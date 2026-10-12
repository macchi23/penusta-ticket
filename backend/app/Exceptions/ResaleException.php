<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class ResaleException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
    ) {
        parent::__construct($message);
    }

    public static function closed(): self
    {
        return new self('resale_closed', 'この試合のリセールは現在受け付けていません。', 422);
    }

    public static function notOwner(): self
    {
        return new self('not_owner', 'このチケットの持ち主ではありません。', 403);
    }

    public static function notListable(string $message = 'このチケットは出品できません。'): self
    {
        return new self('not_listable', $message, 422);
    }

    public static function priceOutOfRange(int $min, int $max): self
    {
        return new self('price_out_of_range', "出品価格は {$min} 円以上 {$max} 円以下で指定してください。", 422);
    }

    public static function listingUnavailable(): self
    {
        return new self('listing_unavailable', 'この出品は購入できません。', 409);
    }

    public static function ownListing(): self
    {
        return new self('own_listing', '自分の出品は購入できません。', 422);
    }

    public static function limitReached(int $max): self
    {
        return new self('resale_limit_reached', "リセールでは 1 試合につき {$max} 枚まで購入できます。", 422);
    }

    public static function paymentFailed(): self
    {
        return new self('payment_failed', '決済が承認されませんでした。', 402);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
