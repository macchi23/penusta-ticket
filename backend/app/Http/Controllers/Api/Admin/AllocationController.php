<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAllocationsRequest;
use App\Models\FootballMatch;
use App\Services\Admin\AllocationChangeService;
use App\Services\SeatmapBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class AllocationController extends Controller
{
    public function __construct(
        private SeatmapBuilder $seatmaps,
        private AllocationChangeService $changes,
    ) {}

    public function show(FootballMatch $match): JsonResponse
    {
        return response()->json($this->payload($match));
    }

    public function update(UpdateAllocationsRequest $request, FootballMatch $match): JsonResponse
    {
        try {
            $result = $this->changes->apply($request->user(), $match, $request->validated('changes'));
        } catch (InvalidArgumentException) {
            // サービス側の入力検査。内部情報を返さないよう固定メッセージにする
            throw ValidationException::withMessages(['changes' => ['変更内容が正しくありません。']]);
        }

        return response()->json(['change_set_id' => $result->changeSetId] + $this->payload($match));
    }

    private function payload(FootballMatch $match): array
    {
        $now = CarbonImmutable::now();
        $counts = $this->changes->ticketCounts($match, $now);

        $blocks = $this->seatmaps->blocks($match)->map(function (array $block) use ($counts) {
            $tickets = $counts[$block['id']] ?? ['paid' => 0, 'held' => 0];

            return $block + ['tickets' => $tickets, 'locked' => $tickets['paid'] + $tickets['held'] > 0];
        })->values();

        $header = $this->seatmaps->header($match);

        return [
            'match' => $header['match'],
            'editable' => CarbonImmutable::parse($match->kickoff_at)->gt($now),
            'viewbox' => $header['viewbox'],
            'blocks' => $blocks,
        ];
    }
}
