<?php

use App\Http\Controllers\Api\AuthTokenController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthTokenController::class, 'store'])->middleware('throttle:api-login');

Route::post('/logout', [AuthTokenController::class, 'destroy'])->middleware('auth:sanctum');
