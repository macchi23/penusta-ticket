<?php

namespace App\Support\DemoData;

use SplHeap;

/**
 * 1 ブロックの出品の在庫。出品時刻になるまでは待機、なったら「一次より安い」と「それ以外」の 2 つの山（支払額の安い順）に入る。
 * 成約・締切後の扱い（時刻の判定）は呼び出し側が持つ。
 */
final class ResalePool
{
    /** @var SplHeap<DemoListing> */
    private SplHeap $pending;

    /** @var SplHeap<DemoListing> */
    private SplHeap $discounted;

    /** @var SplHeap<DemoListing> */
    private SplHeap $full;

    /** 値下げの再出品の待ち（再出品の時刻の順）
     *
     * @var SplHeap<DemoListing>
     */
    private SplHeap $reprices;

    /** @var array<int, int> 出品者添字 => 山の中の件数（自分の出品を除いた枚数を数えるため） */
    private array $ownDiscounted = [];

    /** @var array<int, int> */
    private array $ownFull = [];

    private int $discountedCount = 0;

    private int $fullCount = 0;

    public function __construct()
    {
        $this->pending = new class extends SplHeap
        {
            protected function compare(mixed $a, mixed $b): int
            {
                return [$b->listedAt, $b->seq] <=> [$a->listedAt, $a->seq];
            }
        };
        $this->reprices = new class extends SplHeap
        {
            protected function compare(mixed $a, mixed $b): int
            {
                return [$b->relistAt, $b->seq] <=> [$a->relistAt, $a->seq];
            }
        };
        $this->discounted = $this->priceHeap();
        $this->full = $this->priceHeap();
    }

    public function add(DemoListing $listing): void
    {
        $this->pending->insert($listing);
    }

    /**
     * 出品時刻が $at より前の出品を、買える状態にする。山に入れる前に $prepare(出品) を呼ぶ（価格の確定。再出品の予定もここで登録する）
     *
     * @param  callable(DemoListing): void  $prepare
     */
    public function activate(int $at, callable $prepare): void
    {
        while (! $this->pending->isEmpty() && $this->pending->top()->listedAt < $at) {
            $listing = $this->pending->extract();
            $prepare($listing);
            $this->push($listing);
            if ($listing->relistAt !== null) {
                $this->reprices->insert($listing);
            }
        }
    }

    /**
     * 再出品の時刻が $at より前の、まだ売れていない出品を山から外して返す（取り下げ）。呼び出し側が cancelledAt を付けて新しい出品を足す
     *
     * @return list<DemoListing>
     */
    public function takeDueReprices(int $at): array
    {
        $due = [];
        while (! $this->reprices->isEmpty() && $this->reprices->top()->relistAt < $at) {
            $listing = $this->reprices->extract();
            if ($listing->soldAt !== null) {
                continue;
            }
            $listing->cancelledAt = $listing->relistAt;
            $this->confirm([$listing]);
            $due[] = $listing;
        }

        return $due;
    }

    public function isEmpty(): bool
    {
        return $this->discountedCount + $this->fullCount === 0;
    }

    /** $user が買える（自分の出品を除く）枚数。$includeFull = false なら一次より安いものだけ */
    public function available(int $user, bool $includeFull): int
    {
        $count = $this->discountedCount - ($this->ownDiscounted[$user] ?? 0);
        if ($includeFull) {
            $count += $this->fullCount - ($this->ownFull[$user] ?? 0);
        }

        return $count;
    }

    /**
     * 支払額の安い順に $n 枚を取り出す（足りなければ空）。確定するなら confirm()、やめるなら putBack()
     *
     * @return list<DemoListing>
     */
    public function take(int $n, int $user, bool $includeFull): array
    {
        $taken = [];
        $skipped = [];
        while (count($taken) < $n) {
            $heap = $this->cheaperHeap($includeFull);
            if ($heap === null) {
                break;
            }
            $listing = $heap->extract();
            if ($listing->sellerIndex === $user) {
                $skipped[] = $listing;

                continue;
            }
            $taken[] = $listing;
        }
        foreach ($skipped as $listing) {
            $this->heapOf($listing)->insert($listing);
        }
        if (count($taken) < $n) {
            foreach ($taken as $listing) {
                $this->heapOf($listing)->insert($listing);
            }

            return [];
        }

        return $taken;
    }

    /** @param  list<DemoListing>  $taken */
    public function putBack(array $taken): void
    {
        foreach ($taken as $listing) {
            $this->heapOf($listing)->insert($listing);
        }
    }

    /** @param  list<DemoListing>  $taken */
    public function confirm(array $taken): void
    {
        foreach ($taken as $listing) {
            if ($listing->isDiscounted()) {
                $this->discountedCount--;
                $this->ownDiscounted[$listing->sellerIndex]--;
            } else {
                $this->fullCount--;
                $this->ownFull[$listing->sellerIndex]--;
            }
        }
    }

    private function push(DemoListing $listing): void
    {
        if ($listing->isDiscounted()) {
            $this->discounted->insert($listing);
            $this->discountedCount++;
            $this->ownDiscounted[$listing->sellerIndex] = ($this->ownDiscounted[$listing->sellerIndex] ?? 0) + 1;
        } else {
            $this->full->insert($listing);
            $this->fullCount++;
            $this->ownFull[$listing->sellerIndex] = ($this->ownFull[$listing->sellerIndex] ?? 0) + 1;
        }
    }

    /** 取り下げ済みの出品が先頭にあれば捨てる（山からは遅延して消す。件数は取り下げの時点で引いてある） */
    private function purge(SplHeap $heap): void
    {
        while (! $heap->isEmpty() && $heap->top()->cancelledAt !== null) {
            $heap->extract();
        }
    }

    private function heapOf(DemoListing $listing): SplHeap
    {
        return $listing->isDiscounted() ? $this->discounted : $this->full;
    }

    private function cheaperHeap(bool $includeFull): ?SplHeap
    {
        $this->purge($this->discounted);
        $this->purge($this->full);
        $discounted = $this->discounted->isEmpty() ? null : $this->discounted->top();
        $full = ! $includeFull || $this->full->isEmpty() ? null : $this->full->top();
        if ($discounted === null && $full === null) {
            return null;
        }
        if ($full === null) {
            return $this->discounted;
        }
        if ($discounted === null) {
            return $this->full;
        }

        return [$discounted->payment(), $discounted->seq] <= [$full->payment(), $full->seq] ? $this->discounted : $this->full;
    }

    /** @return SplHeap<DemoListing> */
    private function priceHeap(): SplHeap
    {
        return new class extends SplHeap
        {
            protected function compare(mixed $a, mixed $b): int
            {
                return [$b->payment(), $b->seq] <=> [$a->payment(), $a->seq];
            }
        };
    }
}
