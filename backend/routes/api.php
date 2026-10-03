<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\DemoUserController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\MatchPriceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::get('/matches', [MatchController::class, 'index']);
Route::get('/matches/{match}/seatmap', [MatchController::class, 'seatmap']);
Route::get('/matches/{match}/blocks/{block}/seats', [BlockController::class, 'seats']);
Route::get('/matches/{match}/prices', [MatchPriceController::class, 'index']);

Route::middleware(['auth:demo', 'throttle:cart'])->prefix('/matches/{match}/cart')->group(function () {
    Route::get('/', [CartController::class, 'show']);
    Route::post('/items', [CartController::class, 'store']);
    Route::patch('/items/{item}', [CartController::class, 'update'])->whereNumber('item');
    Route::delete('/items/{item}', [CartController::class, 'destroy'])->whereNumber('item');
});

Route::get('/me', [DemoUserController::class, 'me'])->middleware('auth:demo');
if (DemoUserController::demoEnabled()) {
    Route::get('/demo/users', [DemoUserController::class, 'index']);
}
