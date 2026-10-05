<?php

namespace App\Exceptions;

use Exception;

/**
 * 管理者の割当変更の失敗。HTTP への変換（render）は呼び出し側で行う。
 */
class AllocationChangeException extends Exception
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status,
        /** @var array<int, array{block_id: int, reason: string, current: string, tickets: array{paid: int, held: int}}> */
        public readonly array $conflicts = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<int, array{block_id: int, reason: string, current: string, tickets: array{paid: int, held: int}}> $conflicts */
    public static function conflict(array $conflicts): self
    {
        return new self('allocation_conflict', '割当を変更できないブロックがあります。', 409, $conflicts);
    }

    public static function matchLocked(): self
    {
        return new self('match_locked', 'キックオフ済みの試合の割当は変更できません。', 409);
    }

    public static function busy(): self
    {
        return new self('allocation_busy', '購入処理と競合しました。しばらくしてからやり直してください。', 409);
    }
}
