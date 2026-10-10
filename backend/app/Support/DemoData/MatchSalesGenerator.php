<?php

namespace App\Support\DemoData;

/**
 * 1 試合分の注文・チケット・行動ログを作って sink に渡す。乱数は試合ごとの SeededRandom だけを使うので、
 * 他の試合や --seasons の値に影響されない。AS_OF より後の注文・イベントは作らない（捨てるだけで詰め直さない）。
 */
final class MatchSalesGenerator
{
    /** これより前の注文・イベントは作らない（2021-01-01 00:00 UTC）。注文はこの 1 時間後以降 */
    public const EARLIEST_EPOCH = 1_609_459_200;

    private const SETTLE_SECONDS = 3600;

    private const MAX_REDRAWS = 20;

    private readonly int $asOf;

    /**
     * @param  array<string, int>  $ticketTypeIds  code => id
     */
    public function __construct(
        private readonly DemandModel $demand,
        private readonly SeasonCrowd $crowd,
        private readonly CopySink $sink,
        private readonly array $ticketTypeIds,
        private readonly int $seed,
        private readonly float $browse,
        private readonly float $scale = 1.0,
    ) {
        $this->asOf = DemoScenario::asOf()->getTimestamp();
    }

    /**
     * @param  list<array{block: BlockInfo, allocation: string}>  $blocks  block id の昇順
     * @param  array<int, list<int>>  $seatIds  block id => 座席 id（指定席のみ。id の昇順）
     * @param  array<int, array<int, int>>  $prices  seat_category_id => ticket_type_id => 価格
     */
    public function generate(MatchContext $match, array $blocks, array $seatIds, array $prices): void
    {
        if ($this->crowd->isEmpty()) {
            return;
        }

        $root = new SeededRandom($this->seed, 'match:'.$match->matchId);
        $orderRng = $root->fork('orders');
        $seatRng = $root->fork('seats');
        $funnelRng = $root->fork('funnel');
        $dropoffRng = $root->fork('dropoff');
        $targetRng = $root->fork('targets');
        $joinedBy = $match->kickoffAt - 2 * self::SETTLE_SECONDS;
        $purchases = 0;
        $redeemed = [];

        foreach ($blocks as ['block' => $block, 'allocation' => $allocation]) {
            $target = min($block->capacity, (int) round($this->demand->targetSold($match, $block, $allocation, $targetRng) * $this->scale));
            $seatPool = null;
            if ($block->seatingType === 'reserved') {
                $seatPool = $seatRng->shuffle($seatIds[$block->id] ?? []);
                $target = min($target, count($seatPool));
            }
            if ($target <= 0) {
                continue;
            }

            $categoryPrices = $prices[$block->seatCategoryId] ?? [];
            $remaining = $target;
            $cursor = 0;

            while ($remaining > 0) {
                $size = min($remaining, $this->demand->orderSize($match, $block, $orderRng));
                $remaining -= $size;

                // FC の重みは会員期間を見ずに付けているので、まだ会員でない時点の注文に当たったら重みの分だけ棄却して引き直す
                for ($try = 1; ; $try++) {
                    $buyer = $this->crowd->pickBuyer($block->categoryType, $orderRng, $joinedBy);
                    $lowerBound = max(self::EARLIEST_EPOCH, $this->crowd->joinedAt($buyer)) + self::SETTLE_SECONDS;
                    $orderedAt = $this->settle($match, $match->kickoffAt - $this->demand->leadMinutes($match, $orderRng) * 60, $lowerBound, $orderRng);
                    if ($try >= self::MAX_REDRAWS || ! $this->crowd->isOverweightedAt($buyer, $orderedAt)
                        || $orderRng->chance(1 / SeasonCrowd::FANCLUB_WEIGHT)) {
                        break;
                    }
                }

                $isPassHolder = $allocation === 'home' && $this->crowd->hasSeasonPassAt($buyer, $orderedAt);
                $items = [];
                $types = $this->demand->ticketTypesForOrder($match, $block, $isPassHolder, $size, $orderRng);
                foreach ($types as $code) {
                    // 年パスの引換は 1 ユーザー 1 試合 1 枚まで。会員でない人、2 枚目以降は一般の券種にする
                    if ($code === 'season_pass' && (! $isPassHolder || isset($redeemed[$buyer]))) {
                        $code = 'adult';
                    }
                    if ($code === 'season_pass') {
                        $redeemed[$buyer] = true;
                    }
                    $typeId = $this->ticketTypeIds[$code];
                    $items[] = [$seatPool[$cursor++] ?? null, $typeId, $categoryPrices[$typeId] ?? 0];
                }

                $channel = $orderRng->weighted(['web' => 70, 'app' => 20, 'convenience_store' => 10]);
                $purchases++;

                if ($orderedAt > $this->asOf) {
                    continue;
                }

                $this->emitOrder($match, $block, $buyer, $items, $orderedAt, $channel, $orderRng, $funnelRng);
            }
        }

        $sessions = (int) round($purchases * $this->browse);
        for ($i = 0; $i < $sessions; $i++) {
            $this->emitDropoff($match, $dropoffRng);
        }
    }

    /**
     * @param  list<array{0: ?int, 1: int, 2: int}>  $items  [seat_id, ticket_type_id, price]
     */
    private function emitOrder(
        MatchContext $match,
        BlockInfo $block,
        int $buyerIndex,
        array $items,
        int $orderedAt,
        string $channel,
        SeededRandom $orderRng,
        SeededRandom $funnelRng,
    ): void {
        $userId = $this->crowd->userId($buyerIndex);
        $orderId = $this->sink->nextId('orders');
        $created = PgCopy::timestamp($orderedAt);
        $total = 0;

        foreach ($items as [$seatId, $typeId, $price]) {
            $ticketId = $this->sink->nextId('tickets');
            $total += $price;
            $this->sink->add('tickets', [$ticketId, $match->matchId, $block->id, $seatId, 'paid', $orderRng->uuid(), null, $created, $created]);
            $this->sink->add('order_items', [$orderId, $ticketId, $typeId, $price, $created, $created]);
        }

        $this->sink->add('orders', [$orderId, $userId, $match->matchId, 'paid', $total, $channel, PgCopy::timestampTz($orderedAt), $created, $created]);

        // 購入時刻から逆算し、各ステップを前ステップの 1 分以上後に置く
        $cartOffset = $funnelRng->int(1, 6);
        $seatmapOffset = $cartOffset + $funnelRng->int(1, 12);
        $matchOffset = $seatmapOffset + $funnelRng->int(1, 18);
        $sessionId = $funnelRng->uuid();
        $device = $funnelRng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $quantity = count($items);

        $steps = [
            ['view_match', $matchOffset, ['match_id' => $match->matchId]],
            ['view_seatmap', $seatmapOffset, ['match_id' => $match->matchId, 'block_code' => $block->code]],
            ['add_to_cart', $cartOffset, ['match_id' => $match->matchId, 'block_code' => $block->code, 'quantity' => $quantity]],
            ['purchase', 0, ['match_id' => $match->matchId, 'order_id' => $orderId, 'quantity' => $quantity]],
        ];
        foreach ($steps as [$type, $offsetMinutes, $payload]) {
            $this->emitEvent($type, $userId, $sessionId, $device, $payload, $orderedAt - $offsetMinutes * 60);
        }
        $this->sink->checkpoint();
    }

    private function emitDropoff(MatchContext $match, SeededRandom $rng): void
    {
        $sessionId = $rng->uuid();
        $device = $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $depth = (int) $rng->weighted([1 => 50, 2 => 30, 3 => 20]);
        $isKnownUser = $rng->chance(0.30);
        $quantity = $rng->int(1, 4);
        $base = $this->settle($match, $match->kickoffAt - $this->demand->leadMinutes($match, $rng) * 60, self::EARLIEST_EPOCH + self::SETTLE_SECONDS, $rng);

        $userIndex = $isKnownUser ? $this->crowd->pickBrowser($rng, $base) : null;
        $userId = $userIndex === null ? null : $this->crowd->userId($userIndex);

        $sequence = [
            ['view_match', ['match_id' => $match->matchId]],
            ['view_seatmap', ['match_id' => $match->matchId]],
            ['add_to_cart', ['match_id' => $match->matchId, 'quantity' => $quantity]],
        ];

        $cursor = $base;
        foreach (array_slice($sequence, 0, $depth) as [$type, $payload]) {
            $cursor = min($cursor + $rng->int(1, 8) * 60, $match->kickoffAt);
            if ($cursor > $this->asOf) {
                break;
            }
            $this->emitEvent($type, $userId, $sessionId, $device, $payload, $cursor);
        }
        $this->sink->checkpoint();
    }

    /**
     * 下限（データの始まり・入会直後）より前に落ちた時刻は、下限で打ち切らず、下限からキックオフまでの間に散らす
     * （打ち切ると下限の 1 点に注文が積み上がる）
     */
    private function settle(MatchContext $match, int $at, int $lowerBound, SeededRandom $rng): int
    {
        if ($at >= $lowerBound) {
            return min($at, $match->kickoffAt);
        }

        return $lowerBound + $rng->int(0, max(0, $match->kickoffAt - $lowerBound));
    }

    /** @param  array<string, mixed>  $payload */
    private function emitEvent(string $type, ?int $userId, string $sessionId, string $device, array $payload, int $at): void
    {
        $created = PgCopy::timestamp($at);
        $this->sink->add('events', [$type, $userId, $sessionId, $device, json_encode($payload), PgCopy::timestampTz($at), $created, $created]);
    }
}
