<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class PurchaseException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function seatUnavailable(): self
    {
        return new self('seat_unavailable', 'この席は他の方が押さえているか、すでに売り切れです。', 409);
    }

    public static function soldOut(): self
    {
        return new self('sold_out', 'このブロックは売り切れです。', 409);
    }

    public static function cartExpired(): self
    {
        return new self('cart_expired', 'カートの有効期限が切れました。', 409);
    }

    public static function cartEmpty(): self
    {
        return new self('cart_empty', 'カートに有効な席がありません。', 422);
    }

    public static function paymentDeclined(): self
    {
        return new self('payment_declined', '決済が承認されませんでした。', 402);
    }

    public static function cartLimitReached(int $max): self
    {
        return new self('cart_limit_reached', "1 回のご購入は {$max} 枚までです。", 422);
    }

    public static function notOnSale(string $message = 'この席は現在販売していません。'): self
    {
        return new self('not_on_sale', $message, 422);
    }

    public static function priceNotFound(): self
    {
        return new self('price_not_found', '価格が設定されていません。', 422);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
