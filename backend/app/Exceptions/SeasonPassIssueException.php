<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 年パス固定席の発行を続けられない不整合。黙って飛ばさず、呼び出し側（コマンド）に表に出す。
 */
class SeasonPassIssueException extends RuntimeException
{
    public static function blockNotHome(int $blockId, int $matchId, string $allocation): self
    {
        return new self("年パス固定席のブロック {$blockId} が試合 {$matchId} でホーム割当ではありません（{$allocation}）。");
    }

    public static function seatTaken(int $matchId, int $seatId): self
    {
        return new self("年パス固定席 {$seatId} に試合 {$matchId} の一般チケットが既にあります。");
    }

    public static function overCapacity(int $matchId, int $blockId, int $consumed, int $issuing, int $capacity): self
    {
        return new self("試合 {$matchId} の自由席ブロック {$blockId} が年パス発行で容量を超えます（消費 {$consumed} + 発行 {$issuing} > {$capacity}）。");
    }

    public static function inconsistentRow(int $seasonPassSeatId, string $reason): self
    {
        return new self("年パス固定席 {$seasonPassSeatId} の行が不整合です（{$reason}）。");
    }

    public static function ticketTypeMissing(): self
    {
        return new self("券種 'season_pass' がありません。");
    }
}
