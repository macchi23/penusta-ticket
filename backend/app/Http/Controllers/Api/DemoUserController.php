<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DemoUserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DemoUserController extends Controller
{
    /**
     * 開発用なりすましを許可するか（明示フラグ かつ local / testing）。
     */
    public static function demoEnabled(): bool
    {
        return (bool) config('ticketing.demo_auth_enabled') && app()->environment(['local', 'testing']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('memberships');

        return response()->json(['user' => (new DemoUserResource($user))->resolve()]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(self::demoEnabled(), 404);

        $limit = min(max((int) $request->query('limit', 50), 1), 200);
        $q = trim((string) $request->query('q', ''));

        $users = User::with('memberships')
            ->when($q !== '', function ($query) use ($q) {
                $escaped = addcslashes($q, '\\%_');
                $query->where('name', 'ilike', "%{$escaped}%");
            })
            ->orderByRaw("case when role = 'admin' then 0 else 1 end")
            ->orderBy('id')
            ->limit($limit)
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => (new DemoUserResource($u))->resolve())->all(),
        ]);
    }
}
