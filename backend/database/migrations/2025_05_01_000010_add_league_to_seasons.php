<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEAKAGE_COMMENT = '第 r 節の試合の特徴量には r-1 節後の順位表を使う（リーク防止）。round 節を終えた時点の順位表を持つので、同じ節の行を使うと結果が漏れる';

    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->string('format')->default('spring_autumn');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
        });

        Schema::create('league_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->timestampTz('kickoff_at');
            $table->foreignId('home_team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('away_team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('home_score')->nullable();
            $table->unsignedSmallInteger('away_score')->nullable();
            $table->foreignId('match_id')->nullable()->unique()->constrained('matches')->nullOnDelete();
            $table->timestamps();

            $table->unique(['season_id', 'round', 'home_team_id']);
            $table->index('kickoff_at');
        });

        Schema::create('league_standings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('round');
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('played');
            $table->unsignedSmallInteger('won');
            $table->unsignedSmallInteger('drawn');
            $table->unsignedSmallInteger('lost');
            $table->smallInteger('goals_for');
            $table->smallInteger('goals_against');
            $table->smallInteger('points');
            $table->string('form', 5)->default('');
            $table->timestamps();

            $table->unique(['season_id', 'round', 'team_id']);
        });

        DB::statement("COMMENT ON TABLE league_matches IS '".self::LEAKAGE_COMMENT."'");
        DB::statement("COMMENT ON TABLE league_standings IS '".self::LEAKAGE_COMMENT."'");
        DB::statement("COMMENT ON COLUMN league_standings.form IS '直近 5 試合の結果（古い順の W / D / L）'");
    }

    public function down(): void
    {
        Schema::dropIfExists('league_standings');
        Schema::dropIfExists('league_matches');
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn(['format', 'starts_on', 'ends_on']);
        });
    }
};
