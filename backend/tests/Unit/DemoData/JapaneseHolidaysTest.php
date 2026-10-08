<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\JapaneseHolidays;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JapaneseHolidaysTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function knownHolidays(): array
    {
        return [
            '2024 振替休日' => ['2024-02-12', '休日（振替休日）'],
            '2025 敬老の日' => ['2025-09-15', '敬老の日'],
            '2026 春分の日' => ['2026-03-20', '春分の日'],
            '2026 秋分の日' => ['2026-09-23', '秋分の日'],
            '2026 国民の休日' => ['2026-09-22', '国民の休日'],
            '2026 みどりの日の振替' => ['2026-05-06', '休日（振替休日）'],
            '2024 春分の日' => ['2024-03-20', '春分の日'],
            '2025 春分の日' => ['2025-03-20', '春分の日'],
            '2025 秋分の日' => ['2025-09-23', '秋分の日'],
            '2027 春分の日' => ['2027-03-21', '春分の日'],
            '2027 春分の振替' => ['2027-03-22', '休日（振替休日）'],
            '2021 海の日' => ['2021-07-22', '海の日'],
            '2021 スポーツの日' => ['2021-07-23', 'スポーツの日'],
            '2021 山の日' => ['2021-08-08', '山の日'],
            '2021 山の日の振替' => ['2021-08-09', '休日（振替休日）'],
            '2021 成人の日' => ['2021-01-11', '成人の日'],
            '2022 海の日' => ['2022-07-18', '海の日'],
            '2023 スポーツの日' => ['2023-10-09', 'スポーツの日'],
            '2024 天皇誕生日' => ['2024-02-23', '天皇誕生日'],
            '2025 昭和の日' => ['2025-04-29', '昭和の日'],
            '2024 山の日の振替' => ['2024-08-12', '休日（振替休日）'],
        ];
    }

    #[DataProvider('knownHolidays')]
    public function test_known_holidays(string $date, string $name): void
    {
        $this->assertSame($name, (new JapaneseHolidays)->name(new DateTimeImmutable($date)));
    }

    public function test_ordinary_days_are_not_holidays(): void
    {
        $h = new JapaneseHolidays;
        foreach (['2025-09-16', '2026-03-19', '2024-02-13', '2026-05-07', '2025-12-25', '2021-07-19', '2021-08-11', '2021-10-11'] as $date) {
            $this->assertFalse($h->isHoliday(new DateTimeImmutable($date)), $date);
        }
    }

    public function test_every_year_has_sixteen_or_more_holidays(): void
    {
        $h = new JapaneseHolidays;
        foreach (range(2021, 2027) as $year) {
            $this->assertGreaterThanOrEqual(16, count($h->forYear($year)), (string) $year);
        }
    }
}
