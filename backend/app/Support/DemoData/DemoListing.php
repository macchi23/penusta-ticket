<?php

namespace App\Support\DemoData;

use App\Services\Resale\ResaleService;

/**
 * 生成中の出品 1 件。試合の終わりに resale_listings の行とイベントへ書き出す。
 * 価格は、出品が買える状態になった時点（ブロックの一次の残りが分かる時点）で確定する。値下げの再出品は価格を確定済みで作る
 */
final class DemoListing
{
    public ?int $id = null;

    /** 確定した価格。0 は未確定 */
    public int $price = 0;

    public int $sellerFee = 0;

    public int $payout = 0;

    /** 売れた時刻（epoch 秒）。売れていなければ null */
    public ?int $soldAt = null;

    /** 取り下げた時刻（値下げの再出品のため）。取り下げていなければ null */
    public ?int $cancelledAt = null;

    /** 値下げの再出品を出す時刻。出さないなら null */
    public ?int $relistAt = null;

    /** 同じ買い手がこの試合で直前に買った出品の売れた時刻。仮押さえを重ねないために使う */
    public ?int $buyerPrevSoldAt = null;

    public ?int $buyerIndex = null;

    public ?int $buyerOrderId = null;

    public ?int $newTicketId = null;

    /** 買い手のセッションの前半（view_match / view_resale）。同じ購入グループの先頭の出品だけが持つ
     *
     * @var list<array{0: string, 1: int}> [type, 時刻]
     */
    public array $buyerViews = [];

    public string $buyerSessionId = '';

    public string $buyerDevice = '';

    /**
     * @param  array{0: float, 1: float}  $priceDraws  [定価寄りにする乱数, 値引率の乱数]
     * @param  array{0: float, 1: float, 2: float}  $relistDraws  [再出品するか, 時刻, 値下げ幅]
     */
    public function __construct(
        public readonly int $seq,
        public readonly int $ticketId,
        public readonly int $sellerIndex,
        public readonly int $blockId,
        public readonly int $ticketTypeId,
        public readonly string $source,
        public readonly int $facePrice,
        public readonly int $buyerFee,
        public readonly int $primaryPrice,
        public readonly float $demandIndex,
        public readonly int $listedAt,
        public readonly int $closesAt,
        public readonly string $sessionId,
        public readonly string $device,
        public readonly array $priceDraws,
        public readonly array $relistDraws,
        public readonly bool $isRelist = false,
    ) {}

    public function setPrice(int $price): void
    {
        $this->price = $price;
        $this->sellerFee = ResaleService::sellerFee($price);
        $this->payout = ResaleService::payout($price);
    }

    /** 買い手の支払額 */
    public function payment(): int
    {
        return $this->price + $this->buyerFee;
    }

    /** 買い手が一次販売より安く買えるか */
    public function isDiscounted(): bool
    {
        return $this->payment() < $this->primaryPrice;
    }
}
