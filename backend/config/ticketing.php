<?php

return [
    // 仮押さえの保持時間（分）
    'hold_minutes' => (int) env('TICKETING_HOLD_MINUTES', 10),

    // カート操作のレート制限（1 ユーザーあたり、1 分間の回数）
    'cart_rate_per_minute' => (int) env('TICKETING_CART_RATE_PER_MINUTE', 60),

    // 1 注文あたりの最大枚数
    'max_tickets_per_order' => (int) env('TICKETING_MAX_TICKETS_PER_ORDER', 6),

    // 開発用ユーザー切替（なりすまし）。本番では必ず false
    'demo_auth_enabled' => (bool) env('DEMO_AUTH_ENABLED', false),

    // 決済モックの結果（success / declined）
    'mock_payment_outcome' => env('TICKETING_MOCK_PAYMENT_OUTCOME', 'success'),
];
