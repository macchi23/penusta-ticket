<?php

namespace App\Support\DemoData;

use DateTimeImmutable;

final readonly class Fixture
{
    /** @param  DateTimeImmutable  $kickoffUtc  UTC のキックオフ時刻 */
    public function __construct(
        public int $round,
        public DateTimeImmutable $kickoffUtc,
        public int $homeId,
        public int $awayId,
    ) {}
}
