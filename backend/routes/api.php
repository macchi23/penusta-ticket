<?php

use App\Http\Controllers\Api\BlockController;
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

Route::get('/me', [DemoUserController::class, 'me'])->middleware('auth:demo');
if (DemoUserController::demoEnabled()) {
    Route::get('/demo/users', [DemoUserController::class, 'index']);
}
