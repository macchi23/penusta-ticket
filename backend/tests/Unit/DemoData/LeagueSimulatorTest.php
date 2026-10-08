<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\Fixture;
use App\Support\DemoData\LeagueSimulator;
use App\Support\DemoData\PlayedMatch;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class LeagueSimulatorTest extends TestCase
{
    private function match(int $round, int $home, int $away, ?int $hg, ?int $ag): PlayedMatch
    {
        $kickoff = new DateTimeImmutable('2025-03-01 05:00:00 UTC')->modify('+'.($round * 7).' days');

        return new PlayedMatch(new Fixture($round, $kickoff, $home, $away), $hg, $ag);
    }

    public function test_table_matches_hand_calculation(): void
    {
        $matches = [
            $this->match(1, 1, 2, 2, 1),
            $this->match(2, 2, 3, 0, 0),
            $this->match(3, 3, 1, 1, 3),
            $this->match(4, 1, 3, null, null),
        ];

        $table = (new LeagueSimulator)->table($matches, [1, 2, 3]);

        $this->assertSame([1, 2, 3], array_map(fn ($r) => $r->teamId, $table));
        [$a, $b, $c] = $table;
        $this->assertSame([1, 2, 0, 0, 5, 2, 6, 3, 'WW'], [$a->rank, $a->played, $a->drawn, $a->lost, $a->goalsFor, $a->goalsAgainst, $a->points, $a->goalDifference(), $a->form]);
        $this->assertSame([2, 2, 1, 1, 1, 2, 1, -1, 'LD'], [$b->rank, $b->played, $b->drawn, $b->lost, $b->goalsFor, $b->goalsAgainst, $b->points, $b->goalDifference(), $b->form]);
        $this->assertSame([3, 2, 1, 1, 1, 3, 1, -2, 'DL'], [$c->rank, $c->played, $c->drawn, $c->lost, $c->goalsFor, $c->goalsAgainst, $c->points, $c->goalDifference(), $c->form]);
        $this->assertSame(2, $a->won);
    }

    public function test_tiebreak_order_is_points_goal_difference_goals_for_then_team_id(): void
    {
        // 全チーム 1 勝 1 敗・同じスコア: 勝点・得失点差・総得点がすべて同じなのでチーム ID 順
        $matches = [
            $this->match(1, 3, 2, 1, 0),
            $this->match(2, 2, 1, 1, 0),
            $this->match(3, 1, 3, 1, 0),
        ];
        $table = (new LeagueSimulator)->table($matches, [1, 2, 3]);

        $this->assertSame([3, 3, 3], array_map(fn ($r) => $r->points, $table));
        $this->assertSame([0, 0, 0], array_map(fn ($r) => $r->goalDifference(), $table));
        $this->assertSame([1, 2, 3], array_map(fn ($r) => $r->teamId, $table));

        $byGoalDifference = (new LeagueSimulator)->table([
            $this->match(1, 1, 2, 1, 0),
            $this->match(2, 3, 4, 3, 0),
        ], [1, 2, 3, 4]);
        $this->assertSame([3, 1, 2, 4], array_map(fn ($r) => $r->teamId, $byGoalDifference));

        $byGoalsFor = (new LeagueSimulator)->table([
            $this->match(1, 1, 2, 1, 0),
            $this->match(2, 3, 4, 2, 1),
        ], [1, 2, 3, 4]);
        // 勝点 3・得失点差 +1 が 1 と 3、-1 が 2 と 4。総得点の多い 3 と 4 が、ID の小さい 1 と 2 より上
        $this->assertSame([3, 1, 4, 2], array_map(fn ($r) => $r->teamId, $byGoalsFor));
    }

    public function test_round_tables_and_form_keep_last_five(): void
    {
        $matches = [];
        for ($r = 1; $r <= 7; $r++) {
            $matches[] = $this->match($r, 1, 2, $r % 2, 0); // 奇数節は 1 勝、偶数節は引き分け
        }
        $tables = (new LeagueSimulator)->roundTables($matches, [1, 2]);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], array_keys($tables));
        $this->assertSame(3, $tables[1][0]->points);
        $this->assertSame(4, $tables[2][0]->points);
        $this->assertSame('WDWDW', $tables[7][0]->form);
        $this->assertSame(7, $tables[7][0]->played);
        $this->assertSame('LDLDL', $tables[7][1]->form);
    }
}
