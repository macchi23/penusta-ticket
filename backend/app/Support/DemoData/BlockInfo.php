<?php

namespace App\Support\DemoData;

final readonly class BlockInfo
{
    /**
     * @param  string  $categoryType  goal_back / main / general
     * @param  string  $seatingType  reserved / unreserved
     */
    public function __construct(
        public int $id,
        public string $code,
        public int $seatCategoryId,
        public string $categoryType,
        public string $seatingType,
        public int $capacity,
    ) {}
}
