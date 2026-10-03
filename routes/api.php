<?php

declare(strict_types=1);

use App\Http\ApiException;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));

Route::prefix('v1')->middleware([AuthenticateApiKey::class, 'throttle:api-key'])->group(function (): void {
    Route::get('/notifications/{id}', fn () => throw new ApiException(404, 'not_found', 'Notification not found.'));
});
