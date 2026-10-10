<?php

namespace Tests\Feature;

use App\Support\DemoData\DemoScenario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
