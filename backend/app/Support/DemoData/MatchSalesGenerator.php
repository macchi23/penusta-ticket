<?php

namespace App\Support\DemoData;

use App\Enums\OrderKind;
use App\Services\Resale\ResaleService;

/**
 * 1 試合分の注文・チケット・行動ログを作って sink に渡す。乱数は試合ごとの SeededRandom だけを使うので、
 * 他の試合や --seasons の値に影響されない。AS_OF より後の注文・イベントは作らない（捨てるだけで詰め直さない）。
 *
 * 流れ: 制約なしの需要でブロックごとに注文を作る → 時刻順に並べてブロックの残りで打ち切る（あふれた分は離脱セッション）
 * → 買わずに離脱するセッションを足す。
 * 年パスの固定席は issuePasses() が試合ごとに全員分を発行する（購入の乱数とは独立）。
 * 販売フェーズ（FC 先行 → 一般）は MatchContext が持つ sale_phases の期間で、注文はフェーズの期間と資格を満たす時刻にだけ作る。
 *
 * リセール: 年パス券（試合の頭に全員分を決める）と通常購入券（注文を作った時点で決める）の一部を、一般販売開始〜締切の間に出品する。
 * 一次販売の注文を時刻順に処理するループの中で、出品が出た後の買い手は、確率で一次ではなく出品を買う（一次の在庫は減らない）。
 * 完売ブロックであふれた買い手も、出品があれば買う。チケット・出品は成約で書き換わるので、試合の終わり（commitMatch）まで溜めて書く。
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

    private readonly ResaleModel $resale;

    private int $currentMatchId = 0;

    /** @var array<int, list<int|string|null>> 試合の終わりまで溜めるチケットの行（id => 行）。resold への更新を反映して書く */
    private array $heldTickets = [];

    /** @var list<list<int|string|null>> 試合の終わりまで溜める明細の行。チケットより先に書くと外部キーに当たるので、チケットと一緒に書く */
    private array $heldItems = [];

    /** @var array<int, int> ユーザー添字 => 今の試合の年パス券の ticket id */
    private array $passTicketIds = [];

    /** @var list<DemoListing> */
    private array $listings = [];

    private int $listingSeq = 0;

    /** @var array<int, int> ユーザー添字 => 今の試合でリセールで買った枚数 */
    private array $resaleBought = [];

    /** @var array{listed: int, sold: int, cannibalized: int, absorbed: int, relisted: int} */
    private array $stats = ['listed' => 0, 'sold' => 0, 'cannibalized' => 0, 'absorbed' => 0, 'relisted' => 0];

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
        $this->resale = new ResaleModel;
    }

    /**
     * リセールの集計。listed = 出品、sold = 成約、cannibalized = 売り切れ前に出品へ流れた一次の買い手の枚数、absorbed = 完売であふれた買い手が出品を買った枚数、relisted = 値下げの再出品（listed に含む）
     *
     * @return array{listed: int, sold: int, cannibalized: int, absorbed: int, relisted: int}
     */
    public function stats(): array
    {
        return $this->stats;
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
        $listingRng = $root->fork('resale-listing');
        $resaleRng = $root->fork('resale-purchase');
        $joinedBy = $match->kickoffAt - 2 * self::SETTLE_SECONDS;
        $noise = $this->demand->matchNoise($root->fork('noise'));
        $matchIndex = $this->demand->matchIndex($match, $noise);
        $badWeather = in_array($match->attributes['weather'] ?? '', [WeatherCondition::Rainy->value, WeatherCondition::Snowy->value], true);
        $purchases = 0;

        // ブロックごとの需要・席の並び（席の乱数はブロック順に引く）。出品の価格に完売の見込みと需要指数を使うので、先に全ブロック分を決める
        $plans = [];
        $meta = [];
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
            $meta[$block->id] = $demand->index;
            if ($wanted <= 0 || $sellable <= 0) {
                continue;
            }
            $plans[] = [$block, $demand, $wanted, $sellable, $seatPool];
        }

        // リセールの出品。年パス券は全員分をここで決め、通常購入券は注文を作るたびに決める
        $window = $this->resaleWindow($match);
        $pools = [];
        $purchasedRate = 0.0;
        if ($window !== null) {
            [$passRate, $purchasedRate] = $this->resale->listingRates($this->resale->boost($match), $listingRng);
            $adultId = $this->ticketTypeIds['adult'];
            foreach ($this->seating->holders() as $holder) {
                $ticketId = $this->passTicketIds[$holder] ?? null;
                $blockId = $this->seating->blockOf($holder);
                if ($ticketId === null || ! isset($meta[$blockId]) || ! $listingRng->chance($passRate)) {
                    continue;
                }
                $block = $this->blockInfo($blocks, $blockId);
                $single = $prices[$block->seatCategoryId][$adultId] ?? null;
                $face = ResaleService::listingFacePrice(OrderKind::SeasonPass, 0, $this->ticketTypeIds['season_pass'], $single, $adultId)[0] ?? 0;
                $listing = $this->newListing(
                    $ticketId, $holder, $blockId, $adultId, 'season_pass', $face, (int) $single,
                    max($window[0], $this->crowd->passFrom($holder)), $window[1], $meta[$blockId], $listingRng,
                );
                if ($listing !== null) {
                    ($pools[$blockId] ??= new ResalePool)->add($listing);
                }
            }
        }

        foreach ($plans as [$block, $demand, $wanted, $sellable, $seatPool]) {
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

            // 時刻順に並べ、ブロックの残りに達したところで打ち切る。出品の在庫もこの順に進める
            usort($orders, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $categoryPrices = $prices[$block->seatCategoryId] ?? [];
            $pool = $pools[$block->id] ??= new ResalePool;
            $remaining = $sellable;
            $cursor = 0;
            // 出品が買える状態になる時点で、一次の残りを見て価格を確定する（完売間近なら定価寄りが増える）。再出品の予定もここで決める
            $prepare = function (DemoListing $listing) use (&$remaining, $sellable): void {
                if ($listing->price === 0) {
                    $listing->setPrice($this->resale->price($listing->facePrice, $remaining <= ResaleModel::NEAR_SOLD_OUT_SHARE * $sellable, $listing->demandIndex, $listing->priceDraws));
                }
                if (! $listing->isRelist && $this->resale->relistPrice($listing->price, $listing->relistDraws[2]) !== null) {
                    $listing->relistAt = $this->resale->relistAt($listing, $listing->listedAt);
                }
            };
            foreach ($orders as [$orderedAt, $buyer, $size]) {
                $viewedResale = false;
                if ($window !== null && $orderedAt <= $this->asOf && $orderedAt < $window[1]) {
                    $this->relistDue($pool, $orderedAt - ResaleModel::RELIST_DELAY, $listingRng);
                    $pool->activate($orderedAt, $prepare);
                    if (! $pool->isEmpty()) {
                        $overflow = $remaining <= 0;
                        if ($this->buyFromPool($match, $block, $pool, $buyer, $size, $orderedAt, $overflow, $demand->index, $categoryPrices, $window[1], $resaleRng)) {
                            $purchases++;

                            continue;
                        }
                        $viewedResale = ! $overflow && $resaleRng->chance(ResaleModel::VIEW_SHARE);
                    }
                }
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
                $created = $this->emitOrder($match, $block, $buyer, $items, $orderedAt, $channel, $orderRng, $funnelRng, $viewedResale);

                if ($window !== null) {
                    foreach ($created as [$ticketId, $typeId, $price]) {
                        $start = max($window[0], $orderedAt + ResaleModel::MIN_GAP_AFTER_PURCHASE);
                        if (! $listingRng->chance($purchasedRate) || $start >= $window[1]) {
                            continue;
                        }
                        $listing = $this->newListing($ticketId, $buyer, $block->id, $typeId, 'purchase', $price, $price, $start, $window[1], $meta[$block->id], $listingRng);
                        if ($listing !== null) {
                            $pool->add($listing);
                        }
                    }
                }
            }

            if ($window !== null) {
                $this->relistDue($pool, $this->asOf - ResaleModel::RELIST_DELAY + 1, $listingRng);
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
        $this->currentMatchId = $match->matchId;
        $this->passTicketIds = [];

        foreach ($this->seating->holders() as $holder) {
            $issuedAt = $this->crowd->passFrom($holder);
            $created = PgCopy::timestamp($issuedAt);
            $orderId = $this->sink->nextId('orders');
            $ticketId = $this->sink->nextId('tickets');
            $this->passTicketIds[$holder] = $ticketId;

            $this->sink->add('orders', [$orderId, $this->crowd->userId($holder), $match->matchId, 'paid', 'season_pass', null, 0, 'system', PgCopy::timestampTz($issuedAt), $created, $created]);
            $this->heldTickets[$ticketId] = [$ticketId, $match->matchId, $this->seating->blockOf($holder), $this->seating->seatOf($holder), 'paid', $rng->uuid(), null, $this->passSeatIds[$holder], $created, $created];
            $this->heldItems[] = [$orderId, $ticketId, $typeId, 0, $created, $created];
        }
    }

    /**
     * 試合の終わりに、溜めたチケット・明細と出品（とそのイベント）を書く。販売しない試合でも issuePasses の後に必ず呼ぶ。
     * 注文・イベントは溜めずに書いてよい（外部キーが無い、または親が先に書かれる）。出品の id は出品時刻の順に振る
     */
    public function commitMatch(): void
    {
        foreach ($this->listings as $listing) {
            if ($listing->price === 0) {
                $listing->setPrice($this->resale->price($listing->facePrice, false, $listing->demandIndex, $listing->priceDraws));
            }
        }
        usort($this->listings, fn (DemoListing $a, DemoListing $b) => [$a->listedAt, $a->seq] <=> [$b->listedAt, $b->seq]);
        foreach ($this->listings as $listing) {
            $listing->id = $this->sink->nextId('resale_listings');
        }
        foreach ($this->listings as $listing) {
            $this->writeListing($listing);
        }
        foreach ($this->heldTickets as $row) {
            $this->sink->add('tickets', $row);
        }
        foreach ($this->heldItems as $row) {
            $this->sink->add('order_items', $row);
        }

        $this->heldTickets = [];
        $this->heldItems = [];
        $this->passTicketIds = [];
        $this->listings = [];
        $this->resaleBought = [];
        $this->sink->checkpoint();
    }

    private function writeListing(DemoListing $listing): void
    {
        $sellerId = $this->crowd->userId($listing->sellerIndex);
        $status = match (true) {
            $listing->cancelledAt !== null => 'cancelled',
            $listing->soldAt !== null => 'sold',
            $listing->closesAt <= $this->asOf => 'expired',
            default => 'active',
        };
        $updatedAt = match ($status) {
            'cancelled' => $listing->cancelledAt,
            'sold' => $listing->soldAt,
            'expired' => $listing->closesAt,
            default => $listing->listedAt,
        };
        $this->sink->add('resale_listings', [
            $listing->id, $listing->ticketId, $sellerId, $this->currentMatchId, $listing->blockId, $listing->ticketTypeId,
            $listing->facePrice, $listing->price, $listing->sellerFee, $listing->buyerFee, $listing->payout, $status,
            PgCopy::timestampTz($listing->closesAt), $listing->buyerOrderId, $listing->newTicketId,
            PgCopy::timestamp($listing->listedAt), PgCopy::timestamp($updatedAt),
        ]);

        $this->emitEvent('resale_list', $sellerId, $listing->sessionId, $listing->device, [
            'listing_id' => $listing->id,
            'match_id' => $this->currentMatchId,
            'block_id' => $listing->blockId,
            'price' => $listing->price,
            'source' => $listing->source,
        ], $listing->listedAt);

        if ($listing->cancelledAt !== null) {
            $this->emitEvent('resale_cancel', $sellerId, $listing->sessionId, $listing->device, [
                'listing_id' => $listing->id,
                'match_id' => $this->currentMatchId,
            ], $listing->cancelledAt);
        }
        if ($listing->soldAt === null) {
            return;
        }
        $buyerId = $this->crowd->userId((int) $listing->buyerIndex);
        foreach ($listing->buyerViews as [$type, $at]) {
            $this->emitEvent($type, $buyerId, $listing->buyerSessionId, $listing->buyerDevice, ['match_id' => $this->currentMatchId], $at);
        }
        $this->emitEvent('resale_purchase', $buyerId, $listing->buyerSessionId, $listing->buyerDevice, [
            'listing_id' => $listing->id,
            'order_id' => $listing->buyerOrderId,
            'match_id' => $this->currentMatchId,
            'block_id' => $listing->blockId,
            'price' => $listing->price,
            'total' => $listing->payment(),
            'discount_rate' => round(1 - $listing->price / $listing->facePrice, 3),
        ], $listing->soldAt);
    }

    /**
     * リセールの受付期間 [一般販売の開始, 締切)（epoch 秒）。一般販売のフェーズが無い、または締切が開始より前なら null
     *
     * @return array{0: int, 1: int}|null
     */
    private function resaleWindow(MatchContext $match): ?array
    {
        $general = null;
        foreach ($match->phases as $phase) {
            if ($phase->eligibility === null) {
                $general = $phase;
                break;
            }
        }
        $closesAt = $match->kickoffAt - (int) config('ticketing.resale.close_minutes_before_kickoff') * 60;

        return $general !== null && $closesAt > $general->startsAt ? [$general->startsAt, $closesAt] : null;
    }

    /** @param  list<array{block: BlockInfo, allocation: string}>  $blocks */
    private function blockInfo(array $blocks, int $blockId): BlockInfo
    {
        foreach ($blocks as ['block' => $block]) {
            if ($block->id === $blockId) {
                return $block;
            }
        }
        throw new \LogicException("block {$blockId} が割当に無い");
    }

    /**
     * 出品を 1 件決める（価格は買える状態になる時点で確定する）。乱数は付けられなくても引く（出品できるかどうかで以降の流れが変わらないように）。
     * 定価が min_price 未満・出品時刻が AS_OF より後なら null（登録しない）
     */
    private function newListing(int $ticketId, int $seller, int $blockId, int $typeId, string $source, int $face, int $primaryPrice, int $start, int $closesAt, float $index, SeededRandom $rng): ?DemoListing
    {
        $listedAt = $this->resale->listedAt($start, $closesAt, $rng);
        $priceDraws = $this->resale->priceDraws($rng);
        $relistDraws = $this->resale->relistDraws($rng);
        $sessionId = $rng->uuid();
        $device = $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $min = (int) config('ticketing.resale.min_price');
        if ($face < $min || $listedAt > $this->asOf || ResaleService::payout($min) < 0) {
            return null;
        }

        $listing = new DemoListing(
            $this->listingSeq++, $ticketId, $seller, $blockId, $typeId, $source, $face, (int) config('ticketing.resale.buyer_fee'),
            $primaryPrice, $index, $listedAt, $closesAt, $sessionId, $device, $priceDraws, $relistDraws,
        );
        $this->listings[] = $listing;
        $this->stats['listed']++;

        return $listing;
    }

    /** 再出品の時刻になった売れ残りを取り下げ、値下げした価格で同じチケットを出し直す（新しい出品行） */
    private function relistDue(ResalePool $pool, int $at, SeededRandom $rng): void
    {
        foreach ($pool->takeDueReprices($at) as $old) {
            $next = new DemoListing(
                $this->listingSeq++, $old->ticketId, $old->sellerIndex, $old->blockId, $old->ticketTypeId, $old->source, $old->facePrice, $old->buyerFee,
                $old->primaryPrice, $old->demandIndex, (int) $old->relistAt + ResaleModel::RELIST_DELAY, $old->closesAt, $rng->uuid(),
                $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]), [1.0, 1.0], [1.0, 1.0, 1.0], true,
            );
            $next->setPrice((int) $this->resale->relistPrice($old->price, $old->relistDraws[2]));
            $this->listings[] = $next;
            $pool->add($next);
            $this->stats['listed']++;
            $this->stats['relisted']++;
        }
    }

    /**
     * 一次の買い手 1 人（1 注文）が出品側を買えるなら買う。枚数ぶんの出品があるときだけ。指定席で 2 枚以上なら一部の人だけ（1 枚ずつ）。
     * 流れたときは一次の在庫を使わない（呼び出し側は何もしない）。あふれた買い手は支払額が一次以上の出品も買う
     *
     * @param  array<int, int>  $categoryPrices  ticket_type_id => 価格
     */
    private function buyFromPool(MatchContext $match, BlockInfo $block, ResalePool $pool, int $buyer, int $size, int $at, bool $overflow, float $index, array $categoryPrices, int $closesAt, SeededRandom $rng): bool
    {
        $lastAt = $at + ($size - 1) * ResaleModel::PURCHASE_GAP_SECONDS;
        if (($this->resaleBought[$buyer] ?? 0) + $size > (int) config('ticketing.max_tickets_per_order')
            || $lastAt >= $closesAt || $lastAt > $this->asOf
            || $pool->available($buyer, $overflow) < $size) {
            return false;
        }
        // 指定席で複数枚の注文は、隣り合わない席でもよい人だけが 1 枚ずつ出品を買う
        if ($block->seatingType === 'reserved' && $size !== 1 && ! $rng->chance(ResaleModel::SCATTER_BUY)) {
            return false;
        }
        $taken = $pool->take($size, $buyer, $overflow);
        if ($taken === []) {
            return false;
        }

        $discount = 0.0;
        foreach ($taken as $listing) {
            $discount += max(0.0, 1 - $listing->payment() / max(1, $listing->primaryPrice));
        }
        $probability = $overflow ? ResaleModel::OVERFLOW_BUY : $this->resale->buyProbability($discount / $size, $index);
        if (! $rng->chance($probability)) {
            $pool->putBack($taken);

            return false;
        }
        $pool->confirm($taken);

        $buyerId = $this->crowd->userId($buyer);
        $sessionId = $rng->uuid();
        $device = $rng->weighted(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $viewMatchAt = $at - $rng->int(3, 20) * 60;
        $viewResaleAt = $at - $rng->int(1, 2) * 60;

        foreach ($taken as $k => $listing) {
            $orderedAt = $at + $k * ResaleModel::PURCHASE_GAP_SECONDS;
            $created = PgCopy::timestamp($orderedAt);
            $orderId = $this->sink->nextId('orders');
            $ticketId = $this->sink->nextId('tickets');

            // ResaleService::purchase と同じ形: 元のチケットは resold、同じ席に新しいチケット、resale の注文と明細
            $original = &$this->heldTickets[$listing->ticketId];
            $original[4] = 'resold';
            $original[9] = $created;
            $seatId = $original[3];
            unset($original);
            $this->heldTickets[$ticketId] = [$ticketId, $match->matchId, $listing->blockId, $seatId, 'paid', $rng->uuid(), null, null, $created, $created];
            $this->sink->add('orders', [$orderId, $buyerId, $match->matchId, 'paid', 'resale', null, $listing->payment(), 'web', PgCopy::timestampTz($orderedAt), $created, $created]);
            $this->heldItems[] = [$orderId, $ticketId, $listing->ticketTypeId, $listing->price, $created, $created];

            $listing->soldAt = $orderedAt;
            $listing->buyerIndex = $buyer;
            $listing->buyerOrderId = $orderId;
            $listing->newTicketId = $ticketId;
            $listing->buyerSessionId = $sessionId;
            $listing->buyerDevice = $device;
            $listing->buyerViews = $k === 0 ? [['view_match', $viewMatchAt], ['view_resale', $viewResaleAt]] : [];
            $this->resaleBought[$buyer] = ($this->resaleBought[$buyer] ?? 0) + 1;
            $this->stats['sold']++;
            $this->stats[$overflow ? 'absorbed' : 'cannibalized']++;
        }

        return true;
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
     * @return list<array{0: int, 1: int, 2: int}> 作ったチケットの [ticket_id, ticket_type_id, price]
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
        bool $viewedResale = false,
    ): array {
        $userId = $this->crowd->userId($buyerIndex);
        $orderId = $this->sink->nextId('orders');
        $created = PgCopy::timestamp($orderedAt);
        $phaseId = $match->phaseAt($orderedAt)?->id;
        $total = 0;
        $createdItems = [];

        foreach ($items as [$seatId, $typeId, $price]) {
            $ticketId = $this->sink->nextId('tickets');
            $total += $price;
            $createdItems[] = [$ticketId, $typeId, $price];
            $this->heldTickets[$ticketId] = [$ticketId, $match->matchId, $block->id, $seatId, 'paid', $orderRng->uuid(), null, null, $created, $created];
            $this->heldItems[] = [$orderId, $ticketId, $typeId, $price, $created, $created];
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
        if ($viewedResale) {
            // 座席表を見た 30 秒後（カートの 1 分以上前）に出品の一覧を見た
            $this->emitEvent('view_resale', $userId, $sessionId, $device, ['match_id' => $match->matchId], $orderedAt - $seatmapOffset * 60 + 30);
        }
        $this->sink->checkpoint();

        return $createdItems;
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
