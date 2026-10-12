<?php

namespace App\Support\DemoData;

/**
 * リセールの出品率・価格・買われ方の係数。ユーザーの実感に基づく推測値なので、調整するときはここだけを触る。
 * 乱数は呼び出し側が渡す SeededRandom だけを使う。
 */
final class ResaleModel
{
    /** 1 試合あたりの出品率: 年パス券 = 基礎 + 加算 × boost、通常購入券も同様（boost は 0〜1） */
    public const PASS_LISTING_BASE = 0.08;

    public const PASS_LISTING_BOOST = 0.07;

    public const PURCHASED_LISTING_BASE = 0.01;

    public const PURCHASED_LISTING_BOOST = 0.02;

    /** 出品率に試合ごとに掛けるぶれ（1 ± この値） */
    public const RATE_JITTER = 0.15;

    /** boost の内訳: 平日夜・雨雪・不人気相手（OPPONENT の 1.0 との差に掛ける） */
    public const BOOST_WEEKDAY = 0.30;

    public const BOOST_BAD_WEATHER = 0.30;

    public const BOOST_PER_UNPOPULARITY = 2.5;

    /** 締切までの残り時間の割合 = u ^ この値（大きいほど締切寄り） */
    public const LISTING_LEAD_SKEW = 2.5;

    /** 通常購入券は、購入からこの秒数以上あとに出品する */
    public const MIN_GAP_AFTER_PURCHASE = 600;

    /** 定価のまま出す割合。出品が買える状態になった時点で、ブロックの一次の残りが販売可能席数のこの割合以下なら完売間近として高い方を使う */
    public const FULL_PRICE_SHARE = 0.06;

    public const FULL_PRICE_SHARE_SOLD_OUT = 0.35;

    public const NEAR_SOLD_OUT_SHARE = 0.05;

    /** 値引率の平均 = 基礎 + 加算 × 低需要度（0〜1）。実際の値引率は平均 × [SPREAD_MIN, SPREAD_MAX] */
    public const DISCOUNT_BASE = 0.14;

    public const DISCOUNT_PER_LOW_DEMAND = 0.26;

    public const DISCOUNT_SPREAD_MIN = 0.6;

    public const DISCOUNT_SPREAD_MAX = 1.4;

    /** 低需要度 = (LOW_DEMAND_FROM − 需要指数) / LOW_DEMAND_RANGE を 0〜1 に切る */
    public const LOW_DEMAND_FROM = 1.05;

    public const LOW_DEMAND_RANGE = 0.45;

    /** 一次の買い手が出品側を選ぶ確率 = BASE + 値引率 × PER_DISCOUNT + (需要指数 − 0.9) × PER_DEMAND を [MIN, MAX] に切る */
    public const BUY_BASE = 0.65;

    public const BUY_PER_DISCOUNT = 0.5;

    public const BUY_PER_DEMAND = 1.2;

    public const BUY_MIN = 0.05;

    public const BUY_MAX = 0.95;

    /** 完売ブロックであふれた買い手が出品を買う確率 */
    public const OVERFLOW_BUY = 0.60;

    /** 出品があるときに、買い手が一次の購入の前に view_resale を残す割合 */
    public const VIEW_SHARE = 0.50;

    /** 売れ残った出品のうち、締切の前に取り下げて値下げして出し直す割合。時刻は締切の [MAX, MIN] 秒前、値下げ幅は現在の価格の [DROP_MIN, DROP_MAX]、取り下げから出し直しまでの秒数 */
    public const RELIST_SHARE = 0.30;

    public const RELIST_BEFORE_CLOSE_MAX = 3 * 86400;

    public const RELIST_BEFORE_CLOSE_MIN = 6 * 3600;

    public const RELIST_DROP_MIN = 0.10;

    public const RELIST_DROP_MAX = 0.25;

    public const RELIST_DELAY = 120;

    /** 指定席で 2 枚以上の注文が、隣り合わない席でもよいとして 1 枚ずつ出品を買う割合 */
    public const SCATTER_BUY = 0.60;

    /** 自由席で複数枚を出品から買うとき、1 枚ごとの注文の間隔（秒） */
    public const PURCHASE_GAP_SECONDS = 20;

    private readonly ScenarioDemandModel $scenario;

    public function __construct()
    {
        $this->scenario = new ScenarioDemandModel;
    }

    /** 出品が増える条件（平日夜・雨雪・不人気相手）の強さ。0〜1 */
    public function boost(MatchContext $match): float
    {
        $weekday = $this->scenario->day($match) < 0.9 ? self::BOOST_WEEKDAY : 0.0;
        $badWeather = in_array($match->attributes['weather'] ?? '', [WeatherCondition::Rainy->value, WeatherCondition::Snowy->value], true) ? self::BOOST_BAD_WEATHER : 0.0;
        $unpopular = max(0.0, 1.0 - (ScenarioDemandModel::OPPONENT[$match->popularity()] ?? 1.0)) * self::BOOST_PER_UNPOPULARITY;

        return min(1.0, $weekday + $badWeather + $unpopular);
    }

    /** @return array{0: float, 1: float} [年パス券の出品率, 通常購入券の出品率]（試合ごと） */
    public function listingRates(float $boost, SeededRandom $rng): array
    {
        $jitter = 1.0 + self::RATE_JITTER * (2.0 * $rng->float() - 1.0);

        return [
            (self::PASS_LISTING_BASE + self::PASS_LISTING_BOOST * $boost) * $jitter,
            (self::PURCHASED_LISTING_BASE + self::PURCHASED_LISTING_BOOST * $boost) * $jitter,
        ];
    }

    /** 出品時刻。[$start, $closesAt) の中で、締切に近いほど密 */
    public function listedAt(int $start, int $closesAt, SeededRandom $rng): int
    {
        $lead = (int) floor(($closesAt - $start) * $rng->float() ** self::LISTING_LEAD_SKEW);

        return max($start, $closesAt - 1 - $lead);
    }

    /** @return array{0: float, 1: float} 価格を決める乱数 [定価寄りにするか, 値引率のぶれ]。価格は確定の時点まで使わないので先に引いておく */
    public function priceDraws(SeededRandom $rng): array
    {
        return [$rng->float(), $rng->float()];
    }

    /** @return array{0: float, 1: float, 2: float} 値下げの再出品の乱数 [するか, 時刻, 値下げ幅] */
    public function relistDraws(SeededRandom $rng): array
    {
        return [$rng->float(), $rng->float(), $rng->float()];
    }

    /** 出品価格（min_price 以上、定価以下）。$nearSoldOut = 一次の残りがほぼ無い */
    public function price(int $face, bool $nearSoldOut, float $demandIndex, array $draws): int
    {
        $min = (int) config('ticketing.resale.min_price');
        $fullShare = $nearSoldOut ? self::FULL_PRICE_SHARE_SOLD_OUT : self::FULL_PRICE_SHARE;
        if ($draws[0] < $fullShare) {
            $price = $face;
        } else {
            $low = min(1.0, max(0.0, (self::LOW_DEMAND_FROM - $demandIndex) / self::LOW_DEMAND_RANGE));
            $mean = self::DISCOUNT_BASE + self::DISCOUNT_PER_LOW_DEMAND * $low;
            $discount = $mean * (self::DISCOUNT_SPREAD_MIN + (self::DISCOUNT_SPREAD_MAX - self::DISCOUNT_SPREAD_MIN) * $draws[1]);
            $price = (int) (floor($face * (1.0 - $discount) / 10) * 10);
        }

        return min($face, max($min, $price));
    }

    /** 値下げの再出品の時刻。出さない・出品から近すぎるときは null */
    public function relistAt(DemoListing $listing, int $from): ?int
    {
        [$share, $time] = $listing->relistDraws;
        if ($share >= self::RELIST_SHARE) {
            return null;
        }
        $at = $listing->closesAt - (int) (self::RELIST_BEFORE_CLOSE_MIN + (self::RELIST_BEFORE_CLOSE_MAX - self::RELIST_BEFORE_CLOSE_MIN) * $time);

        return $at > $from + 3600 ? $at : null;
    }

    /** 値下げ後の価格。下げられなければ null */
    public function relistPrice(int $price, float $draw): ?int
    {
        $min = (int) config('ticketing.resale.min_price');
        $drop = self::RELIST_DROP_MIN + (self::RELIST_DROP_MAX - self::RELIST_DROP_MIN) * $draw;
        $next = max($min, (int) (floor($price * (1.0 - $drop) / 10) * 10));

        return $next < $price ? $next : null;
    }

    public function buyProbability(float $discount, float $demandIndex): float
    {
        $p = self::BUY_BASE + self::BUY_PER_DISCOUNT * $discount + self::BUY_PER_DEMAND * ($demandIndex - 0.9);

        return min(self::BUY_MAX, max(self::BUY_MIN, $p));
    }
}
