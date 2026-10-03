<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ExportEventsTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'raw/events/dt=2026-08-23/part-0000.jsonl.gz';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('s3');
    }

    private function makeEvent(string $at, array $payload = [], ?string $session = 'sess-1'): ActivityEvent
    {
        return ActivityEvent::create([
            'event_type' => 'page_view',
            'user_id' => null,
            'session_id' => $session,
            'device' => 'pc',
            'payload' => $payload,
            'occurred_at' => $at,
        ]);
    }

    private function seedBoundary(): array
    {
        return [
            $this->makeEvent('2026-08-22T23:59:59Z', ['a' => 1]),
            $this->makeEvent('2026-08-23T00:00:00Z', ['match_id' => 1]),
            $this->makeEvent('2026-08-23T23:59:59Z', []),
            $this->makeEvent('2026-08-24T00:00:00Z', ['a' => 2]),
        ];
    }

    private function readLines(string $key): array
    {
        $lines = array_filter(explode("\n", gzdecode(Storage::disk('s3')->get($key))));

        return array_map(fn ($l) => json_decode($l), array_values($lines));
    }

    public function test_boundaries_are_respected(): void
    {
        $e = $this->seedBoundary();

        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame([self::KEY], Storage::disk('s3')->allFiles('raw/events'));
        $rows = $this->readLines(self::KEY);
        $this->assertCount(2, $rows);
        $this->assertSame([$e[1]->id, $e[2]->id], array_map(fn ($r) => $r->id, $rows));
    }

    public function test_row_format(): void
    {
        $this->seedBoundary();
        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $rows = $this->readLines(self::KEY);
        $this->assertSame('2026-08-23T00:00:00Z', $rows[0]->occurred_at);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $rows[0]->created_at);
        $this->assertEquals((object) ['match_id' => 1], $rows[0]->payload);
        $this->assertInstanceOf(\stdClass::class, $rows[1]->payload);
        $this->assertSame([], (array) $rows[1]->payload);
        $this->assertObjectNotHasProperty('dt', $rows[0]);

        $raw = gzdecode(Storage::disk('s3')->get(self::KEY));
        $this->assertStringContainsString('"payload":{"match_id":1}', $raw);
        $this->assertStringContainsString('"payload":{}', $raw);
    }

    public function test_e2e_sessions_are_included(): void
    {
        $this->makeEvent('2026-08-23T10:00:00Z', [], 'e2e0abc');
        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame('e2e0abc', $this->readLines(self::KEY)[0]->session_id);
    }

    public function test_rerun_is_idempotent(): void
    {
        $this->seedBoundary();
        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();
        $files1 = Storage::disk('s3')->allFiles('raw/events');
        $body1 = Storage::disk('s3')->get(self::KEY);

        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame($files1, Storage::disk('s3')->allFiles('raw/events'));
        $this->assertSame(gzdecode($body1), gzdecode(Storage::disk('s3')->get(self::KEY)));
    }

    public function test_stale_keys_are_removed(): void
    {
        $this->seedBoundary();
        Storage::disk('s3')->put('raw/events/dt=2026-08-23/part-0001.jsonl.gz', 'x');

        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame([self::KEY], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_empty_day_removes_existing_files(): void
    {
        Storage::disk('s3')->put(self::KEY, 'x');
        Storage::disk('s3')->put('raw/events/dt=2026-08-23/part-0001.jsonl.gz', 'x');

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutput('dt=2026-08-23 rows=0 deleted=2')
            ->assertSuccessful();

        $this->assertSame([], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_defaults_to_yesterday_utc(): void
    {
        $this->seedBoundary();
        $this->travelTo('2026-08-24 15:30:00 UTC');

        $this->artisan('events:export')->assertSuccessful();

        $this->assertSame([self::KEY], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_range_exports_multiple_days(): void
    {
        $this->seedBoundary();

        $this->artisan('events:export', ['--from' => '2026-08-22', '--to' => '2026-08-24'])->assertSuccessful();

        $this->assertEqualsCanonicalizing([
            'raw/events/dt=2026-08-22/part-0000.jsonl.gz',
            self::KEY,
            'raw/events/dt=2026-08-24/part-0000.jsonl.gz',
        ], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_invalid_input_fails(): void
    {
        $this->artisan('events:export', ['--date' => '2026/08/23'])->assertFailed();
        $this->artisan('events:export', ['--date' => '2026-02-30'])->assertFailed();
        $this->artisan('events:export', ['--from' => '2026-08-24', '--to' => '2026-08-23'])->assertFailed();
        $this->artisan('events:export', ['--date' => '2026-08-23', '--from' => '2026-08-22', '--to' => '2026-08-23'])->assertFailed();
        $this->artisan('events:export', ['--from' => '2026-08-23'])->assertFailed();
        $this->artisan('events:export', ['--to' => '2026-08-23'])->assertFailed();
        $this->assertSame([], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_other_days_are_untouched(): void
    {
        $this->seedBoundary();
        Storage::disk('s3')->put('raw/events/dt=2026-08-24/part-0000.jsonl.gz', 'keep');

        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame('keep', Storage::disk('s3')->get('raw/events/dt=2026-08-24/part-0000.jsonl.gz'));
    }

    public function test_subprefix_files_are_removed(): void
    {
        $this->seedBoundary();
        Storage::disk('s3')->put('raw/events/dt=2026-08-23/tmp/x.jsonl.gz', 'x');

        $this->artisan('events:export', ['--date' => '2026-08-23'])->assertSuccessful();

        $this->assertSame([self::KEY], Storage::disk('s3')->allFiles('raw/events'));
    }

    public function test_valueless_options_and_trailing_newline_fail(): void
    {
        $this->artisan('events:export', ['--date' => null])->assertFailed();
        $this->artisan('events:export', ['--from' => null, '--to' => '2026-08-23'])->assertFailed();
        $this->artisan('events:export', ['--from' => '2026-08-23', '--to' => null])->assertFailed();
        $this->artisan('events:export', ['--date' => "2026-08-23\n"])->assertFailed();
    }

    public function test_write_stream_failure_keeps_existing_file(): void
    {
        $this->seedBoundary();
        $fake = Storage::fake('s3');
        $fake->put(self::KEY, 'old');
        $mock = \Mockery::mock($fake)->makePartial();
        $mock->shouldReceive('writeStream')->once()->andReturn(false);
        Storage::set('s3', $mock);

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutputToContain('書き込みに失敗しました')
            ->assertFailed();

        $this->assertSame('old', $fake->get(self::KEY));
    }

    public function test_delete_failure_fails(): void
    {
        $fake = Storage::fake('s3');
        $fake->put(self::KEY, 'old');
        $mock = \Mockery::mock($fake)->makePartial();
        $mock->shouldReceive('delete')->once()->andReturn(false);
        Storage::set('s3', $mock);

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutputToContain('削除に失敗しました')
            ->assertFailed();
    }

    public function test_delete_failure_of_stale_key_after_write_fails(): void
    {
        $this->seedBoundary();
        $fake = Storage::fake('s3');
        $stale = 'raw/events/dt=2026-08-23/part-0001.jsonl.gz';
        $fake->put($stale, 'old');
        $mock = \Mockery::mock($fake)->makePartial();
        $mock->shouldReceive('delete')->once()->with($stale)->andReturn(false);
        Storage::set('s3', $mock);

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutputToContain('削除に失敗しました')
            ->assertFailed();
    }

    public function test_listing_failure_fails(): void
    {
        $fake = Storage::fake('s3');
        $mock = \Mockery::mock($fake)->makePartial();
        $mock->shouldReceive('allFiles')->once()->andThrow(new \RuntimeException('list boom'));
        Storage::set('s3', $mock);

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutputToContain('list boom')
            ->assertFailed();
    }

    public function test_exception_during_row_processing_fails_and_keeps_existing_file(): void
    {
        $this->seedBoundary();
        $fake = Storage::fake('s3');
        $fake->put(self::KEY, 'old');
        ActivityEvent::retrieved(function () {
            throw new \RuntimeException('row boom');
        });

        $this->artisan('events:export', ['--date' => '2026-08-23'])
            ->expectsOutputToContain('row boom')
            ->assertFailed();

        $this->assertSame('old', $fake->get(self::KEY));
    }
}
