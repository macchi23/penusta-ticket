<?php

namespace App\Support\DemoData;

/**
 * 1 試合分の注文・チケット・行動ログを作って sink に渡す。乱数は試合ごとの SeededRandom だけを使うので、
 * 他の試合や --seasons の値に影響されない。AS_OF より後の注文・イベントは作らない（捨てるだけで詰め直さない）。
 *
 * 流れ: 制約なしの需要でブロックごとに注文を作る → 時刻順に並べてブロックの残りで打ち切る（あふれた分は離脱セッション）
 * → 買わずに離脱するセッションを足す。
 * 年パスの固定席は issuePasses() が試合ごとに全員分を発行する（購入の乱数とは独立）。
 * 販売フェーズ（FC 先行 → 一般）は MatchContext が持つ sale_phases の期間で、注文はフェーズの期間と資格を満たす時刻にだけ作る。
 */
final class MatchSalesGenerator
{
    /** これより前の注文・イベントは作らない（2021-01-01 00:00 UTC）。注文はこの 1 時間後以降 */
    public const EARLIEST_EPOCH = 1_609_459_200;

    /** 雨・雪の試合で、キックオフのこの日数前以降の注文を落とす割合 */
    public const BAD_WEATHER_WINDOW_DAYS = 3;

    public const BAD_WEATHER_DROP = 0.35;

    private const SETTLE_SECONDS = 3600;

    private const MAX_REDRAWS = 20;

    private readonly int $asOf;

    /** @var array<int, int> ユーザー添字 => season_pass_seats.id（writePassSeats で埋める） */
    private array $passSeatIds = [];

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
                $drawn = $this->drawBuyerAndTime($match, $block->categoryType, $demand->index, $orderRng, $joinedBy);
                if ($drawn === null) {
                    continue;
                }
                [$buyer, $orderedAt] = $drawn;
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

        $sessions = (int) round($purchases * $this->browse);
        for ($i = 0; $i < $sessions; $i++) {
            $this->emitDropoff($match, $matchIndex, $dropoffRng);
        }
    }

    /**
     * 買い手と注文時刻を引く。注文時刻を含むフェーズの資格（FC 先行なら FC 会員）を満たさなければ引き直す。
     * FC の重みは会員期間を見ずに付けているので、まだ会員でない時点の注文に当たったら重みの分だけ棄却して引き直す
     *
     * 引き直しを尽くしてもフェーズの期間・資格を満たす時刻が作れない試合（フェーズが無い、資格を問わないフェーズが無い等）は null（注文を作らない）
     *
     * @return array{0: int, 1: int}|null [ユーザー添字, 注文時刻]
     */
    private function drawBuyerAndTime(MatchContext $match, string $categoryType, float $index, SeededRandom $rng, int $joinedBy): ?array
    {
        $openStart = $match->openPhase()->startsAt ?? $match->kickoffAt - 1;
        $lastOrderAt = min($match->kickoffAt, $match->salesEnd()) - 1;

        for ($try = 1; ; $try++) {
            $lead = $this->drawLead($index, $rng, intdiv($match->kickoffAt - $match->salesStart(), 60));
            $buyer = $this->crowd->pickBuyer($categoryType, $rng, $joinedBy);
            $lowerBound = max(self::EARLIEST_EPOCH, $this->crowd->joinedAt($buyer)) + self::SETTLE_SECONDS;
            $orderedAt = $this->settle($match, $match->kickoffAt - $lead * 60, $lowerBound, $rng, $lastOrderAt);

            $phase = $match->phaseAt($orderedAt);
            $presaleOk = $phase !== null && $this->isEligible($buyer, $phase, $orderedAt);
            $weightOk = ! $this->crowd->isOverweightedAt($buyer, $orderedAt) || $rng->chance(1 / SeasonCrowd::FANCLUB_WEIGHT);
            if ($try >= self::MAX_REDRAWS || ($presaleOk && $weightOk)) {
                if (! $presaleOk) {
                    $orderedAt = min($lastOrderAt, max($openStart, $lowerBound) + $rng->int(0, 86399));
                    $phase = $match->phaseAt($orderedAt);
                    if ($phase === null || ! $this->isEligible($buyer, $phase, $orderedAt)) {
                        return null;
                    }
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

    private function isEligible(int $buyer, SalePhaseWindow $phase, int $at): bool
    {
        return match ($phase->eligibility) {
            null => true,
            MembershipPlan::FANCLUB => $this->crowd->isFanclubAt($buyer, $at),
            MembershipPlan::SEASON_PASS => $this->crowd->hasSeasonPassAt($buyer, $at),
            default => false,
        };
    }

    /**
     * シーズンの年パス固定席を season_pass_seats に書く。user 添字の昇順に id を振り、holder => 行 id を覚える
     */
    public function writePassSeats(SeasonSpan $span): void
    {
        $this->passSeatIds = [];
        foreach ($this->seating->holders() as $holder) {
            $id = $this->sink->nextId('season_pass_seats');
            $this->passSeatIds[$holder] = $id;
            $created = PgCopy::timestamp($this->crowd->passFrom($holder));
            $this->sink->add('season_pass_seats', [
                $id, $span->seasonId, $this->crowd->userId($holder), $this->seating->blockOf($holder), $this->seating->seatOf($holder), $created, $created,
            ]);
        }
        $this->sink->flush();
    }

    /**
     * 1 試合分の年パス発行。固定席を持つ会員全員に、SeasonPassIssuer と同じ形（kind=season_pass の 0 円の注文 + 明細 + チケット）で作る。
     * 発行時刻は年パスの valid_from（JST 0 時）。AS_OF より後の試合でも発行する。行動ログは作らない
     */
    public function issuePasses(MatchContext $match): void
    {
        $rng = new SeededRandom($this->seed, 'passissue:'.$match->matchId);
        $typeId = $this->ticketTypeIds['season_pass'];

        foreach ($this->seating->holders() as $holder) {
            $issuedAt = $this->crowd->passFrom($holder);
            $created = PgCopy::timestamp($issuedAt);
            $orderId = $this->sink->nextId('orders');
            $ticketId = $this->sink->nextId('tickets');

            $this->sink->add('orders', [$orderId, $this->crowd->userId($holder), $match->matchId, 'paid', 'season_pass', null, 0, 'system', PgCopy::timestampTz($issuedAt), $created, $created]);
            $this->sink->add('tickets', [$ticketId, $match->matchId, $this->seating->blockOf($holder), $this->seating->seatOf($holder), 'paid', $rng->uuid(), null, $this->passSeatIds[$holder], $created, $created]);
            $this->sink->add('order_items', [$orderId, $ticketId, $typeId, 0, $created, $created]);
            $this->sink->checkpoint();
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
        $phaseId = $match->phaseAt($orderedAt)?->id;
        $total = 0;

        foreach ($items as [$seatId, $typeId, $price]) {
            $ticketId = $this->sink->nextId('tickets');
            $total += $price;
            $this->sink->add('tickets', [$ticketId, $match->matchId, $block->id, $seatId, 'paid', $orderRng->uuid(), null, null, $created, $created]);
            $this->sink->add('order_items', [$orderId, $ticketId, $typeId, $price, $created, $created]);
        }

        $this->sink->add('orders', [$orderId, $userId, $match->matchId, 'paid', 'purchase', $phaseId, $total, $channel, PgCopy::timestampTz($orderedAt), $created, $created]);

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
        $openStart = $match->openPhase()->startsAt ?? $match->kickoffAt;
        $base = $this->settle($match, $match->kickoffAt - $this->drawLead($matchIndex, $rng, intdiv($match->kickoffAt - $openStart, 60)) * 60, self::EARLIEST_EPOCH + self::SETTLE_SECONDS, $rng, $match->kickoffAt);

        $userIndex = $isKnownUser ? $this->crowd->pickBrowser($rng, $base) : null;
        $userId = $userIndex === null ? null : $this->crowd->userId($userIndex);

        $this->emitSession($match, $userId, $sessionId, $device, $base, $depth, $quantity, null, $rng);
    }

    /**
     * 下限（データの始まり・入会直後）より前に落ちた時刻は、下限で打ち切らず、下限からキックオフまでの間に散らす
     * （打ち切ると下限の 1 点に注文が積み上がる）。$cap（注文は販売の終わり kickoff − 1 秒、行動ログは kickoff）を超えない
     */
    private function settle(MatchContext $match, int $at, int $lowerBound, SeededRandom $rng, int $cap): int
    {
        if ($at >= $lowerBound) {
            return min($at, $cap);
        }

        return min($cap, $lowerBound + $rng->int(0, max(0, $match->kickoffAt - $lowerBound)));
    }

    /** @param  array<string, mixed>  $payload */
    private function emitEvent(string $type, ?int $userId, string $sessionId, string $device, array $payload, int $at): void
    {
        $created = PgCopy::timestamp($at);
        $this->sink->add('events', [$type, $userId, $sessionId, $device, json_encode($payload), PgCopy::timestampTz($at), $created, $created]);
    }
}
