<?php

namespace Database\Seeders;

use App\Models\Team;
use App\Support\DemoData\DemoScenario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TeamSeeder extends Seeder
{
    /** ペヌスタ（id 1）+ 相手プール 22 チーム。id は DemoScenario と一致させる */
    public function run(): void
    {
        foreach (DemoScenario::teams() as $team) {
            Team::create([
                'id' => $team->id,
                'name' => $team->name,
                'short_name' => $team->shortName,
                'color' => $team->color,
                'is_home_club' => $team->isHomeClub,
            ]);
        }

        // id を明示したのでシーケンスを進めておく（後続の create が衝突しない）
        DB::statement("SELECT setval(pg_get_serial_sequence('teams', 'id'), (SELECT max(id) FROM teams))");
    }
}
