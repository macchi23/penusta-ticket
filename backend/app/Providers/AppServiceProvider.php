<?php

namespace App\Providers;

use App\Http\Controllers\Api\DemoUserController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        //
    }
}
