<?php

namespace App\Services\Purchase;

use RuntimeException;

class SaleMethodRegistry
{
    public function __construct(private FirstComeSaleMethod $firstCome) {}

    public function for(string $method): SaleMethod
    {
        return match ($method) {
            'first_come' => $this->firstCome,
            default => throw new RuntimeException("未知の販売方式: {$method}"),
        };
    }
}
