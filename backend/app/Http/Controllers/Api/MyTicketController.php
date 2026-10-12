<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyTicketResource;
use App\Http\Resources\TicketDetailResource;
use App\Services\Resale\MyTicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MyTicketController extends Controller
{
    public function __construct(private MyTicketService $tickets) {}

    public function index(Request $request): JsonResponse
    {
        $scope = $request->validate(['scope' => ['nullable', 'in:upcoming,past']])['scope'] ?? 'upcoming';

        return response()->json([
            'data' => $this->tickets->list($request->user(), $scope)
                ->map(fn ($view) => (new MyTicketResource($view))->resolve($request))
                ->all(),
        ]);
    }

    public function show(Request $request, int $ticket): JsonResponse
    {
        // 他人の券・存在しない券・paid/resold 以外は区別せず 404
        $view = $this->tickets->find($request->user(), $ticket);
        abort_if($view === null, 404);

        return response()->json(['ticket' => (new TicketDetailResource($view))->resolve($request)]);
    }
}
