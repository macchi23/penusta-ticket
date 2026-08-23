<?php

use App\Http\Controllers\Api\BlockController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MatchController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [HealthController::class, 'index']);

Route::get('/matches', [MatchController::class, 'index']);
Route::get('/matches/{match}/seatmap', [MatchController::class, 'seatmap']);
Route::get('/matches/{match}/blocks/{block}/seats', [BlockController::class, 'seats']);
