<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Support\DemoData\DemoScenario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class DemoUserResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'gender' => $this->gender,
            'birth_date' => $this->birth_date?->toDateString(),
            'prefecture' => $this->prefecture,
            'role' => $this->role->value,
            'memberships' => $this->activeMembershipKinds(),
        ];
    }

    /** AS_OF 時点で有効な会員種別だけ（7 シーズン分の履歴が全部出ないように） */
    private function activeMembershipKinds(): array
    {
        $today = DemoScenario::asOf()->format('Y-m-d');

        return $this->memberships
            ->filter(fn ($m) => $m->valid_from->toDateString() <= $today && $m->valid_until->toDateString() >= $today)
            ->pluck('kind')->unique()->values()->all();
    }
}
