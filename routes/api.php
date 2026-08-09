<?php

use App\Http\Controllers\Mie\CommandCenterController;
use App\Http\Controllers\Mie\MarketScanController;
use App\Http\Controllers\ModuleAccess\PhoneVerificationController;
use App\Http\Controllers\ModuleAccess\PinController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['status' => 'ok']));

/*
|--------------------------------------------------------------------------
| Market Intelligence & Exchange — Module Access Gate (spec section 1.1)
|--------------------------------------------------------------------------
|
| Guarded only by standard login (auth:sanctum). These endpoints ARE the
| second-factor gate, so they cannot themselves require the gate to already
| be passed — that would be circular.
*/
Route::middleware('auth:sanctum')->prefix('module-access')->group(function () {
    Route::post('/phone/request-otp', [PhoneVerificationController::class, 'requestOtp'])
        ->middleware('throttle:6,1'); // basic anti-SMS-bombing throttle, separate from the PIN lockout policy
    Route::post('/phone/verify-otp', [PhoneVerificationController::class, 'verifyOtp']);
    Route::post('/pin/set', [PinController::class, 'set']);
    Route::post('/pin/verify', [PinController::class, 'verify']);
    Route::post('/pin/reset', [PinController::class, 'reset']);
});

/*
|--------------------------------------------------------------------------
| Market Intelligence & Exchange — module routes (gated per section 1.1)
|--------------------------------------------------------------------------
*/
Route::middleware(['auth:sanctum', 'module.access'])->prefix('mie')->group(function () {
    Route::get('/ping', fn () => response()->json(['status' => 'module access granted']));

    // Section 3.1 — Market Command Center
    Route::get('/command-center', CommandCenterController::class);

    // Section 3.2 — Global Market Scan
    Route::get('/market-scan', [MarketScanController::class, 'index']);
});
