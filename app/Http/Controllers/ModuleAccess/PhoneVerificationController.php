<?php

namespace App\Http\Controllers\ModuleAccess;

use App\Http\Controllers\Controller;
use App\Services\ModuleAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhoneVerificationController extends Controller
{
    public function __construct(private readonly ModuleAccessService $moduleAccess) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $sent = $this->moduleAccess->requestPhoneOtp($request->user(), $request);

        if (! $sent) {
            return response()->json([
                'message' => 'A verification code could not be sent — your phone number is not verified on your account.',
                'code' => 'phone_not_verified_on_account',
            ], 422);
        }

        return response()->json([
            'message' => 'A verification code has been sent to your registered phone number.',
            'expires_in_minutes' => config('module_access.otp_ttl_minutes'),
        ]);
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'digits:'.config('module_access.otp_length')],
        ]);

        $verified = $this->moduleAccess->verifyPhoneOtp($request->user(), $validated['code'], $request);

        if (! $verified) {
            return response()->json([
                'message' => 'That code is invalid or has expired.',
                'code' => 'otp_invalid',
            ], 422);
        }

        $request->session()->put('module_access.phone_verified_at', now()->toISOString());

        return response()->json([
            'message' => 'Phone verified for this session.',
        ]);
    }
}
