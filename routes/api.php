<?php

use App\Http\Controllers\Mie\BuyerController;
use App\Http\Controllers\Mie\CommandCenterController;
use App\Http\Controllers\Mie\ContractController;
use App\Http\Controllers\Mie\DealController;
use App\Http\Controllers\Mie\MarketScanController;
use App\Http\Controllers\Mie\NegotiationController;
use App\Http\Controllers\Mie\RequirementController;
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

    // Section 3.3 — Buyer Intelligence Profiles
    Route::get('/buyers', [BuyerController::class, 'index']);
    Route::get('/buyers/{id}', [BuyerController::class, 'show']);

    // Section 3.4 — Requirements Exchange
    Route::prefix('requirements')->group(function () {
        Route::get('/{id}', [RequirementController::class, 'show']);
        Route::post('/{id}/match', [RequirementController::class, 'match']);
        Route::post('/{id}/message', [RequirementController::class, 'message']);

        // Financial commitment point — requires a freshly-verified PIN, not just module access.
        Route::post('/{id}/offer', [RequirementController::class, 'offer'])
            ->middleware('module.access.fresh-pin');

        Route::post('/{id}/negotiate', [RequirementController::class, 'negotiate']);
        Route::post('/{id}/save', [RequirementController::class, 'save']);
        Route::post('/{id}/share', [RequirementController::class, 'share']);
    });

    // Section 3.10 — Deals Workspace
    Route::post('/negotiations/{id}/convert-to-deal', [NegotiationController::class, 'convertToDeal']);

    Route::prefix('deals')->group(function () {
        Route::get('/', [DealController::class, 'index']);
        Route::get('/{id}', [DealController::class, 'show']);
        Route::patch('/{id}/stage', [DealController::class, 'updateStage']);

        // Section 3.11 — the contract-signing moment; requires a freshly-verified PIN.
        Route::post('/{id}/contract', [ContractController::class, 'store'])
            ->middleware('module.access.fresh-pin');
    });

    // Section 3.11 — Contract Center
    Route::prefix('contracts')->group(function () {
        Route::get('/', [ContractController::class, 'index']);
        Route::get('/{id}', [ContractController::class, 'show']);
    });
});
