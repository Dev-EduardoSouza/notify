<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use App\Http\Controllers\MessageController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);

Route::prefix('v1')->middleware([AuthenticateApiKey::class, 'throttle:api-key'])->group(function (): void {
    Route::post('/messages', [MessageController::class, 'store']);
});
