<?php

use App\Http\Controllers\Api\ActivityEventController;
use App\Http\Controllers\Api\Admin\AllocationController;
use App\Http\Controllers\Api\Admin\MatchController as AdminMatchController;
use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DemoUserController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchPriceController;
use App\Http\Controllers\Api\MyTicketController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ResaleBoardController;
use App\Http\Controllers\Api\ResaleCartController;
use App\Http\Controllers\Api\TicketListingController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::get('/matches', [MatchController::class, 'index']);
Route::get('/matches/{match}/seatmap', [MatchController::class, 'seatmap']);
Route::get('/matches/{match}/blocks/{block}/seats', [BlockController::class, 'seats']);
Route::get('/matches/{match}/prices', [MatchPriceController::class, 'index']);

// GET は画面の再描画ごとに呼ばれるため throttle しない
Route::get('/matches/{match}/cart', [CartController::class, 'show'])->middleware('auth:demo');
Route::middleware(['auth:demo', 'throttle:cart'])->prefix('/matches/{match}/cart')->group(function () {
    Route::post('/items', [CartController::class, 'store']);
    Route::put('/blocks/{block}/items', [CartController::class, 'setUnreservedQuantities']);
    Route::patch('/items/{item}', [CartController::class, 'update'])->whereNumber('item');
    Route::delete('/items/{item}', [CartController::class, 'destroy'])->whereNumber('item');
});

Route::post('/orders/{order}/checkout', [CheckoutController::class, 'store'])
    ->middleware(['auth:demo', 'throttle:cart'])->whereNumber('order');
Route::get('/orders/{order}', [OrderController::class, 'show'])
    ->middleware('auth:demo')->whereNumber('order');

// マイチケットとリセール。他人の券は 404。一覧(GET)は throttle しない
Route::get('/me/tickets', [MyTicketController::class, 'index'])->middleware('auth:demo');
Route::get('/tickets/{ticket}', [MyTicketController::class, 'show'])->middleware('auth:demo')->whereNumber('ticket');
Route::middleware(['auth:demo', 'throttle:cart'])->group(function () {
    Route::post('/tickets/{ticket}/listing', [TicketListingController::class, 'store'])->whereNumber('ticket');
    Route::delete('/tickets/{ticket}/listing', [TicketListingController::class, 'destroy'])->whereNumber('ticket');
    Route::post('/resale-listings/{listing}/hold', [ResaleCartController::class, 'hold'])->whereNumber('listing');
    Route::delete('/resale-holds/{hold}', [ResaleCartController::class, 'release'])->whereNumber('hold');
    Route::post('/resale-holds/{hold}/checkout', [ResaleCartController::class, 'checkout'])->whereNumber('hold');
});
Route::get('/matches/{match}/resale-cart', [ResaleCartController::class, 'show'])->middleware('auth:demo');
// 任意認証(ゲストには login_required を返す)
Route::get('/matches/{match}/resale-listings', [ResaleBoardController::class, 'index']);

// 任意認証（ログインしていれば user_id を記録する）
Route::post('/events', [ActivityEventController::class, 'store'])->middleware('throttle:events');

Route::get('/me', [DemoUserController::class, 'me'])->middleware('auth:demo');
if (DemoUserController::demoEnabled()) {
    Route::get('/demo/users', [DemoUserController::class, 'index']);
}

// 管理者向け。認証方式を差し替えるときはここのミドルウェアだけを直す
Route::middleware(['auth:demo', 'can:manage-allocations'])->prefix('admin')->group(function () {
    Route::middleware('throttle:admin-read')->group(function () {
        Route::get('/matches', [AdminMatchController::class, 'index']);
        Route::get('/matches/{match}/allocations', [AllocationController::class, 'show']);
    });
    Route::patch('/matches/{match}/allocations', [AllocationController::class, 'update'])->middleware('throttle:admin-write');
});
