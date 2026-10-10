<?php

namespace Tests\Feature;

use App\Support\DemoData\PgCopy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PgCopyRoundTripTest extends TestCase
{
    use RefreshDatabase;

    public function test_special_characters_and_null_survive_copy(): void
    {
        $payload = json_encode(['text' => "line1\nline2\ttab \\ back\\slash \\N", 'path' => 'a/b', 'jp' => '日本語']);
        $sessionWithSpecials = "s\ttab\nnl\\bs";
        PgCopy::copy(DB::connection()->getPdo(), 'events', ['event_type', 'user_id', 'session_id', 'device', 'payload', 'occurred_at', 'created_at', 'updated_at'], [
            PgCopy::line(['view_match', null, $sessionWithSpecials, null, $payload, PgCopy::timestampTz(1_609_459_200), PgCopy::timestamp(1_609_459_200), PgCopy::timestamp(1_609_459_200)]),
            PgCopy::line(['view_match', null, 'N', '\\N', '{}', PgCopy::timestampTz(1_609_459_200), PgCopy::timestamp(1_609_459_200), PgCopy::timestamp(1_609_459_200)]),
        ]);

        $rows = DB::table('events')->orderBy('id')->get();
        $this->assertSame($sessionWithSpecials, $rows[0]->session_id);
        $this->assertNull($rows[0]->user_id);
        $this->assertNull($rows[0]->device);
        $this->assertEquals(json_decode($payload, true), json_decode($rows[0]->payload, true));
        $this->assertSame('N', $rows[1]->session_id);
        $this->assertSame('\\N', $rows[1]->device);
    }
}
