<?php

namespace App\Support\DemoData;

/** 合成ユーザーの属性（メモリ上の構造。添字 i のユーザーの users.id は idOffset + i + 1） */
final class DemoPopulation
{
    /** @var list<int> 入会日時（epoch 秒） */
    public array $joinedAt = [];

    /** @var list<int> 退会した年。退会しないなら 9999（その年の末まで有効） */
    public array $leaveYear = [];

    /** @var list<int> */
    public array $age = [];

    /** @var list<bool> */
    public array $male = [];

    /** @var list<float> 熱心さ（対数正規分布）。保存しない */
    public array $passion = [];

    public function __construct(public readonly int $count) {}

    public function isActiveIn(int $index, SeasonSpan $span): bool
    {
        return $this->joinedAt[$index] <= $span->endEpoch()
            && $this->leaveYear[$index] >= $span->definition->startYear;
    }
}
