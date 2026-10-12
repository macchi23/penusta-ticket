<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ResaleBoardResource;
use App\Models\FootballMatch;
use App\Models\User;
use App\Services\Resale\ResaleBoardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResaleBoardController extends Controller
{
    public function __construct(private ResaleBoardService $boards) {}

    public function index(Request $request, FootballMatch $match): JsonResponse
    {
        // 任意認証。ゲストには login_required を返す
        $viewer = auth('demo')->user();

        return response()->json(
            (new ResaleBoardResource($this->boards->board($viewer instanceof User ? $viewer : null, $match)))->resolve($request),
        );
    }
}
