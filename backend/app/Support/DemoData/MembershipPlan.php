<?php

namespace App\Support\DemoData;

/** 会員の計画。シーズン × 種別ごとに、ユーザー添字 => valid_from（Y-m-d）を持つ */
final class MembershipPlan
{
    public const SEASON_PASS = 'season_pass';

    public const FANCLUB = 'fanclub';

    public const KINDS = [self::SEASON_PASS, self::FANCLUB];

    /** @var array<string, array<int, array<int, string>>> */
    private array $holders = [];

    /** @var array<string, array<int, array{previous:int, renewed:int, added:int}>> */
    private array $stats = [];

    /** valid_from（JST の日付）の 0 時の epoch */
    public static function validFromEpoch(string $date): int
    {
        return (int) strtotime($date.' 00:00:00 +09:00');
    }

    /** @param  array<int, string>  $holders  ユーザー添字 => valid_from */
    public function set(string $kind, int $season, array $holders): void
    {
        ksort($holders);
        $this->holders[$kind][$season] = $holders;
    }

    /** @return array<int, string> ユーザー添字 => valid_from */
    public function holders(string $kind, int $season): array
    {
        return $this->holders[$kind][$season] ?? [];
    }

    public function has(string $kind, int $season, int $userIndex): bool
    {
        return isset($this->holders[$kind][$season][$userIndex]);
    }

    public function count(string $kind, int $season): int
    {
        return count($this->holders[$kind][$season] ?? []);
    }

    public function setStats(string $kind, int $season, int $previous, int $renewed, int $added): void
    {
        $this->stats[$kind][$season] = ['previous' => $previous, 'renewed' => $renewed, 'added' => $added];
    }

    /** @return array{previous:int, renewed:int, added:int} */
    public function stats(string $kind, int $season): array
    {
        return $this->stats[$kind][$season] ?? ['previous' => 0, 'renewed' => 0, 'added' => 0];
    }
}
