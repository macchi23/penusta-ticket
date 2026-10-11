<?php

namespace App\Services\Purchase;

use App\Models\Membership;
use App\Models\User;
use Carbon\CarbonImmutable;

class MembershipEligibility
{
    /**
     * $eligibility が null なら誰でも可。非 null ならその kind の会員資格が JST の日付で有効なこと。
     */
    public function isEligible(User $user, ?string $eligibility, CarbonImmutable $now): bool
    {
        if ($eligibility === null) {
            return true;
        }

        $today = $now->setTimezone('Asia/Tokyo')->toDateString();

        return Membership::where('user_id', $user->id)
            ->where('kind', $eligibility)
            ->where('valid_from', '<=', $today)
            ->where('valid_until', '>=', $today)
            ->exists();
    }
}
