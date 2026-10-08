<?php

namespace App\Support\DemoData;

use DateTimeImmutable;
use DateTimeZone;

/** 合成データのシナリオ定数 */
final class DemoScenario
{
    public const HOME_CLUB_ID = 1;

    public const HOME_CLUB_NAME = 'ペヌスタFC';

    public const DEFAULT_SEED = 20261008;

    public const TIMEZONE = 'Asia/Tokyo';

    /** 「今」。これより後にキックオフする試合は結果を持たない。変えると 2026-27 の結果は作り直される（2025 以前と 2026 ハーフは不変） */
    public const AS_OF_JST = '2026-10-08 00:00:00';

    public const RELEGATION_SLOTS = 3;

    /** 春秋制 2021 の時点で下部リーグにいるチーム数 */
    public const LOWER_LEAGUE_SIZE = 3;

    /** 人気度がこの値以上のチームは降格させない */
    public const NO_RELEGATION_POPULARITY = 4;

    public static function asOf(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AS_OF_JST, new DateTimeZone(self::TIMEZONE));
    }

    /**
     * ペヌスタ（id 1）+ 相手プール 22 チーム（id 2〜23）。id は TeamSeeder の投入順（2〜10）に合わせる
     *
     * @return array<int, DemoTeam>
     */
    public static function teams(): array
    {
        $rows = [
            // [name, short, color, popularity, attack, defense]
            ['潮見グランディーズ', '潮見', '#dc2626', 5, 1.25, 0.82],
            ['弦巻ユナイテッド', '弦巻', '#f59e0b', 4, 1.12, 0.92],
            ['緑ヶ丘アスレチカ', '緑ヶ丘', '#16a34a', 4, 1.10, 0.95],
            ['朱雀セレステ', '朱雀', '#ea580c', 3, 1.02, 1.00],
            ['白鷺ヴィクトリア', '白鷺', '#0ea5e9', 3, 0.98, 0.98],
            ['桜庭コメッツ', '桜庭', '#db2777', 3, 1.05, 1.04],
            ['鷹羽フェニックス', '鷹羽', '#7c3aed', 4, 1.15, 0.90],
            ['津々浦フリート', '津々浦', '#0d9488', 3, 0.97, 1.02],
            ['金剛レオネス', '金剛', '#ca8a04', 5, 1.20, 0.85],
            ['蒼海トリトンズ', '蒼海', '#1e40af', 4, 1.08, 0.94],
            ['霧島ファルコンズ', '霧島', '#64748b', 3, 1.00, 0.97],
            ['黒潮ワイルドキャッツ', '黒潮', '#111827', 3, 0.99, 1.03],
            ['紫雲ペガサス', '紫雲', '#a855f7', 3, 1.03, 1.01],
            ['瑞穂ライトニング', '瑞穂', '#84cc16', 2, 0.93, 1.05],
            ['灘波ドルフィンズ', '灘波', '#06b6d4', 2, 0.95, 1.08],
            ['嵐山ヴァリアント', '嵐山', '#be123c', 2, 0.90, 1.04],
            ['北斗スターズ', '北斗', '#fbbf24', 2, 0.94, 1.06],
            ['若葉シルフィード', '若葉', '#4ade80', 2, 0.92, 1.07],
            ['茜野ドラグーン', '茜野', '#b45309', 1, 0.86, 1.12],
            ['氷室ノーザンズ', '氷室', '#38bdf8', 1, 0.84, 1.14],
            ['常盤アルバトロス', '常盤', '#065f46', 1, 0.88, 1.13],
            ['天城ブレイカーズ', '天城', '#9a3412', 1, 0.85, 1.15],
        ];

        $teams = [
            self::HOME_CLUB_ID => new DemoTeam(self::HOME_CLUB_ID, self::HOME_CLUB_NAME, 'ペヌスタ', '#2563eb', 3, 1.0, 1.0, true),
        ];
        foreach ($rows as $i => [$name, $short, $color, $popularity, $attack, $defense]) {
            $id = $i + 2;
            $teams[$id] = new DemoTeam($id, $name, $short, $color, $popularity, $attack, $defense);
        }

        return $teams;
    }

    /**
     * 春秋制 2021 → 2025、ハーフ 2026、秋春制 2026-27。
     * ペヌスタの強さの調整値（末尾 2 つの float）は、棄却サンプリングが速く収まるよう置いた定数
     *
     * @return list<SeasonDefinition>
     */
    public static function seasons(): array
    {
        return [
            new SeasonDefinition('2021', SeasonFormat::SpringAutumn, 2021, 20, 15, 17, 0.92, 1.08, lowRankFrom: ['round' => 25, 'rank' => 16]),
            new SeasonDefinition('2022', SeasonFormat::SpringAutumn, 2022, 20, 8, 12, 1.00, 1.00),
            new SeasonDefinition('2023', SeasonFormat::SpringAutumn, 2023, 20, 8, 12, 1.00, 1.00),
            new SeasonDefinition('2024', SeasonFormat::SpringAutumn, 2024, 20, 8, 12, 1.00, 1.00),
            new SeasonDefinition('2025', SeasonFormat::SpringAutumn, 2025, 20, 2, 2, 1.20, 0.85, leaderFromRound: 30, maxGapToChampion: 3),
            new SeasonDefinition('2026', SeasonFormat::Half, 2026, 10, 5, 5, 1.00, 1.00),
            new SeasonDefinition('2026-27', SeasonFormat::AutumnSpring, 2026, 20, 8, 11, 1.00, 1.00),
        ];
    }
}
