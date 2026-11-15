<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\Concerns\BuildsSaleFixture;
use Tests\TestCase;

class ExportTablesTest extends TestCase
{
    use BuildsSaleFixture;
    use RefreshDatabase;

    private const TABLES = [
        'seasons', 'teams', 'matches', 'league_standings', 'league_matches', 'stands', 'seat_categories',
        'blocks', 'match_block_allocations', 'ticket_types', 'match_prices', 'sale_phases', 'orders',
        'order_items', 'tickets',
    ];

    private const COLUMNS = [
        'seasons' => ['id', 'name', 'year', 'format', 'starts_on', 'ends_on'],
        'teams' => ['id', 'name', 'short_name', 'color', 'is_home_club'],
        'matches' => ['id', 'season_id', 'home_team_id', 'away_team_id', 'stadium_id', 'kickoff_at', 'status', 'section_label', 'attributes'],
        'league_standings' => ['id', 'season_id', 'round', 'team_id', 'position', 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'points', 'form'],
        'league_matches' => ['id', 'season_id', 'round', 'kickoff_at', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'match_id'],
        'stands' => ['id', 'stadium_id', 'name', 'code'],
        'seat_categories' => ['id', 'stadium_id', 'name', 'display_order'],
        'blocks' => ['id', 'stand_id', 'seat_category_id', 'code', 'seating_type', 'capacity'],
        'match_block_allocations' => ['id', 'match_id', 'block_id', 'allocation'],
        'ticket_types' => ['id', 'name', 'code', 'display_order'],
        'match_prices' => ['id', 'match_id', 'seat_category_id', 'ticket_type_id', 'price'],
        'sale_phases' => ['id', 'match_id', 'kind', 'method', 'eligibility', 'starts_at', 'ends_at'],
        'orders' => ['id', 'user_id', 'match_id', 'status', 'total', 'channel', 'ordered_at', 'expires_at', 'sale_phase_id', 'kind', 'created_at', 'updated_at'],
        'order_items' => ['id', 'order_id', 'ticket_id', 'ticket_type_id', 'price'],
        'tickets' => ['id', 'match_id', 'block_id', 'seat_id', 'status', 'expires_at', 'season_pass_seat_id', 'created_at', 'updated_at'],
    ];

    private $match;

    private $adult;

    private $alice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
        $this->buildSaleFixture();
        $this->buildSalePricesAndUser();
        $block = $this->makeBlock('M1', 'free', 10);

        DB::table('matches')->where('id', $this->match->id)
            ->update(['attributes' => json_encode(['weather' => 'rain'])]);

        $ticketId = DB::table('tickets')->insertGetId([
            'match_id' => $this->match->id, 'block_id' => $block->id, 'status' => 'paid',
            'qr_uuid' => (string) Str::uuid(), 'created_at' => '2026-08-23 01:02:03', 'updated_at' => '2026-08-23 01:02:03',
        ]);
        $orderId = DB::table('orders')->insertGetId([
            'user_id' => $this->alice->id, 'match_id' => $this->match->id, 'status' => 'paid', 'total' => 5000,
            'channel' => 'web', 'ordered_at' => '2026-08-23T10:00:00+09:00', 'created_at' => '2026-08-23 01:02:03',
            'updated_at' => '2026-08-23 01:02:03',
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'ticket_id' => $ticketId, 'ticket_type_id' => $this->adult->id, 'price' => 5000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $teams = DB::table('teams')->pluck('id');
        $seasonId = $this->match->season_id;
        DB::table('league_matches')->insert([
            'season_id' => $seasonId, 'round' => 1, 'kickoff_at' => '2026-03-01T10:00:00Z',
            'home_team_id' => $teams[0], 'away_team_id' => $teams[1], 'home_score' => 2, 'away_score' => 1,
            'match_id' => $this->match->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('league_standings')->insert([
            'season_id' => $seasonId, 'round' => 1, 'team_id' => $teams[0], 'position' => 1, 'played' => 1,
            'won' => 1, 'drawn' => 0, 'lost' => 0, 'goals_for' => 2, 'goals_against' => 1, 'points' => 3,
            'form' => 'W', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function key(string $table): string
    {
        return "raw/{$table}/part-0000.jsonl.gz";
    }

    private function readLines(string $key): array
    {
        $lines = array_filter(explode("\n", gzdecode(Storage::disk('s3')->get($key))));

        return array_map(fn ($l) => json_decode($l), array_values($lines));
    }

    private function failingDisk(string $method, callable $configure): MockInterface
    {
        $mock = \Mockery::mock(Storage::disk('s3'))->makePartial();
        $configure($mock->shouldReceive($method));
        Storage::set('s3', $mock);

        return $mock;
    }

    public function test_every_table_exports_exactly_the_whitelisted_columns(): void
    {
        $this->artisan('tables:export')->assertSuccessful();

        $keys = array_map(fn ($t) => $this->key($t), self::TABLES);
        $this->assertEqualsCanonicalizing($keys, Storage::disk('s3')->allFiles('raw'));
        foreach (self::TABLES as $table) {
            $rows = $this->readLines($this->key($table));
            $this->assertNotEmpty($rows, $table);
            $this->assertEqualsCanonicalizing(self::COLUMNS[$table], array_keys((array) $rows[0]), $table);
        }
    }

    public function test_sensitive_columns_and_other_tables_are_not_exported(): void
    {
        $this->artisan('tables:export')->assertSuccessful();

        $this->assertStringNotContainsString('qr_uuid', gzdecode(Storage::disk('s3')->get($this->key('tickets'))));
        $this->assertStringNotContainsString('geometry', gzdecode(Storage::disk('s3')->get($this->key('blocks'))));
        foreach (['users', 'memberships', 'season_pass_seats', 'resale_listings', 'events'] as $table) {
            $this->assertSame([], Storage::disk('s3')->allFiles("raw/{$table}"));
        }
    }

    public function test_value_formats(): void
    {
        $this->artisan('tables:export')->assertSuccessful();

        $match = $this->readLines($this->key('matches'))[0];
        $this->assertEquals((object) ['weather' => 'rain'], $match->attributes);
        $this->assertSame('2030-03-01T10:00:00Z', $match->kickoff_at);
        $this->assertSame($this->match->id, $match->id);

        $order = $this->readLines($this->key('orders'))[0];
        $this->assertSame('2026-08-23T01:00:00Z', $order->ordered_at);
        $this->assertSame('2026-08-23T01:02:03Z', $order->created_at);
        $this->assertNull($order->expires_at);
        $this->assertSame(5000, $order->total);

        $this->assertSame(true, $this->readLines($this->key('teams'))[0]->is_home_club);
        $this->assertNull($this->readLines($this->key('tickets'))[0]->seat_id);
    }

    public function test_empty_jsonb_is_an_object(): void
    {
        $this->artisan('tables:export', ['--table' => ['matches']])->assertSuccessful();
        DB::table('matches')->update(['attributes' => '{}']);
        $this->artisan('tables:export', ['--table' => ['matches']])->assertSuccessful();

        $this->assertStringContainsString('"attributes":{}', gzdecode(Storage::disk('s3')->get($this->key('matches'))));
    }

    public function test_date_columns_are_ymd(): void
    {
        DB::table('seasons')->update(['starts_on' => '2026-03-01', 'ends_on' => '2026-12-06']);
        $this->artisan('tables:export', ['--table' => ['seasons']])->assertSuccessful();

        $row = $this->readLines($this->key('seasons'))[0];
        $this->assertSame('2026-03-01', $row->starts_on);
        $this->assertSame('2026-12-06', $row->ends_on);
    }

    public function test_rerun_is_idempotent_and_stale_keys_are_removed(): void
    {
        $this->artisan('tables:export', ['--table' => ['orders']])->assertSuccessful();
        $body1 = gzdecode(Storage::disk('s3')->get($this->key('orders')));
        Storage::disk('s3')->put('raw/orders/part-0001.jsonl.gz', 'x');

        $this->artisan('tables:export', ['--table' => ['orders']])
            ->expectsOutputToContain('deleted=1')
            ->assertSuccessful();

        $this->assertSame([$this->key('orders')], Storage::disk('s3')->allFiles('raw/orders'));
        $this->assertSame($body1, gzdecode(Storage::disk('s3')->get($this->key('orders'))));
    }

    public function test_empty_table_writes_an_empty_gzip_and_removes_stale_keys(): void
    {
        DB::table('order_items')->delete();
        Storage::disk('s3')->put($this->key('order_items'), 'x');
        Storage::disk('s3')->put('raw/order_items/part-0001.jsonl.gz', 'x');

        $this->artisan('tables:export', ['--table' => ['order_items']])
            ->expectsOutput('table=order_items rows=0 key=raw/order_items/part-0000.jsonl.gz deleted=1')
            ->assertSuccessful();

        $this->assertSame([$this->key('order_items')], Storage::disk('s3')->allFiles('raw/order_items'));
        $this->assertSame('', gzdecode(Storage::disk('s3')->get($this->key('order_items'))));
    }

    public function test_read_failure_keeps_that_table_and_continues(): void
    {
        Storage::disk('s3')->put($this->key('orders'), 'old');
        Storage::disk('s3')->put('raw/orders/part-0001.jsonl.gz', 'stale');
        // 本物の SQL エラーを起こす（PG はトランザクション内の失敗で後続が止まるので、表ごとのセーブポイントの検証になる）
        DB::statement('ALTER TABLE orders RENAME COLUMN channel TO channel_x');

        $this->artisan('tables:export')
            ->expectsOutputToContain('table=orders')
            ->assertFailed();

        $disk = Storage::disk('s3');
        $this->assertSame('old', $disk->get($this->key('orders')));
        $this->assertTrue($disk->exists('raw/orders/part-0001.jsonl.gz'));
        $this->assertNotEmpty($this->readLines($this->key('order_items')));
        $this->assertNotEmpty($this->readLines($this->key('tickets')));
    }

    public function test_matches_attributes_drops_object_and_array_values_of_whitelisted_keys(): void
    {
        DB::table('matches')->where('id', $this->match->id)->update(['attributes' => json_encode([
            'promo' => ['note' => 'x'], 'weather' => '晴',
        ])]);

        $this->artisan('tables:export', ['--table' => ['matches']])->assertSuccessful();

        $body = gzdecode(Storage::disk('s3')->get($this->key('matches')));
        $this->assertStringNotContainsString('promo', $body);
        $this->assertStringNotContainsString('note', $body);
        $this->assertEquals((object) ['weather' => '晴'], $this->readLines($this->key('matches'))[0]->attributes);
    }

    public function test_matches_attributes_only_exports_whitelisted_keys(): void
    {
        DB::table('matches')->where('id', $this->match->id)->update(['attributes' => json_encode([
            'weather' => 'rain', 'memo' => 'secret', 'unsold' => 3, 'promo' => null,
        ])]);

        $this->artisan('tables:export', ['--table' => ['matches']])->assertSuccessful();

        $body = gzdecode(Storage::disk('s3')->get($this->key('matches')));
        $this->assertStringNotContainsString('memo', $body);
        $this->assertStringNotContainsString('secret', $body);
        $this->assertEquals(
            (object) ['weather' => 'rain', 'unsold' => 3, 'promo' => null],
            $this->readLines($this->key('matches'))[0]->attributes
        );
    }

    public function test_table_option_limits_the_export(): void
    {
        $this->artisan('tables:export', ['--table' => ['teams', 'seasons']])
            ->expectsOutputToContain('table=teams rows=2')
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$this->key('teams'), $this->key('seasons')],
            Storage::disk('s3')->allFiles('raw')
        );
    }

    public function test_unknown_table_fails_without_writing(): void
    {
        $this->artisan('tables:export', ['--table' => ['teams', 'users']])->assertFailed();
        $this->artisan('tables:export', ['--table' => ['nope']])->assertFailed();

        $this->assertSame([], Storage::disk('s3')->allFiles('raw'));
    }

    public function test_write_stream_failure_keeps_existing_file_and_continues(): void
    {
        Storage::disk('s3')->put($this->key('orders'), 'old');
        $this->failingDisk('writeStream', fn ($e) => $e->with($this->key('orders'), \Mockery::any())->once()->andReturn(false));

        $this->artisan('tables:export')
            ->expectsOutputToContain('書き込みに失敗しました')
            ->assertFailed();

        $disk = Storage::disk('s3');
        $this->assertSame('old', $disk->get($this->key('orders')));
        $this->assertTrue($disk->exists($this->key('tickets')));
        $this->assertTrue($disk->exists($this->key('teams')));
    }

    public function test_delete_failure_fails_and_continues(): void
    {
        Storage::disk('s3')->put('raw/order_items/part-0001.jsonl.gz', 'x');
        $this->failingDisk('delete', fn ($e) => $e->once()->andReturn(false));

        $this->artisan('tables:export')
            ->expectsOutputToContain('削除に失敗しました')
            ->assertFailed();

        $this->assertTrue(Storage::disk('s3')->exists($this->key('tickets')));
    }

    public function test_listing_failure_fails_and_continues(): void
    {
        $this->failingDisk('allFiles', fn ($e) => $e->with('raw/orders')->once()->andThrow(new \RuntimeException('list boom')));

        $this->artisan('tables:export')
            ->expectsOutputToContain('list boom')
            ->assertFailed();

        $this->assertTrue(Storage::disk('s3')->exists($this->key('tickets')));
    }
}
