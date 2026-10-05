<?php

namespace App\Providers;

use App\Http\Controllers\Api\DemoUserController;
use App\Models\User;
use App\Services\ActivityRecorder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 開発用ユーザー切替。本物の認証（sanctum 等）に移るまでの暫定 guard。
        Auth::viaRequest('demo-header', function (Request $request) {
            if (! DemoUserController::demoEnabled()) {
                return null;
            }

            $id = $request->header('X-Demo-User-Id');

            return ctype_digit((string) $id) ? User::find((int) $id) : null;
        });

        Gate::define('manage-allocations', fn (User $user) => $user->isAdmin());

        RateLimiter::for('cart', fn (Request $request) => Limit::perMinute((int) config('ticketing.cart_rate_per_minute'))
            ->by($request->user()?->id ?: $request->ip()));

        RateLimiter::for('events', function (Request $request) {
            $session = $request->header('X-Session-Id');
            $session = is_string($session) && preg_match(ActivityRecorder::SESSION_ID_PATTERN, $session)
                ? $session
                : 'unknown';

            return [
                Limit::perMinute((int) config('ticketing.events_rate_per_minute'))->by('s:'.$session),
                Limit::perMinute((int) config('ticketing.events_rate_per_minute_ip'))->by('ip:'.$request->ip()),
            ];
        });
    }
}
