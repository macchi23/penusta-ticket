<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        Team::create([
            'name' => 'ペヌスタFC',
            'short_name' => 'ペヌスタ',
            'color' => '#2563eb',
            'is_home_club' => true,
        ]);

        $opponents = [
            ['name' => '潮見グランディーズ', 'short_name' => '潮見', 'color' => '#dc2626'],
            ['name' => '弦巻ユナイテッド', 'short_name' => '弦巻', 'color' => '#f59e0b'],
            ['name' => '緑ヶ丘アスレチカ', 'short_name' => '緑ヶ丘', 'color' => '#16a34a'],
            ['name' => '朱雀セレステ', 'short_name' => '朱雀', 'color' => '#ea580c'],
            ['name' => '白鷺ヴィクトリア', 'short_name' => '白鷺', 'color' => '#0ea5e9'],
            ['name' => '桜庭コメッツ', 'short_name' => '桜庭', 'color' => '#db2777'],
            ['name' => '鷹羽フェニックス', 'short_name' => '鷹羽', 'color' => '#7c3aed'],
            ['name' => '津々浦フリート', 'short_name' => '津々浦', 'color' => '#0d9488'],
            ['name' => '金剛レオネス', 'short_name' => '金剛', 'color' => '#ca8a04'],
        ];

        foreach ($opponents as $opponent) {
            Team::create([
                'name' => $opponent['name'],
                'short_name' => $opponent['short_name'],
                'color' => $opponent['color'],
                'is_home_club' => false,
            ]);
        }
    }
}
