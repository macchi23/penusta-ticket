<?php

namespace App\Support\DemoData;

/** 試合前（r−1 節後）のペヌスタの順位表の抜粋。第 r 節の試合の需要に使う */
final readonly class MatchStanding
{
    /**
     * @param  string  $form  直近 5 試合（古い順）。W / D / L
     * @param  int|null  $cutoffPoints  降格圏の最上位（18 位）の勝点。ハーフでは null
     */
    public function __construct(
        public int $rank,
        public int $points,
        public string $form,
        public int $leaderPoints,
        public ?int $cutoffPoints,
    ) {}

    public function formPoints(): int
    {
        return substr_count($this->form, 'W') * 3 + substr_count($this->form, 'D');
    }
}
