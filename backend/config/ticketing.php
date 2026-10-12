<?php

return [
    // 仮押さえの保持時間（分）
    'hold_minutes' => (int) env('TICKETING_HOLD_MINUTES', 10),

    // カート操作のレート制限（1 ユーザーあたり、1 分間の回数）
    'admin_update_rate_per_minute' => (int) env('TICKETING_ADMIN_UPDATE_RATE_PER_MINUTE', 30),
    'admin_read_rate_per_minute' => (int) env('TICKETING_ADMIN_READ_RATE_PER_MINUTE', 120),
    'cart_rate_per_minute' => (int) env('TICKETING_CART_RATE_PER_MINUTE', 60),

    // 閲覧イベント記録のレート制限（IP + セッションあたり、1 分間の回数）
    'events_rate_per_minute' => (int) env('TICKETING_EVENTS_RATE_PER_MINUTE', 120),

    // 閲覧イベント記録の IP 単位のレート制限（1 分間の回数）。Next 経由では全利用者が同じ IP になるため高め
    'events_rate_per_minute_ip' => (int) env('TICKETING_EVENTS_RATE_PER_MINUTE_IP', 600),

    // 1 注文あたりの最大枚数
    'max_tickets_per_order' => (int) env('TICKETING_MAX_TICKETS_PER_ORDER', 6),

    // リセール（チケットの再販売）
    'resale' => [
        // 出品価格の下限（円）
        'min_price' => (int) env('TICKETING_RESALE_MIN_PRICE', 500),
        // 出品者の手数料率、振込手数料（円）、買い手の手数料（円、1 枚あたり）
        'seller_fee_rate' => (float) env('TICKETING_RESALE_SELLER_FEE_RATE', 0.10),
        'transfer_fee' => (int) env('TICKETING_RESALE_TRANSFER_FEE', 330),
        'buyer_fee' => (int) env('TICKETING_RESALE_BUYER_FEE', 220),
        // キックオフの何分前にリセールを締め切るか
        'close_minutes_before_kickoff' => (int) env('TICKETING_RESALE_CLOSE_MINUTES', 150),
        // 年パス券の定価 = 単券の大人価格 × (1 - この率)
        'season_pass_discount_rate' => (float) env('TICKETING_RESALE_SEASON_PASS_DISCOUNT_RATE', 0.25),
    ],

    // 開発用ユーザー切替（なりすまし）。本番では必ず false
    'demo_auth_enabled' => (bool) env('DEMO_AUTH_ENABLED', false),

    // 管理者の割当変更が allocation 行のロックを待つ上限（ミリ秒）
    'admin_lock_timeout_ms' => (int) env('TICKETING_ADMIN_LOCK_TIMEOUT_MS', 3000),

    // 決済モックの結果（success / declined）
    'mock_payment_outcome' => env('TICKETING_MOCK_PAYMENT_OUTCOME', 'success'),
];
