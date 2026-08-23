<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->cascadeOnDelete();
            $table->foreignId('block_id')->constrained()->cascadeOnDelete();
            $table->foreignId('seat_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->uuid('qr_uuid')->unique();
            $table->timestampTz('expires_at')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'block_id']);
            $table->index('status');
        });

        // 指定席は座席の重複割当を禁止する（自由席は seat_id が null のため対象外）。
        DB::statement(
            'CREATE UNIQUE INDEX tickets_match_id_seat_id_unique ON tickets (match_id, seat_id) WHERE seat_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
