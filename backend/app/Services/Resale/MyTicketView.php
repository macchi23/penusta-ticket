<?php

namespace App\Services\Resale;

use App\Models\OrderItem;
use App\Models\ResaleListing;

/** マイチケット 1 枚ぶんの表示用データ（ロックなしの読み取り。最終判定は書き込み時の ResaleService）。 */
final class MyTicketView
{
    /**
     * @param  array{0: int, 1: int}|null  $face  [定価, 買い手の券種 id]。can_list のときだけ入る
     */
    public function __construct(
        public readonly OrderItem $item,
        public readonly ?ResaleListing $listing,
        public readonly bool $canList,
        public readonly bool $canCancel,
        public readonly bool $onHold,
        public readonly ?array $face,
    ) {}
}
