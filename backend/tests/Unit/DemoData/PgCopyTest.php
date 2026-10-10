<?php

namespace Tests\Unit\DemoData;

use App\Support\DemoData\PgCopy;
use PHPUnit\Framework\TestCase;

class PgCopyTest extends TestCase
{
    public function test_escape_handles_backslash_tab_newline(): void
    {
        $this->assertSame('a\\\\b\\tc\\nd\\re', PgCopy::escape("a\\b\tc\nd\re"));
    }

    public function test_line_uses_null_marker_and_escapes_only_when_needed(): void
    {
        $this->assertSame("1\t\\N\tplain\t{\"a\":\"x\\\\ny\"}\tt", PgCopy::line([1, null, 'plain', '{"a":"x\ny"}', true]));
        $this->assertSame("a\\tb\tc\\\\d", PgCopy::line(["a\tb", 'c\\d']));
    }

    public function test_timestamp_is_utc(): void
    {
        $this->assertSame('2021-01-01 00:00:00', PgCopy::timestamp(1_609_459_200));
        $this->assertSame('2021-01-01 00:00:00+00', PgCopy::timestampTz(1_609_459_200));
    }
}
