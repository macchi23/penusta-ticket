<?php

namespace App\Support\DemoData;

use Closure;

/** 合成ユーザーを作る。users 行に要る文字列の項目は onUser に渡し、メモリには溜めない */
final class DemoPopulationGenerator
{
    public const BASE_COUNT = 90_000;

    /** 入会年の配分（%）。0 は 2020 以前 */
    private const JOIN_WEIGHTS = [0 => 40, 2021 => 10, 2022 => 8, 2023 => 7, 2024 => 8, 2025 => 14, 2026 => 13];

    private const PRE_2021_FROM_YEAR = 2014;

    /** 年あたりの退会確率（2021〜2025 年末。2026 は退会させない） */
    private const ANNUAL_LEAVE_RATE = 0.06;

    private const LAST_LEAVE_YEAR = 2025;

    private const SURNAMES = ['佐藤', '鈴木', '高橋', '田中', '伊藤', '渡辺', '山本', '中村', '小林', '加藤',
        '吉田', '山田', '佐々木', '山口', '松本', '井上', '木村', '林', '斎藤', '清水',
        '森', '池田', '橋本', '阿部', '石川', '前田', '藤田', '後藤', '岡田', '長谷川'];

    private const MALE_GIVEN = ['翔太', '大輔', '健太', '直樹', '拓也', '亮', '雄大', '和也', '智也', '賢一',
        '陽太', '海斗', '大和', '悠斗', '蓮', '颯太', '洋平', '啓太', '真也', '駿'];

    private const FEMALE_GIVEN = ['美咲', '愛', '結衣', '陽菜', 'さくら', '真央', '花', '彩', '千夏', '由美',
        '智子', '真由美', '恵', '美穂', '香織', '瞳', '奈々', '麻衣', '莉子', '葵'];

    public function __construct(private readonly int $seed) {}

    public static function countFor(float $scale): int
    {
        return max(1, (int) round(self::BASE_COUNT * $scale));
    }

    /**
     * @param  Closure(int, array{name:string, email:string, birth_date:string, gender:string, prefecture:string, attributes:string, joined_at:int}): void  $onUser
     */
    public function generate(int $count, Closure $onUser): DemoPopulation
    {
        $rng = new SeededRandom($this->seed, 'users');
        $population = new DemoPopulation($count);
        $prefectures = self::prefectureWeights();
        $channels = ['web' => 45, 'sns' => 25, 'app' => 20, 'friend_invite' => 7, 'other' => 3];
        $asOf = DemoScenario::asOf()->getTimestamp();

        for ($i = 0; $i < $count; $i++) {
            $gender = $rng->weighted(['male' => 55, 'female' => 45]);
            $age = $this->pickAge($rng);
            $birth = (int) gmmktime(0, 0, 0, 1, 1, 2026 - $age) - $rng->int(0, 364) * 86400;
            $surname = $rng->pick(self::SURNAMES);
            $given = $gender === 'male' ? $rng->pick(self::MALE_GIVEN) : $rng->pick(self::FEMALE_GIVEN);
            $prefecture = $rng->weighted($prefectures);
            $attributes = json_encode([
                'acquisition_channel' => $rng->weighted($channels),
                'newsletter_opt_in' => $rng->chance(0.35),
            ]);
            $joinedAt = $this->pickJoinedAt($rng, $asOf);
            $leaveYear = $this->pickLeaveYear($rng, (int) gmdate('Y', $joinedAt));
            $passion = exp($rng->normal(0.0, 1.0));

            $population->joinedAt[] = $joinedAt;
            $population->leaveYear[] = $leaveYear;
            $population->age[] = $age;
            $population->male[] = $gender === 'male';
            $population->passion[] = $passion;

            $onUser($i, [
                'name' => $surname.' '.$given,
                'email' => 'user'.($i + 1).'@example.com',
                'birth_date' => gmdate('Y-m-d', $birth),
                'gender' => $gender,
                'prefecture' => $prefecture,
                'attributes' => $attributes,
                'joined_at' => $joinedAt,
            ]);
        }

        return $population;
    }

    private function pickJoinedAt(SeededRandom $rng, int $asOf): int
    {
        $bucket = (int) $rng->weighted(self::JOIN_WEIGHTS);

        if ($bucket === 0) {
            $from = (int) gmmktime(0, 0, 0, 1, 1, self::PRE_2021_FROM_YEAR);
            $until = (int) gmmktime(0, 0, 0, 1, 1, 2021);
        } else {
            $from = (int) gmmktime(0, 0, 0, 1, 1, $bucket);
            $until = $bucket === 2026 ? $asOf : (int) gmmktime(0, 0, 0, 1, 1, $bucket + 1);
        }

        return $rng->int($from, $until - 1);
    }

    private function pickLeaveYear(SeededRandom $rng, int $joinYear): int
    {
        $uniform = 1.0 - $rng->float();
        $year = max($joinYear, 2021) + (int) floor(log($uniform) / log(1.0 - self::ANNUAL_LEAVE_RATE));

        return $year <= self::LAST_LEAVE_YEAR ? $year : 9999;
    }

    private function pickAge(SeededRandom $rng): int
    {
        $bracket = $rng->weighted([
            '18-24' => 10,
            '25-34' => 22,
            '35-44' => 24,
            '45-54' => 20,
            '55-64' => 14,
            '65-75' => 10,
        ]);

        [$min, $max] = array_map('intval', explode('-', $bracket));

        return $rng->int($min, $max);
    }

    /** @return array<string, int> */
    private static function prefectureWeights(): array
    {
        $others = [
            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
            '静岡県', '愛知県', '三重県', '鳥取県', '島根県', '岡山県', '広島県',
            '山口県', '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県',
            '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
        ];

        $weights = [
            '大阪府' => 25,
            '兵庫県' => 12,
            '京都府' => 8,
            '奈良県' => 6,
            '和歌山県' => 4,
            '滋賀県' => 4,
        ];

        foreach ($others as $prefecture) {
            $weights[$prefecture] = 1;
        }

        return $weights;
    }
}
