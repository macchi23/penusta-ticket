<?php

namespace App\Console\Commands;

use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\MatchPrice;
use App\Models\Seat;
use App\Models\TicketType;
use App\Support\DemoData\DemoScenario;
use Carbon\Carbon;
use Database\Seeders\MatchSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class GenerateDemoData extends Command
{
    protected $signature = 'penusta:generate-demo-data';

    protected $description = 'ペヌスタチケット販売サイトの合成デモデータ（ユーザー・注文・チケット・行動ログ）を生成する';

    private const CHUNK = 500;

    /** @var array<int, array{age:int, gender:string, isSeasonPass:bool}> */
    private array $userMeta = [];

    /** @var array<int, int> 20-40代男性に重み付けしたユーザーIDプール（ゴール裏向け） */
    private array $goalBackPool = [];

    /** @var array<int, int> 40代以上に重み付けしたユーザーIDプール（メインスタンド向け） */
    private array $mainPool = [];

    /** @var array<int, int> 重み付けなしユーザーIDプール */
    private array $generalPool = [];

    public function handle(): int
    {
        mt_srand(42);

        $this->info('既存のデモデータをクリーンアップ中...');
        $this->cleanup();

        $this->info('ユーザーを生成中...');
        $userIds = $this->generateUsers(2000);

        $this->info('会員種別を生成中...');
        $this->generateMemberships($userIds);

        $this->buildWeightedPools($userIds);

        $this->info('試合ごとの注文・チケット・行動ログを生成中...');
        $ticketTypeIds = TicketType::pluck('id', 'code');
        // 暫定: 注文の作り直しまでは 2026-27 シーズンの試合（finished と販売途中）だけを対象にする
        $matches = FootballMatch::whereHas('season', fn ($q) => $q->where('name', '2026-27'))->orderBy('kickoff_at')->get();
        $blocks = Block::with('seatCategory', 'stand')->get()->keyBy('id');
        $seatsByBlock = Seat::select('id', 'block_id')->get()->groupBy('block_id');
        $allocationsByMatch = DB::table('match_block_allocations')->whereIn('match_id', $matches->modelKeys())->get()->groupBy('match_id');
        $priceMap = $this->buildPriceMap($matches->modelKeys());

        foreach ($matches as $match) {
            $this->generateOrSkip($match, $blocks, $seatsByBlock, $allocationsByMatch, $priceMap, $ticketTypeIds);
        }

        $this->info('完了しました。');

        return self::SUCCESS;
    }

    /**
     * 未販売の試合は何も生成しない。スキップ時は mt_rand も shuffle も呼ばないので、乱数列は試合の位置に依存せず変わらない。
     *
     * @param  Collection<int, Block>  $blocks
     * @param  Collection<int, Collection>  $seatsByBlock
     * @param  Collection<int, Collection>  $allocationsByMatch
     * @param  array<int, array<int, array<int, int>>>  $priceMap
     * @param  Collection<string, int>  $ticketTypeIds
     */
    public function generateOrSkip(FootballMatch $match, $blocks, $seatsByBlock, $allocationsByMatch, array $priceMap, $ticketTypeIds): void
    {
        if ($this->isUnsold($match)) {
            return;
        }

        $this->generateForMatch($match, $blocks, $seatsByBlock, $allocationsByMatch, $priceMap, $ticketTypeIds);
    }

    public function isUnsold(FootballMatch $match): bool
    {
        return ($match->attributes[MatchSeeder::UNSOLD_KEY] ?? false) === true;
    }

    private function cleanup(): void
    {
        DB::table('events')->delete();
        DB::table('order_items')->delete();
        DB::table('tickets')->delete();
        DB::table('orders')->delete();
        DB::table('memberships')->delete();
        DB::table('users')->where('email', '!=', 'test@example.com')->where('role', '!=', 'admin')->delete();
    }

    /**
     * @return array<int, int>
     */
    private function generateUsers(int $count): array
    {
        $ids = $this->reserveIds('users', $count);

        $surnames = ['佐藤', '鈴木', '高橋', '田中', '伊藤', '渡辺', '山本', '中村', '小林', '加藤',
            '吉田', '山田', '佐々木', '山口', '松本', '井上', '木村', '林', '斎藤', '清水',
            '森', '池田', '橋本', '阿部', '石川', '前田', '藤田', '後藤', '岡田', '長谷川'];
        $maleGiven = ['翔太', '大輔', '健太', '直樹', '拓也', '亮', '雄大', '和也', '智也', '賢一',
            '陽太', '海斗', '大和', '悠斗', '蓮', '颯太', '洋平', '啓太', '真也', '駿'];
        $femaleGiven = ['美咲', '愛', '結衣', '陽菜', 'さくら', '真央', 'optional', '彩', '千夏', '由美',
            '智子', '真由美', '恵', '美穂', '香織', '瞳', '奈々', '麻衣', '莉子', '葵'];
        // "optional" は誤りのため置換
        $femaleGiven[6] = '花';

        $prefectures = $this->prefectureWeights();
        $channels = ['web' => 45, 'sns' => 25, 'app' => 20, 'friend_invite' => 7, 'other' => 3];

        $rows = [];
        $now = now();
        $passwordHash = Hash::make('password');

        foreach ($ids as $i => $id) {
            $gender = $this->weightedPick(['male' => 55, 'female' => 45]);
            $age = $this->pickAge();
            $birthDate = Carbon::parse('2026-01-01')->subYears($age)->subDays(mt_rand(0, 364));
            $surname = $surnames[array_rand($surnames)];
            $given = $gender === 'male' ? $maleGiven[array_rand($maleGiven)] : $femaleGiven[array_rand($femaleGiven)];

            $this->userMeta[$id] = ['age' => $age, 'gender' => $gender, 'isSeasonPass' => false];

            $rows[] = [
                'id' => $id,
                'name' => $surname.' '.$given,
                'email' => 'user'.($i + 1).'@example.com',
                'email_verified_at' => $now,
                'password' => $passwordHash,
                'birth_date' => $birthDate->toDateString(),
                'gender' => $gender,
                'prefecture' => $this->weightedPick($prefectures),
                'attributes' => json_encode([
                    'acquisition_channel' => $this->weightedPick($channels),
                    'newsletter_opt_in' => mt_rand(1, 100) <= 35,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        return $ids;
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function generateMemberships(array $userIds): void
    {
        $rows = [];
        $now = now();

        foreach ($userIds as $id) {
            if (mt_rand(1, 100) <= 15) {
                $rows[] = [
                    'user_id' => $id,
                    'kind' => 'fanclub',
                    'valid_from' => '2025-04-01',
                    'valid_until' => '2026-03-31',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if (mt_rand(1, 100) <= 5) {
                $rows[] = [
                    'user_id' => $id,
                    'kind' => 'season_pass',
                    'valid_from' => '2026-02-01',
                    'valid_until' => '2026-12-31',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $this->userMeta[$id]['isSeasonPass'] = true;
            }
        }

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('memberships')->insert($chunk);
        }
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function buildWeightedPools(array $userIds): void
    {
        foreach ($userIds as $id) {
            $meta = $this->userMeta[$id];
            $goalBackWeight = ($meta['age'] >= 20 && $meta['age'] <= 40 ? 3 : 1) * ($meta['gender'] === 'male' ? 2 : 1);
            $mainWeight = $meta['age'] >= 40 ? 3 : 1;

            for ($i = 0; $i < $goalBackWeight; $i++) {
                $this->goalBackPool[] = $id;
            }
            for ($i = 0; $i < $mainWeight; $i++) {
                $this->mainPool[] = $id;
            }
            $this->generalPool[] = $id;
        }
    }

    /**
     * @param  array<int, int>  $matchIds
     * @return array<int, array<int, array<int, int>>> [match_id][seat_category_id][ticket_type_id] => price
     */
    private function buildPriceMap(array $matchIds): array
    {
        $map = [];
        foreach (MatchPrice::whereIn('match_id', $matchIds)->get() as $price) {
            $map[$price->match_id][$price->seat_category_id][$price->ticket_type_id] = $price->price;
        }

        return $map;
    }

    /**
     * @param  Collection<int, Block>  $blocks
     * @param  Collection<int, Collection>  $seatsByBlock
     * @param  Collection<int, Collection>  $allocationsByMatch
     * @param  array<int, array<int, array<int, int>>>  $priceMap
     * @param  Collection<string, int>  $ticketTypeIds
     */
    private function generateForMatch(
        FootballMatch $match,
        $blocks,
        $seatsByBlock,
        $allocationsByMatch,
        array $priceMap,
        $ticketTypeIds,
    ): void {
        $popularity = (int) ($match->attributes['opponent_popularity'] ?? 3);
        $rate = [1 => 0.50, 2 => 0.60, 3 => 0.72, 4 => 0.85, 5 => 0.97][$popularity] ?? 0.65;

        $allocations = $allocationsByMatch->get($match->id, collect());

        // 各注文の情報を蓄積し、あとでチケット/注文/注文明細/イベントをまとめて生成する。
        $pendingOrders = [];

        foreach ($allocations as $allocationRow) {
            if (! in_array($allocationRow->allocation, ['home', 'away'], true)) {
                continue; // buffer / closed には販売しない
            }

            $block = $blocks->get($allocationRow->block_id);
            if ($block === null) {
                continue;
            }

            $categoryName = $block->seatCategory->name;
            $categoryType = match (true) {
                $categoryName === 'ゴール裏' => 'goal_back',
                in_array($categoryName, ['カテゴリー1', 'カテゴリー2'], true) => 'main',
                default => 'general',
            };

            $factor = $allocationRow->allocation === 'away' ? 0.35 : 1.0;
            $targetSold = (int) min($block->capacity, round($block->capacity * $rate * $factor));

            if ($targetSold <= 0) {
                continue;
            }

            $seatPool = null;
            if ($block->seating_type === 'reserved') {
                $seatIds = ($seatsByBlock->get($block->id) ?? collect())->pluck('id')->all();
                shuffle($seatIds);
                $seatPool = array_slice($seatIds, 0, $targetSold);
            }

            $priceRow = $priceMap[$match->id][$block->seat_category_id] ?? [];

            $remaining = $targetSold;
            $seatCursor = 0;

            while ($remaining > 0) {
                $size = min($remaining, $this->pickOrderSize($categoryType));
                $remaining -= $size;

                $buyerId = $this->pickBuyer($categoryType);
                $isSeasonPassHolder = $this->userMeta[$buyerId]['isSeasonPass'] && $allocationRow->allocation === 'home';

                $items = [];
                for ($i = 0; $i < $size; $i++) {
                    $ticketTypeCode = $this->pickTicketTypeCode($categoryType, $isSeasonPassHolder);
                    $ticketTypeId = $ticketTypeIds[$ticketTypeCode];
                    $price = $priceRow[$ticketTypeId] ?? 0;
                    $seatId = $seatPool !== null ? ($seatPool[$seatCursor++] ?? null) : null;

                    $items[] = [
                        'seat_id' => $seatId,
                        'ticket_type_id' => $ticketTypeId,
                        'price' => $price,
                    ];
                }

                $pendingOrders[] = [
                    'block_id' => $block->id,
                    'block_code' => $block->code,
                    'buyer_id' => $buyerId,
                    'items' => $items,
                ];
            }
        }

        $this->persistOrders($match, $pendingOrders);
    }

    private function pickOrderSize(string $categoryType): int
    {
        return $categoryType === 'main'
            ? $this->weightedPick([1 => 10, 2 => 25, 3 => 30, 4 => 35])
            : $this->weightedPick([1 => 40, 2 => 30, 3 => 20, 4 => 10]);
    }

    private function pickBuyer(string $categoryType): int
    {
        $pool = match ($categoryType) {
            'goal_back' => $this->goalBackPool,
            'main' => $this->mainPool,
            default => $this->generalPool,
        };

        return $pool[mt_rand(0, count($pool) - 1)];
    }

    private function pickTicketTypeCode(string $categoryType, bool $isSeasonPassHolder): string
    {
        if ($isSeasonPassHolder && mt_rand(1, 100) <= 40) {
            return 'season_pass';
        }

        $youthChance = $categoryType === 'main' ? 25 : 8;
        if (mt_rand(1, 100) <= $youthChance) {
            return 'youth';
        }

        return 'adult';
    }

    /**
     * @param  array<int, array{block_id:int, block_code:string, buyer_id:int, items:array<int, array{seat_id:?int, ticket_type_id:int, price:int}>}>  $pendingOrders
     */
    private function persistOrders(FootballMatch $match, array $pendingOrders): void
    {
        $orderCount = count($pendingOrders);
        if ($orderCount === 0) {
            return;
        }

        $ticketCount = array_sum(array_map(fn ($o) => count($o['items']), $pendingOrders));

        $orderIds = $this->reserveIds('orders', $orderCount);
        $ticketIds = $this->reserveIds('tickets', $ticketCount);

        $kickoff = Carbon::parse($match->kickoff_at);
        $now = now();

        $orderRows = [];
        $ticketRows = [];
        $itemRows = [];
        $eventRows = [];

        $ticketCursor = 0;

        foreach ($pendingOrders as $index => $order) {
            $orderId = $orderIds[$index];
            $orderedAt = $this->pickOrderedAt($kickoff);
            $total = 0;
            $itemCount = count($order['items']);

            foreach ($order['items'] as $item) {
                $ticketId = $ticketIds[$ticketCursor++];
                $total += $item['price'];

                $ticketRows[] = [
                    'id' => $ticketId,
                    'match_id' => $match->id,
                    'block_id' => $order['block_id'],
                    'seat_id' => $item['seat_id'],
                    'status' => 'paid',
                    'qr_uuid' => (string) Str::uuid(),
                    'expires_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $itemRows[] = [
                    'order_id' => $orderId,
                    'ticket_id' => $ticketId,
                    'ticket_type_id' => $item['ticket_type_id'],
                    'price' => $item['price'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            $orderRows[] = [
                'id' => $orderId,
                'user_id' => $order['buyer_id'],
                'match_id' => $match->id,
                'status' => 'paid',
                'total' => $total,
                'channel' => $this->weightedPick(['web' => 70, 'app' => 20, 'convenience_store' => 10]),
                'ordered_at' => $orderedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            array_push(
                $eventRows,
                ...$this->buildPurchaseFunnelEvents($match, $order, $orderId, $orderedAt, $itemCount)
            );
        }

        foreach (array_chunk($orderRows, self::CHUNK) as $chunk) {
            DB::table('orders')->insert($chunk);
        }
        foreach (array_chunk($ticketRows, self::CHUNK) as $chunk) {
            DB::table('tickets')->insert($chunk);
        }
        foreach (array_chunk($itemRows, self::CHUNK) as $chunk) {
            DB::table('order_items')->insert($chunk);
        }

        // 離脱セッション（購入に至らない）を購入数の約3倍生成する。
        $dropoffCount = (int) round($orderCount * 3);
        for ($i = 0; $i < $dropoffCount; $i++) {
            array_push($eventRows, ...$this->buildDropoffEvents($match));
        }

        foreach (array_chunk($eventRows, self::CHUNK) as $chunk) {
            DB::table('events')->insert($chunk);
        }
    }

    private function pickOrderedAt(Carbon $kickoff): Carbon
    {
        if (mt_rand(1, 100) <= 60) {
            $daysBefore = mt_rand(0, 14);
        } else {
            $daysBefore = mt_rand(15, 90);
        }

        $orderedAt = $kickoff->copy()->subDays($daysBefore)->subMinutes(mt_rand(0, 1439));

        // 販売途中の試合（キックオフが AS_OF より後）の注文は AS_OF より前に限る
        $asOf = Carbon::instance(DemoScenario::asOf())->utc();
        if ($orderedAt->greaterThan($asOf)) {
            $orderedAt = $asOf->copy()->subMinutes(mt_rand(0, 1439));
        }

        return $orderedAt;
    }

    /**
     * @param  array{block_id:int, block_code:string, buyer_id:int, items:array}  $order
     * @return array<int, array<string, mixed>>
     */
    private function buildPurchaseFunnelEvents(FootballMatch $match, array $order, int $orderId, Carbon $orderedAt, int $itemCount): array
    {
        $sessionId = (string) Str::uuid();
        $device = $this->weightedPick(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $now = now();

        // 購入時刻から逆算し、各ステップを前ステップの 1 分以上後に置く。
        // mt_rand の呼び出し順と幅（max - min）は旧実装と同じ。
        $gapMatchToSeatmap = mt_rand(1, 18);   // 幅 17（旧 mt_rand(8, 25) と同じ）
        $gapSeatmapToCart = mt_rand(1, 12);    // 幅 11（旧 mt_rand(4, 15) と同じ）
        $gapCartToPurchase = mt_rand(1, 6);    // 幅 5（旧 mt_rand(1, 6) と同じ）
        $cartOffset = $gapCartToPurchase;
        $seatmapOffset = $cartOffset + $gapSeatmapToCart;
        $matchOffset = $seatmapOffset + $gapMatchToSeatmap;

        $steps = [
            ['type' => 'view_match', 'offsetMinutes' => $matchOffset, 'payload' => ['match_id' => $match->id]],
            ['type' => 'view_seatmap', 'offsetMinutes' => $seatmapOffset, 'payload' => ['match_id' => $match->id, 'block_code' => $order['block_code']]],
            ['type' => 'add_to_cart', 'offsetMinutes' => $cartOffset, 'payload' => ['match_id' => $match->id, 'block_code' => $order['block_code'], 'quantity' => $itemCount]],
            ['type' => 'purchase', 'offsetMinutes' => 0, 'payload' => ['match_id' => $match->id, 'order_id' => $orderId, 'quantity' => $itemCount]],
        ];

        $rows = [];
        foreach ($steps as $step) {
            $rows[] = [
                'event_type' => $step['type'],
                'user_id' => $order['buyer_id'],
                'session_id' => $sessionId,
                'device' => $device,
                'payload' => json_encode($step['payload']),
                'occurred_at' => $orderedAt->copy()->subMinutes($step['offsetMinutes']),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDropoffEvents(FootballMatch $match): array
    {
        $sessionId = (string) Str::uuid();
        $device = $this->weightedPick(['mobile' => 60, 'desktop' => 30, 'tablet' => 10]);
        $depth = $this->weightedPick([1 => 50, 2 => 30, 3 => 20]);
        $isKnownUser = mt_rand(1, 100) <= 30;
        $userId = $isKnownUser ? $this->generalPool[mt_rand(0, count($this->generalPool) - 1)] : null;

        $kickoff = Carbon::parse($match->kickoff_at);
        $baseTime = $this->pickOrderedAt($kickoff);

        $sequence = [
            'view_match' => ['match_id' => $match->id],
            'view_seatmap' => ['match_id' => $match->id],
            'add_to_cart' => ['match_id' => $match->id, 'quantity' => mt_rand(1, 4)],
        ];

        $types = array_slice(array_keys($sequence), 0, $depth);

        $rows = [];
        $now = now();
        $cursor = $baseTime->copy();
        foreach ($types as $type) {
            $cursor = $cursor->copy()->addMinutes(mt_rand(1, 8));
            $rows[] = [
                'event_type' => $type,
                'user_id' => $userId,
                'session_id' => $sessionId,
                'device' => $device,
                'payload' => json_encode($sequence[$type]),
                'occurred_at' => $cursor,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    private function pickAge(): int
    {
        $bracket = $this->weightedPick([
            '18-24' => 10,
            '25-34' => 22,
            '35-44' => 24,
            '45-54' => 20,
            '55-64' => 14,
            '65-75' => 10,
        ]);

        [$min, $max] = array_map('intval', explode('-', $bracket));

        return mt_rand($min, $max);
    }

    /**
     * @return array<string, int>
     */
    private function prefectureWeights(): array
    {
        $others = [
            '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
            '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
            '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
            '静岡県', '愛知県', '三重県', '鳥取県', '島根県', '岡山県', '広島県',
            '山口県', '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県',
            '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県',
        ];

        $weights = [
            '大阪府' => 25,
            '兵庫県' => 12,
            '京都府' => 8,
            '奈良県' => 6,
            '和歌山県' => 4,
            '滋賀県' => 4,
        ];

        foreach ($others as $prefecture) {
            $weights[$prefecture] = 1;
        }

        return $weights;
    }

    /**
     * @param  array<int|string, int>  $weights
     * @return int|string
     */
    private function weightedPick(array $weights)
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, $total);
        $cumulative = 0;

        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            if ($roll <= $cumulative) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * PostgreSQL のシーケンスから連番IDをまとめて確保する（大量insert時のFK整合性確保のため）。
     *
     * @return array<int, int>
     */
    private function reserveIds(string $table, int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $rows = DB::select(
            "select nextval('{$table}_id_seq') as id from generate_series(1, ?)",
            [$count]
        );

        return array_map(fn ($row) => (int) $row->id, $rows);
    }
}
