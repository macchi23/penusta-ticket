<?php

namespace App\Services\Purchase;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class DbHoldExpiry
{
    /**
     * 期限切れの held ticket を同じ席の再取得のために expired にする。
     */
    public function releaseStaleSeat(int $matchId, int $seatId, CarbonImmutable $now): void
    {
        DB::table('tickets')
            ->where('match_id', $matchId)
            ->where('seat_id', $seatId)
            ->where('status', 'held')
            ->where('expires_at', '<=', $now)
            ->update(['status' => 'expired', 'updated_at' => $now]);
    }

    /**
     * 呼び出し側でカート行をロックしておくこと。
     */
    public function expireCart(int $orderId, CarbonImmutable $now): void
    {
        DB::table('tickets')
            ->where('status', 'held')
            ->whereIn('id', DB::table('order_items')->where('order_id', $orderId)->select('ticket_id'))
            ->update(['status' => 'expired', 'updated_at' => $now]);

        DB::table('orders')
            ->where('id', $orderId)
            ->update(['status' => 'expired', 'updated_at' => $now]);
    }

    /**
     * 期限切れの pending カートを 1 件ずつ回収し、回収した件数を返す。
     */
    public function expireOverdue(CarbonImmutable $now): int
    {
        $count = 0;

        while (true) {
            $expired = DB::transaction(function () use ($now) {
                $row = DB::table('orders')
                    ->where('status', 'pending')
                    ->where('expires_at', '<=', $now)
                    ->orderBy('id')
                    ->limit(1)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->first(['id']);

                if ($row === null) {
                    return false;
                }

                $this->expireCart($row->id, $now);

                return true;
            }, 3);

            if (! $expired) {
                return $count;
            }
            $count++;
        }
    }
}
