<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Exceptions\PurchaseException;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Ticket;
use App\Services\Purchase\SaleGate;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BlockController extends Controller
{
    public function __construct(private SaleGate $gate) {}

    public function seats(Request $request, FootballMatch $match, Block $block): JsonResponse
    {
        if ($block->seating_type !== 'reserved') {
            abort(404);
        }

        $allocation = $match->blockAllocations()->where('block_id', $block->id)->value('allocation');
        if (! in_array($allocation, ['home', 'away'], true)) {
            abort(404);
        }

        $now = CarbonImmutable::now();

        // 購入できない人には席ごとの状態を見せない（ブロック分けだけ見える）
        if (! $this->gate->canViewSeats(auth('demo')->user(), $match, $now)) {
            throw PurchaseException::seatsHidden();
        }

        $block->load('stand');

        $seats = $block->seats()
            ->orderBy('row_label')
            ->orderBy('number')
            ->get();

        $ticketBySeat = Ticket::where('match_id', $match->id)
            ->where('block_id', $block->id)
            ->whereNotNull('seat_id')
            ->consumingInventory($now)
            ->get()
            ->keyBy('seat_id');

        $rows = $seats->groupBy('row_label')->map(function ($seatsInRow, $rowLabel) use ($ticketBySeat) {
            return [
                'row' => $rowLabel,
                'seats' => $seatsInRow->map(function ($seat) use ($ticketBySeat) {
                    $ticket = $ticketBySeat->get($seat->id);
                    $status = 'available';

                    if ($ticket !== null) {
                        $status = $ticket->status === TicketStatus::Held ? 'held' : 'sold';
                    }

                    return [
                        'id' => $seat->id,
                        'number' => $seat->number,
                        'status' => $status,
                    ];
                })->values(),
            ];
        })->values();

        return response()->json([
            'block' => [
                'id' => $block->id,
                'code' => $block->code,
                'stand' => $block->stand->name,
            ],
            'rows' => $rows,
        ]);
    }
}
