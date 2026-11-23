<?php

use App\Http\Controllers\Api\AuthTokenController;
use App\Http\Controllers\Api\ChatAiController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthTokenController::class, 'store'])->middleware('throttle:api-login');

Route::post('/logout', [AuthTokenController::class, 'destroy'])->middleware('auth:sanctum');

Route::middleware(['auth:sanctum', 'throttle:chat-ai'])->group(function () {
    Route::post('/chat-ai', [ChatAiController::class, 'store']);
    Route::post('/chat-ai/stream', [ChatAiController::class, 'stream']);
});
