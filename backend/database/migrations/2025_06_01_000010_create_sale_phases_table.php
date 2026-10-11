<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

            Schema::create('sale_phases', function (Blueprint $table) {
                $table->id();
                $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
                $table->string('kind');
                $table->string('method')->default('first_come');
                $table->string('eligibility')->nullable();
                $table->timestampTz('starts_at');
                $table->timestampTz('ends_at');
                $table->timestamps();

                $table->unique(['match_id', 'kind']);
            });
            DB::statement("ALTER TABLE sale_phases ADD CONSTRAINT sale_phases_kind_check CHECK (kind IN ('fanclub_presale','general'))");
            DB::statement("ALTER TABLE sale_phases ADD CONSTRAINT sale_phases_eligibility_check CHECK (eligibility IS NULL OR eligibility IN ('season_pass','fanclub'))");
            DB::statement('ALTER TABLE sale_phases ADD CONSTRAINT sale_phases_period_check CHECK (starts_at < ends_at)');
            // 半開区間 [starts_at, ends_at) なので、隣接するフェーズは重ならない。
            DB::statement('ALTER TABLE sale_phases ADD CONSTRAINT sale_phases_no_overlap EXCLUDE USING gist (match_id WITH =, tstzrange(starts_at, ends_at) WITH &&)');

            // 既存の試合へのバックフィル。日数は SalePhaseTemplate と一致させる（テストで担保）。
            DB::statement(<<<'SQL'
                INSERT INTO sale_phases (match_id, kind, method, eligibility, starts_at, ends_at, created_at, updated_at)
                SELECT id, 'fanclub_presale', 'first_come', 'fanclub',
                    kickoff_at - INTERVAL '42 days', kickoff_at - INTERVAL '35 days', NOW(), NOW()
                FROM matches
                SQL);
            DB::statement(<<<'SQL'
                INSERT INTO sale_phases (match_id, kind, method, eligibility, starts_at, ends_at, created_at, updated_at)
                SELECT id, 'general', 'first_come', NULL,
                    kickoff_at - INTERVAL '35 days', kickoff_at, NOW(), NOW()
                FROM matches
                SQL);

            Schema::table('orders', function (Blueprint $table) {
                $table->foreignId('sale_phase_id')->nullable()->constrained('sale_phases')->nullOnDelete();
                $table->string('kind')->default('purchase');
            });
            DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_kind_check CHECK (kind IN ('purchase','season_pass'))");

            Schema::create('season_pass_seats', function (Blueprint $table) {
                $table->id();
                $table->foreignId('season_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->restrictOnDelete();
                $table->foreignId('block_id')->constrained()->cascadeOnDelete();
                $table->foreignId('seat_id')->nullable()->constrained()->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['season_id', 'user_id']);
            });
            DB::statement('CREATE UNIQUE INDEX season_pass_seats_season_id_seat_id_unique ON season_pass_seats (season_id, seat_id) WHERE seat_id IS NOT NULL');

            Schema::table('tickets', function (Blueprint $table) {
                $table->foreignId('season_pass_seat_id')->nullable()->constrained('season_pass_seats')->restrictOnDelete();
            });
            // 年パス席の発行を試合ごとに 1 枚へ絞る（発行の冪等性の鍵）。
            DB::statement('CREATE UNIQUE INDEX tickets_match_id_season_pass_seat_id_unique ON tickets (match_id, season_pass_seat_id) WHERE season_pass_seat_id IS NOT NULL');
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            DB::statement('DROP INDEX tickets_match_id_season_pass_seat_id_unique');
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('season_pass_seat_id');
            });
            Schema::dropIfExists('season_pass_seats');
            DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_kind_check');
            Schema::table('orders', function (Blueprint $table) {
                $table->dropColumn('kind');
                $table->dropConstrainedForeignId('sale_phase_id');
            });
            Schema::dropIfExists('sale_phases');
        });
    }
};
