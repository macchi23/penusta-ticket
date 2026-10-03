<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreActivityEventRequest;
use App\Models\User;
use App\Services\ActivityRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

class ActivityEventController extends Controller
{
    public function __construct(private ActivityRecorder $recorder) {}

    public function store(StoreActivityEventRequest $request): Response
    {
        $type = $request->validated('type');
        $matchId = (int) $request->validated('match_id');
        $blockCode = $request->validated('block_code');

        $payload = $type === 'view_seatmap' && $blockCode !== null
            ? ['match_id' => $matchId, 'block_code' => $blockCode]
            : ['match_id' => $matchId];

        // 任意認証: 取れたときだけ user_id に入れる
        $user = Auth::guard('demo')->user();

        $this->recorder->record(
            $type,
            $request,
            $user instanceof User ? $user : null,
            $payload,
            CarbonImmutable::now(),
        );

        return response()->noContent();
    }
}
