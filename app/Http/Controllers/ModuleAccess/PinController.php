<?php

namespace App\Http\Controllers\ModuleAccess;

use App\Http\Controllers\Controller;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PinController extends Controller
{
    public function __construct(private readonly ModuleAccessService $moduleAccess) {}

    public function set(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->phoneVerifiedThisSession($request)) {
            return $this->phoneVerificationRequiredResponse();
        }

        if ($user->pin_hash) {
            return response()->json([
                'message' => 'A PIN already exists for this module. Use the reset endpoint to change it.',
                'code' => 'pin_already_set',
            ], 409);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed'],
        ]);

        $this->moduleAccess->setPin($user, $validated['pin'], $request);

        $this->grantModuleAccess($request);

        return response()->json([
            'message' => 'PIN set. Module access granted for this session.',
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->phoneVerifiedThisSession($request)) {
            return $this->phoneVerificationRequiredResponse();
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);

        $result = $this->moduleAccess->verifyPin($user, $validated['pin'], $request);

        if ($result['locked']) {
            return response()->json([
                'message' => 'Too many failed PIN attempts. Try again later.',
                'code' => 'pin_locked',
                'locked_until' => $result['locked_until']->toISOString(),
            ], 429);
        }

        if (! $result['success']) {
            return response()->json([
                'message' => 'Incorrect PIN.',
                'code' => 'pin_incorrect',
            ], 401);
        }

        $this->grantModuleAccess($request);

        return response()->json([
            'message' => 'Module access granted.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $user = $request->user();

        // The recovery path IS re-verifying phone ownership — there is no separate flow.
        if (! $this->phoneVerifiedThisSession($request)) {
            return response()->json([
                'message' => 'Re-verify your phone number to reset your PIN.',
                'code' => 'phone_verification_required',
            ], 403);
        }

        $validated = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/', 'confirmed'],
        ]);

        $this->moduleAccess->resetPin($user, $validated['pin'], $request);

        $this->grantModuleAccess($request);

        return response()->json([
            'message' => 'PIN reset. Module access granted.',
        ]);
    }

    private function phoneVerifiedThisSession(Request $request): bool
    {
        return (bool) $request->session()->get('module_access.phone_verified_at');
    }

    private function phoneVerificationRequiredResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Verify your phone number before continuing.',
            'code' => 'phone_verification_required',
        ], 403);
    }

    private function grantModuleAccess(Request $request): void
    {
        $now = now()->toISOString();
        $request->session()->put('module_access.granted_at', $now);
        $request->session()->put('module_access.last_pin_verified_at', $now);
    }
}
