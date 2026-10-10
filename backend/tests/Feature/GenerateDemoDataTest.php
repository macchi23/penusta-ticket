<?php

namespace Tests\Feature;

use App\Console\Commands\GenerateDemoData;
use App\Support\DemoData\DemandModel;
use App\Support\DemoData\DemoScenario;
use App\Support\DemoData\SeasonPassSeating;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HighDemandModel;
use Tests\TestCase;

class GenerateDemoDataTest extends TestCase
{
    use RefreshDatabase;

    private function generate(int $seasons, float $scale = 0.01, int $seed = 42): void
    {
        $this->artisan('penusta:generate-demo-data', ['--seasons' => $seasons, '--scale' => $scale, '--seed' => $seed])->assertSuccessful();
    }

    /** 生成対象の全テーブルを全列で比べる指紋。除外列は無い（id・uuid・時刻・password を含む） */
    private function fingerprint(): array
    {
        $result = [];
        foreach (['users', 'memberships', 'orders', 'tickets', 'order_items', 'events'] as $table) {
            $result[$table] = DB::selectOne("select count(*) c, md5(coalesce(string_agg(t::text, ',' order by id), '')) h from {$table} t");
        }

        return $result;
    }

    public function test_same_arguments_give_identical_data(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(2);
        $first = $this->fingerprint();
        $this->assertGreaterThan(0, $first['orders']->c);

        $this->generate(2);

        $this->assertEquals($first, $this->fingerprint());
    }

    public function test_seasons_option_does_not_change_the_latest_season(): void
    {
        $this->seed(DatabaseSeeder::class);
        $matchIds = DB::table('matches')->join('seasons', 'seasons.id', '=', 'matches.season_id')
            ->where('seasons.name', '2026-27')->pluck('matches.id')->all();
        $in = implode(',', $matchIds);
        $latest = fn () => [
            DB::selectOne("select count(*) c, md5(string_agg(user_id||'/'||ordered_at::text||'/'||total, ',' order by user_id, ordered_at, total, id)) h from orders where match_id in ($in)"),
            DB::selectOne("select count(*) c, md5(string_agg(coalesce(seat_id,0)||'/'||qr_uuid||'/'||block_id, ',' order by qr_uuid)) h from tickets where match_id in ($in)"),
            DB::selectOne("select count(*) c, md5(string_agg(event_type||'/'||coalesce(user_id,0)||'/'||session_id||'/'||occurred_at::text, ',' order by session_id, occurred_at, event_type)) h from events where (payload->>'match_id')::int in ($in)"),
            DB::selectOne('select count(*) c, md5(string_agg(id||email, \',\' order by id)) h from users'),
        ];

        $this->generate(1);
        $one = $latest();
        $this->assertGreaterThan(0, $one[0]->c);

        $this->generate(2);

        $this->assertEquals($one, $latest());
    }

    public function test_invariants(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(7);
        $asOf = DemoScenario::asOf()->format('Y-m-d H:i:sP');

        $this->assertSame(0, DB::table('orders')->where('ordered_at', '>', $asOf)->count());
        // tickets.created_at は timestamp（タイムゾーン無し）なので UTC の値で比べる（AS_OF = 2026-10-08 00:00 JST）
        $this->assertSame(0, DB::table('tickets')->where('created_at', '>', '2026-10-07 15:00:00')->count());
        $this->assertSame(0, DB::table('events')->where('occurred_at', '>', $asOf)->count());
        $this->assertSame(0, DB::table('memberships')->where('valid_from', '>', substr($asOf, 0, 10))->count());
        $this->assertGreaterThanOrEqual('2021-01-01', substr(DB::table('events')->min('occurred_at'), 0, 10));

        // 売り越し 0（ブロックごと）、指定席の重複なし
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from tickets t join blocks b on b.id = t.block_id group by t.match_id, b.id, b.capacity having count(*) > b.capacity) x')->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from tickets where seat_id is not null group by match_id, seat_id having count(*) > 1) x')->c);
        // 割当外（buffer / closed）には売らない
        $this->assertSame(0, DB::selectOne("select count(*) c from tickets t join match_block_allocations a on a.match_id = t.match_id and a.block_id = t.block_id where a.allocation not in ('home','away')")->c);
        // 注文の合計は明細の合計、買い手は注文より前に入会している
        $this->assertSame(0, DB::selectOne('select count(*) c from orders o where o.total <> (select coalesce(sum(price),0) from order_items i where i.order_id = o.id)')->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from orders o join users u on u.id = o.user_id where o.ordered_at < u.created_at')->c);

        // 会員の作成日時は入会より前にしない。行動ログはキックオフ以前
        $this->assertSame(0, DB::selectOne('select count(*) c from memberships m join users u on u.id = m.user_id where m.created_at < u.created_at')->c);
        $this->assertSame(0, DB::selectOne("select count(*) c from events e join matches m on m.id = (e.payload->>'match_id')::int where e.occurred_at > m.kickoff_at")->c);

        // 年パスの券種は、注文時点で有効な年パス会員の明細だけ。1 ユーザー 1 試合 1 枚まで
        $passTypeId = DB::table('ticket_types')->where('code', 'season_pass')->value('id');
        $this->assertGreaterThan(0, DB::table('order_items')->where('ticket_type_id', $passTypeId)->count());
        $this->assertSame(0, DB::selectOne("select count(*) c from order_items i join orders o on o.id = i.order_id where i.ticket_type_id = ? and not exists (select 1 from memberships m where m.user_id = o.user_id and m.kind = 'season_pass' and m.valid_from <= (o.ordered_at at time zone 'Asia/Tokyo')::date and m.valid_until >= (o.ordered_at at time zone 'Asia/Tokyo')::date)", [$passTypeId])->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from order_items i join orders o on o.id = i.order_id where i.ticket_type_id = ? group by o.user_id, o.match_id having count(*) > 1) x', [$passTypeId])->c);

        // 未販売の試合には何も無い
        $unsold = DB::table('matches')->whereRaw("(attributes->>'unsold') = 'true'")->pluck('id')->all();
        $this->assertNotEmpty($unsold);
        $this->assertSame(0, DB::table('orders')->whereIn('match_id', $unsold)->count());
        // tickets.created_at は timestamp（タイムゾーン無し）なので UTC の値で比べる（AS_OF = 2026-10-08 00:00 JST）
        $this->assertSame(0, DB::table('tickets')->whereIn('match_id', $unsold)->count());
        $this->assertSame(0, DB::table('events')->whereIn(DB::raw("(payload->>'match_id')::int"), $unsold)->count());
    }

    public function test_season_pass_seats_are_fixed_and_never_sold_to_the_general_public(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(3, 0.05);
        $passTypeId = DB::table('ticket_types')->where('code', 'season_pass')->value('id');

        $this->assertGreaterThan(0, DB::selectOne('select count(*) c from order_items where ticket_type_id = ?', [$passTypeId])->c);
        // 引換は 1 ユーザー 1 席、一般販売の券は引換の席に座らない（同じシーズン内）
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from order_items i join tickets t on t.id = i.ticket_id join orders o on o.id = i.order_id join matches m on m.id = t.match_id where i.ticket_type_id = ? and t.seat_id is not null group by m.season_id, t.seat_id having count(distinct o.user_id) > 1) x', [$passTypeId])->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from order_items gi join tickets gt on gt.id = gi.ticket_id join matches gm on gm.id = gt.match_id where gi.ticket_type_id <> ? and gt.seat_id is not null and exists (select 1 from order_items pi join tickets pt on pt.id = pi.ticket_id join matches pm on pm.id = pt.match_id where pi.ticket_type_id = ? and pt.seat_id = gt.seat_id and pm.season_id = gm.season_id)', [$passTypeId, $passTypeId])->c);
        // 引換はアウェー割当のブロックには無い。1 ユーザー 1 シーズンで同じブロック・席に固定される
        $this->assertSame(0, DB::selectOne("select count(*) c from order_items i join tickets t on t.id = i.ticket_id join match_block_allocations a on a.match_id = t.match_id and a.block_id = t.block_id where i.ticket_type_id = ? and a.allocation <> 'home'", [$passTypeId])->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from order_items i join tickets t on t.id = i.ticket_id join orders o on o.id = i.order_id join matches m on m.id = t.match_id where i.ticket_type_id = ? group by m.season_id, o.user_id having count(distinct t.block_id) > 1 or count(distinct t.seat_id) > 1) x', [$passTypeId])->c);
        // 年パスの券種は一般販売の注文に混ざらない（引換の注文は 1 枚だけ）
        $this->assertSame(0, DB::selectOne('select count(*) c from (select i.order_id from order_items i group by i.order_id having bool_or(i.ticket_type_id = ?) and count(*) > 1) x', [$passTypeId])->c);
    }

    public function test_presale_orders_are_fanclub_members_only(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(3, 0.05);

        $presale = "o.ordered_at < m.kickoff_at - interval '35 days'";
        $this->assertGreaterThan(0, DB::selectOne("select count(*) c from orders o join matches m on m.id = o.match_id where $presale")->c);
        $this->assertSame(0, DB::selectOne("select count(*) c from orders o join matches m on m.id = o.match_id where $presale and not exists (select 1 from memberships f where f.user_id = o.user_id and f.kind = 'fanclub' and f.valid_from <= (o.ordered_at at time zone 'Asia/Tokyo')::date and f.valid_until >= (o.ordered_at at time zone 'Asia/Tokyo')::date)")->c);
        // 先行の前（キックオフの 42 日より前）には誰も買えない
        $this->assertSame(0, DB::selectOne("select count(*) c from orders o join matches m on m.id = o.match_id where o.ordered_at < m.kickoff_at - interval '42 days'")->c);
    }

    public function test_bad_weather_cuts_orders_in_the_last_three_days(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(7, 0.05);
        $passTypeId = DB::table('ticket_types')->where('code', 'season_pass')->value('id');

        $share = DB::select("select (m.attributes->>'weather') in ('雨','雪') as bad, avg((o.ordered_at >= m.kickoff_at - interval '3 days')::int) as late
            from orders o join matches m on m.id = o.match_id
            where exists (select 1 from order_items i where i.order_id = o.id and i.ticket_type_id <> ?)
            group by 1", [$passTypeId]);
        $byWeather = collect($share)->mapWithKeys(fn ($r) => [$r->bad ? 'bad' : 'fair' => (float) $r->late]);

        $this->assertCount(2, $byWeather);
        $this->assertLessThan($byWeather['fair'] * 0.9, $byWeather['bad']);
    }

    public function test_sold_out_blocks_stop_at_remaining_seats_and_overflow_becomes_abandoned_sessions(): void
    {
        $this->seed(DatabaseSeeder::class);
        // scale 0.05 を掛けて販売可能席数の 1.2 倍の需要にする
        $this->app->bind(DemandModel::class, fn () => new HighDemandModel(24.0));
        $this->generate(1, 0.05);
        $passTypeId = DB::table('ticket_types')->where('code', 'season_pass')->value('id');
        $holders = DB::table('memberships')->where('kind', 'season_pass')->where('valid_until', '2027-06-30')->count();
        [$goalFixed, $backFixed, $mainFixed] = SeasonPassSeating::split($holders);

        // 売り越し 0・席の重複 0
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from tickets t join blocks b on b.id = t.block_id group by t.match_id, b.id, b.capacity having count(*) > b.capacity) x')->c);
        $this->assertSame(0, DB::selectOne('select count(*) c from (select 1 from tickets where seat_id is not null group by match_id, seat_id having count(*) > 1) x')->c);

        // 一般販売の枚数 + そのシーズンに引換のあったユーザー数（固定席の下限）は capacity 以下。固定枠まで一般販売していれば超える
        $over = DB::selectOne('select count(*) c from (
            select t.match_id, t.block_id, b.capacity,
              count(*) filter (where i.ticket_type_id <> ?) as general
            from tickets t join order_items i on i.ticket_id = t.id join blocks b on b.id = t.block_id
            group by t.match_id, t.block_id, b.capacity) g
          join (
            select t.block_id, count(distinct o.user_id) as fixed
            from tickets t join order_items i on i.ticket_id = t.id join orders o on o.id = i.order_id
            where i.ticket_type_id = ? group by t.block_id) f on f.block_id = g.block_id
          where g.general + f.fixed > g.capacity', [$passTypeId, $passTypeId])->c;
        $this->assertSame(0, $over);

        // 完売している: home のブロックの一般販売が、capacity − 固定席の上限（席種ごとの固定席数）以上に達している
        $maxFixed = max($goalFixed, $backFixed, $mainFixed);
        $soldOut = DB::selectOne("select count(*) c from (
            select t.match_id, t.block_id, b.capacity, count(*) as general
            from tickets t join order_items i on i.ticket_id = t.id join blocks b on b.id = t.block_id
            join match_block_allocations a on a.match_id = t.match_id and a.block_id = t.block_id
            where i.ticket_type_id <> ? and a.allocation = 'home' group by t.match_id, t.block_id, b.capacity) g
          where g.general >= g.capacity - ?", [$passTypeId, $maxFixed])->c;
        $this->assertGreaterThan(0, $soldOut);

        // 完売ブロックの座席表で離脱したセッション: block_code 付きの view_seatmap があり、購入が無い
        $abandoned = DB::selectOne("select count(*) c from (
            select session_id from events group by session_id
            having bool_or(event_type = 'view_seatmap' and payload->>'block_code' is not null) and not bool_or(event_type = 'purchase')) x")->c;
        $this->assertGreaterThan(0, $abandoned);
    }

    public function test_standing_before_a_match_is_the_previous_rounds_row(): void
    {
        $this->seed(DatabaseSeeder::class);
        $command = $this->app->make(GenerateDemoData::class);
        $checked = 0;

        foreach ($command->loadSpans() as $span) {
            $standings = $command->loadStandings($span);
            $matches = DB::table('matches')->where('season_id', $span->seasonId)->get();
            foreach ($matches as $match) {
                $round = (int) json_decode($match->attributes, true)['round'];
                $standing = $command->standingBefore($standings, $round);
                if ($round === 1) {
                    $this->assertNull($standing);

                    continue;
                }
                $row = DB::table('league_standings')->where('season_id', $span->seasonId)->where('round', $round - 1)->where('team_id', DemoScenario::HOME_CLUB_ID)->first();
                if ($row === null) {
                    continue;
                }
                $leader = DB::table('league_standings')->where('season_id', $span->seasonId)->where('round', $round - 1)->where('position', 1)->value('points');
                $this->assertNotNull($standing);
                $this->assertSame([$row->position, $row->points, $row->form, $leader], [$standing->rank, $standing->points, $standing->form, $standing->leaderPoints]);
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked);
    }

    public function test_season_pass_seats_are_only_in_blocks_that_are_home_in_every_match(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(2, 0.05);
        $passTypeId = DB::table('ticket_types')->where('code', 'season_pass')->value('id');

        $this->assertGreaterThan(0, DB::selectOne('select count(*) c from order_items where ticket_type_id = ?', [$passTypeId])->c);
        $this->assertSame(0, DB::selectOne("select count(*) c from order_items i join tickets t on t.id = i.ticket_id
            where i.ticket_type_id = ? and exists (select 1 from match_block_allocations a where a.block_id = t.block_id and a.allocation <> 'home')", [$passTypeId])->c);
    }

    public function test_keeps_test_user_and_admin_with_stable_ids(): void
    {
        $this->seed(DatabaseSeeder::class);
        $kept = DB::table('users')->orderBy('id')->pluck('email', 'id')->all();

        $this->generate(1);

        foreach ($kept as $id => $email) {
            $this->assertSame($email, DB::table('users')->where('id', $id)->value('email'));
        }
        $this->assertSame(max(array_keys($kept)) + 1, (int) DB::table('users')->where('email', 'user1@example.com')->value('id'));
    }

    public function test_demo_user_lists_only_memberships_valid_at_as_of(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->generate(1);
        $userId = DB::table('memberships')->where('valid_until', '<', '2026-01-01')->value('user_id');
        $this->assertNotNull($userId);
        $valid = DB::table('memberships')->where('user_id', $userId)->where('valid_from', '<=', '2026-10-08')->where('valid_until', '>=', '2026-10-08')->pluck('kind')->unique()->sort()->values()->all();

        $res = $this->getJson('/api/demo/users?limit=200&q=')->assertOk();
        $row = collect($res->json('data'))->firstWhere('id', $userId);
        if ($row !== null) {
            $this->assertSame($valid, collect($row['memberships'])->sort()->values()->all());
        }
        foreach ($res->json('data') as $u) {
            $this->assertSame(count($u['memberships']), count(array_unique($u['memberships'])));
        }
    }
}
