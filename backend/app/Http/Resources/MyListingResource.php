<?php

namespace App\Http\Resources;

use App\Models\ResaleListing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 出品者本人に見せる出品。出品者向けの金額(seller_fee / payout)を含むので、他人には使わない。
 *
 * @mixin ResaleListing
 */
class MyListingResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ResaleListing $resource, private bool $onHold = false)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'price' => $this->price,
            'seller_fee' => $this->seller_fee,
            // 設定が変わっても過去の出品は当時の額で説明できるよう、行から逆算する
            'transfer_fee' => $this->price - $this->seller_fee - $this->payout,
            'payout' => $this->payout,
            'closes_at' => OrderResource::time($this->closes_at),
            'on_hold' => $this->onHold,
        ];
    }
}
