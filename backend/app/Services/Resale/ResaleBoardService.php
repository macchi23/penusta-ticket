<?php

namespace App\Services\Resale;

use App\Enums\ResaleListingStatus;
use App\Models\FootballMatch;
use App\Models\ResaleListing;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** 試合ごとのリセール一覧の読み取り。 */
class ResaleBoardService
{
    public function __construct(private ResaleWindow $window) {}

    /**
     * @return array{status: string, opens_at: ?CarbonImmutable, closes_at: ?CarbonImmutable, listings: Collection<int, ResaleListing>}
     */
    public function board(?User $viewer, FootballMatch $match): array
    {
        $now = CarbonImmutable::now();
        $status = $this->window->boardStatus($viewer, $match, $now);

        // 自分の出品は買えないので除く。締切を過ぎて未失効のものも出さない
        $listings = $status === 'open'
            ? ResaleListing::where('match_id', $match->id)
                ->where('status', ResaleListingStatus::Active->value)
                ->where('closes_at', '>', $now)
                ->where('seller_user_id', '!=', $viewer->id)
                // 仮押さえ中の出品は、自分が押さえた分も含めて出さない
                ->whereDoesntHave('holds', fn ($q) => $q->live($now))
                ->with(['ticket.seat', 'block.stand', 'block.seatCategory', 'ticketType'])
                ->get()
            : collect();

        return [
            'status' => $status,
            'opens_at' => $this->window->opensAt($match),
            'closes_at' => $match->status === 'on_sale' ? $this->window->closesAt($match) : null,
            'listings' => $listings,
        ];
    }
}
