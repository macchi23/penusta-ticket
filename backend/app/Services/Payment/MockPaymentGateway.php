<?php

namespace App\Services\Payment;

use App\Models\Order;
use Illuminate\Support\Str;

class MockPaymentGateway
{
    public function charge(Order $order, int $amount): PaymentResult
    {
        $transactionId = 'mock_'.Str::uuid();

        return config('ticketing.mock_payment_outcome') === 'success'
            ? new PaymentResult(true, null, $transactionId)
            : new PaymentResult(false, 'declined', $transactionId);
    }
}
