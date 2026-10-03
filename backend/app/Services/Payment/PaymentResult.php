<?php

namespace App\Services\Payment;

final readonly class PaymentResult
{
    public function __construct(
        public bool $ok,
        public ?string $reason,
        public string $transactionId,
    ) {}
}
