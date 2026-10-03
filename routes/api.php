<?php

declare(strict_types=1);

use App\Http\Controllers\NotificationController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::prefix('v1')->middleware([AuthenticateApiKey::class, 'throttle:api-key'])->group(function (): void {
    Route::post('/notifications', [NotificationController::class, 'store']);
    Route::get('/notifications/{id}', [NotificationController::class, 'show']);
});
