<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Block;
use App\Models\FootballMatch;
use App\Models\Ticket;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class BlockController extends Controller
{
    public function seats(FootballMatch $match, Block $block): JsonResponse
    {
        if ($block->seating_type !== 'reserved') {
            abort(404);
        }

        $now = CarbonImmutable::now();

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
