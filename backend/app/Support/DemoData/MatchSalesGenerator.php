<?php

namespace App\Support\DemoData;

/**
 * 1 試合分の注文・チケット・行動ログを作って sink に渡す。乱数は試合ごとの SeededRandom だけを使うので、
 * 他の試合や --seasons の値に影響されない。AS_OF より後の注文・イベントは作らない（捨てるだけで詰め直さない）。
 *
 * 流れ: 制約なしの需要でブロックごとに注文を作る → 時刻順に並べてブロックの残りで打ち切る（あふれた分は離脱セッション）
 * → 年パス会員の引換を固定席に作る → 買わずに離脱するセッションを足す。
 */
final class MatchSalesGenerator
{
    /** これより前の注文・イベントは作らない（2021-01-01 00:00 UTC）。注文はこの 1 時間後以降 */
    public const EARLIEST_EPOCH = 1_609_459_200;

    /** 一般販売はキックオフのこの日数前から。FC の先行販売はその前の PRESALE_DAYS 日間 */
    public const GENERAL_SALE_DAYS = 35;

    public const PRESALE_DAYS = 7;

    /** 雨・雪の試合で、キックオフのこの日数前以降の注文を落とす割合 */
    public const BAD_WEATHER_WINDOW_DAYS = 3;

    public const BAD_WEATHER_DROP = 0.35;

    /** 年パス会員が試合ごとに引き換える確率 */
    public const PASS_REDEEM_RATE = 0.85;

    private const SETTLE_SECONDS = 3600;

    private const MAX_REDRAWS = 20;

    private readonly int $asOf;

    /**
     * @param  array<string, int>  $ticketTypeIds  code => id
     */
    public function __construct(
        private readonly DemandModel $demand,
        private readonly SeasonCrowd $crowd,
        private readonly SeasonPassSeating $seating,
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
        $overflowRng = $root->fork('overflow');
        $joinedBy = $match->kickoffAt - 2 * self::SETTLE_SECONDS;
        $noise = $this->demand->matchNoise($root->fork('noise'));
        $matchIndex = $this->demand->matchIndex($match, $noise);
        $badWeather = in_array($match->attributes['weather'] ?? '', [WeatherCondition::Rainy->value, WeatherCondition::Snowy->value], true);
        $purchases = 0;

        foreach ($blocks as ['block' => $block, 'allocation' => $allocation]) {
            $fixed = $allocation === 'home' ? $this->seating->fixedCount($block->id) : 0;
            $sellable = $block->capacity - $fixed;
            $seatPool = null;
            if ($block->seatingType === 'reserved') {
                $free = array_values(array_filter($seatIds[$block->id] ?? [], fn (int $id) => ! $this->seating->isFixedSeat($id)));
                $seatPool = $seatRng->shuffle($free);
                $sellable = min($sellable, count($seatPool));
            }

            $demand = $this->demand->blockDemand($match, $block, $allocation, $noise);
            $wanted = (int) round($demand->rate * ($block->capacity - $fixed) * $this->scale);
            if ($wanted <= 0 || $sellable <= 0) {
                continue;
            }

            // 制約なしの需要ぶんの注文を作る。雨・雪の直前の注文は一部が落ちる
            $orders = [];
            $dropped = [];
            for ($ticketsDrawn = 0; $ticketsDrawn < $wanted;) {
                $size = min($wanted - $ticketsDrawn, $this->demand->orderSize($match, $block, $orderRng));
                $ticketsDrawn += $size;
                [$buyer, $orderedAt] = $this->drawBuyerAndTime($match, $block->categoryType, $demand->index, $orderRng, $joinedBy);
                if ($badWeather && $orderedAt >= $match->kickoffAt - self::BAD_WEATHER_WINDOW_DAYS * 86400 && $orderRng->chance(self::BAD_WEATHER_DROP)) {
                    $dropped[] = [$buyer, $orderedAt];

                    continue;
                }
                $orders[] = [$orderedAt, $buyer, $size];
            }

            // 時刻順に並べ、ブロックの残りに達したところで打ち切る
            usort($orders, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $categoryPrices = $prices[$block->seatCategoryId] ?? [];
            $remaining = $sellable;
            $cursor = 0;
            foreach ($orders as [$orderedAt, $buyer, $size]) {
                if ($remaining <= 0) {
                    $this->emitAbandoned($match, $buyer, $orderedAt, 2, $block->code, $overflowRng);

                    continue;
                }
                $size = min($size, $remaining);
                $remaining -= $size;

                $items = [];
                foreach ($this->demand->ticketTypesForOrder($match, $block, $size, $orderRng) as $code) {
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

            foreach ($dropped as [$buyer, $orderedAt]) {
                $this->emitAbandoned($match, $buyer, $orderedAt, (int) $overflowRng->weighted([2 => 30, 3 => 70]), null, $overflowRng);
            }
        }

        $this->emitPassRedemptions($match, $blocks, $prices, $matchIndex, $root->fork('passes'), $funnelRng);

        $sessions = (int) round($purchases * $this->browse);
        for ($i = 0; $i < $sessions; $i++) {
            $this->emitDropoff($match, $matchIndex, $dropoffRng);
        }
    }

    /**
     * 買い手と注文時刻を引く。先行販売の期間（一般販売の開始より前）に落ちたら FC 会員でなければ引き直す。
     * FC の重みは会員期間を見ずに付けているので、まだ会員でない時点の注文に当たったら重みの分だけ棄却して引き直す
     *
     * @return array{0: int, 1: int} [ユーザー添字, 注文時刻]
     */
    private function drawBuyerAndTime(MatchContext $match, string $categoryType, float $index, SeededRandom $rng, int $joinedBy): array
    {
        $generalStart = $match->kickoffAt - self::GENERAL_SALE_DAYS * 86400;

        for ($try = 1; ; $try++) {
            $lead = $this->drawLead($index, $rng, (self::GENERAL_SALE_DAYS + self::PRESALE_DAYS) * 1440);
            $buyer = $this->crowd->pickBuyer($categoryType, $rng, $joinedBy);
            $lowerBound = max(self::EARLIEST_EPOCH, $this->crowd->joinedAt($buyer)) + self::SETTLE_SECONDS;
            $orderedAt = $this->settle($match, $match->kickoffAt - $lead * 60, $lowerBound, $rng);

            $presaleOk = $orderedAt >= $generalStart || $this->crowd->isFanclubAt($buyer, $orderedAt);
            $weightOk = ! $this->crowd->isOverweightedAt($buyer, $orderedAt) || $rng->chance(1 / SeasonCrowd::FANCLUB_WEIGHT);
            if ($try >= self::MAX_REDRAWS || ($presaleOk && $weightOk)) {
                if (! $presaleOk) {
                    $orderedAt = min($match->kickoffAt, max($generalStart, $lowerBound) + $rng->int(0, 86399));
                }

                return [$buyer, $orderedAt];
            }
        }
    }

    /** 販売開始より前に出た分は打ち切って引き直す（上限は分） */
    private function drawLead(float $index, SeededRandom $rng, int $maxMinutes): int
    {
        for ($try = 0; $try < self::MAX_REDRAWS; $try++) {
            $lead = $this->demand->leadMinutes($index, $rng);
            if ($lead <= $maxMinutes) {
                return $lead;
            }
        }

        return $rng->int(0, $maxMinutes);
    }

    /**
     * 年パス会員の引換。固定席に、毎試合 PASS_REDEEM_RATE の確率で 1 枚（price は年パス引換の価格）。時期は一般と同じ分布で、先行期間には入れない
     *
     * @param  list<array{block: BlockInfo, allocation: string}>  $blocks
     * @param  array<int, array<int, int>>  $prices
     */
    private function emitPassRedemptions(MatchContext $match, array $blocks, array $prices, float $matchIndex, SeededRandom $rng, SeededRandom $funnelRng): void
    {
        $byId = [];
        foreach ($blocks as ['block' => $block, 'allocation' => $allocation]) {
            if ($allocation === 'home') {
                $byId[$block->id] = $block;
            }
        }
        $typeId = $this->ticketTypeIds['season_pass'];

        foreach ($this->seating->holders() as $holder) {
            $shows = $rng->chance(self::PASS_REDEEM_RATE);
            $block = $byId[$this->seating->blockOf($holder)] ?? null;
            $joined = $this->crowd->joinedAt($holder);
            if (! $shows || $block === null || $joined > $match->kickoffAt - 2 * self::SETTLE_SECONDS) {
                continue;
            }

            $lowerBound = max(self::EARLIEST_EPOCH, $joined, $this->crowd->passFrom($holder)) + self::SETTLE_SECONDS;
            $orderedAt = $this->settle($match, $match->kickoffAt - $this->drawLead($matchIndex, $rng, self::GENERAL_SALE_DAYS * 1440) * 60, $lowerBound, $rng);
            $channel = $rng->weighted(['web' => 70, 'app' => 20, 'convenience_store' => 10]);
            if ($orderedAt > $this->asOf) {
                continue;
            }
            $items = [[$this->seating->seatOf($holder), $typeId, $prices[$block->seatCategoryId][$typeId] ?? 0]];
            $this->emitOrder($match, $block, $holder, $items, $orderedAt, $channel, $rng, $funnelRng);
        }
    }

    /**
     * 買わずに離脱した購入候補の行動ログ。$depth 2 = 座席表まで、3 = カートまで。$blockCode は完売ブロックで離脱したときだけ付ける
     */
    private function emitAbandoned(MatchContext $match, int $buyerIndex, int $at, int $depth, ?string $blockCode, SeededRandom $rng): void
    {
        $sessionId = $rng->uuid();
        $device = $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $quantity = $rng->int(1, 4);
        $this->emitSession($match, $this->crowd->userId($buyerIndex), $sessionId, $device, $at, $depth, $quantity, $blockCode, $rng);
    }

    /** @param  int  $depth  1 = 試合ページ、2 = 座席表、3 = カート */
    private function emitSession(MatchContext $match, ?int $userId, string $sessionId, string $device, int $base, int $depth, int $quantity, ?string $blockCode, SeededRandom $rng): void
    {
        $seatmap = ['match_id' => $match->matchId] + ($blockCode === null ? [] : ['block_code' => $blockCode]);
        $sequence = [
            ['view_match', ['match_id' => $match->matchId]],
            ['view_seatmap', $seatmap],
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

    private function emitDropoff(MatchContext $match, float $matchIndex, SeededRandom $rng): void
    {
        $sessionId = $rng->uuid();
        $device = $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $depth = (int) $rng->weighted([1 => 50, 2 => 30, 3 => 20]);
        $isKnownUser = $rng->chance(0.30);
        $quantity = $rng->int(1, 4);
        $base = $this->settle($match, $match->kickoffAt - $this->drawLead($matchIndex, $rng, self::GENERAL_SALE_DAYS * 1440) * 60, self::EARLIEST_EPOCH + self::SETTLE_SECONDS, $rng);

        $userIndex = $isKnownUser ? $this->crowd->pickBrowser($rng, $base) : null;
        $userId = $userIndex === null ? null : $this->crowd->userId($userIndex);

        $this->emitSession($match, $userId, $sessionId, $device, $base, $depth, $quantity, null, $rng);
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
