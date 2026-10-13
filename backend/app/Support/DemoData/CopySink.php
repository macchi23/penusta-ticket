<?php

namespace App\Support\DemoData;

use PDO;

/** COPY 行をテーブルごとに溜めてまとめて書く。外部キーの順（親が先）で書き出す */
final class CopySink
{
    public const COLUMNS = [
        'users' => ['id', 'name', 'email', 'email_verified_at', 'password', 'birth_date', 'gender', 'prefecture', 'attributes', 'created_at', 'updated_at'],
        'memberships' => ['user_id', 'kind', 'valid_from', 'valid_until', 'created_at', 'updated_at'],
        'season_pass_seats' => ['id', 'season_id', 'user_id', 'block_id', 'seat_id', 'created_at', 'updated_at'],
        'orders' => ['id', 'user_id', 'match_id', 'status', 'kind', 'sale_phase_id', 'total', 'channel', 'ordered_at', 'created_at', 'updated_at'],
        'tickets' => ['id', 'match_id', 'block_id', 'seat_id', 'status', 'qr_uuid', 'expires_at', 'season_pass_seat_id', 'created_at', 'updated_at'],
        'order_items' => ['order_id', 'ticket_id', 'ticket_type_id', 'price', 'created_at', 'updated_at'],
        'resale_listings' => ['id', 'ticket_id', 'seller_user_id', 'match_id', 'block_id', 'ticket_type_id', 'face_price', 'price', 'seller_fee', 'buyer_fee', 'payout', 'status', 'closes_at', 'buyer_order_id', 'new_ticket_id', 'created_at', 'updated_at'],
        'resale_holds' => ['id', 'listing_id', 'user_id', 'match_id', 'status', 'expires_at', 'order_id', 'created_at', 'updated_at'],
        'events' => ['event_type', 'user_id', 'session_id', 'device', 'payload', 'occurred_at', 'created_at', 'updated_at'],
    ];

    /** @var array<string, list<string>> */
    private array $buffers = [];

    /** @var array<string, int> */
    private array $nextIds = ['orders' => 1, 'tickets' => 1, 'season_pass_seats' => 1, 'resale_listings' => 1, 'resale_holds' => 1];

    /** @var array<string, int> */
    private array $counts = [];

    private int $buffered = 0;

    public function __construct(private readonly PDO $pdo, private readonly int $flushAt = 50_000)
    {
        foreach (self::COLUMNS as $table => $_) {
            $this->buffers[$table] = [];
            $this->counts[$table] = 0;
        }
    }

    /** @param  list<int|float|string|bool|null>  $values */
    public function add(string $table, array $values): void
    {
        $this->buffers[$table][] = PgCopy::line($values);
        $this->counts[$table]++;
        $this->buffered++;
    }

    /** 溜まっていれば書き出す。行の組（注文と明細など）の途中で書かないよう、呼び出し側が区切りで呼ぶ */
    public function checkpoint(): void
    {
        if ($this->buffered >= $this->flushAt) {
            $this->flush();
        }
    }

    /** 明示 id で書くテーブルの次の id を返す */
    public function nextId(string $table): int
    {
        return $this->nextIds[$table]++;
    }

    public function count(string $table): int
    {
        return $this->counts[$table];
    }

    public function flush(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            PgCopy::copy($this->pdo, $table, $columns, $this->buffers[$table]);
            $this->buffers[$table] = [];
        }
        $this->buffered = 0;
    }
}
