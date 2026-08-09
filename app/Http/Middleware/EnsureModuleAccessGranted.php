<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates every Market Intelligence and Exchange module route behind the section-1.1
 * second factor (phone OTP + PIN). Runs after the standard `auth:sanctum` guard —
 * this is an ADDITIONAL gate on top of standard login, not a replacement for it.
 *
 * Session policy (spec explicitly asks for this to be stated + justified):
 * PIN — and, per the spec's literal "module entry point" wording, the phone OTP step
 * that precedes it — is required ONCE PER SESSION, on first entry to any module route.
 * It is deliberately NOT re-checked on every request: re-verifying on every click would
 * add friction with no real security benefit for read/browse-type actions. The one place
 * this module DOES re-prompt is financial/legal commitment points (contract signing, offer
 * submission) — that's handled by the separate `requiresFreshPin` guard
 * (RequiresFreshPin::class), which layers a PIN-recency check on top of this one.
 */
class EnsureModuleAccessGranted
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('module_access.granted_at')) {
            return $next($request);
        }

        if (! $request->session()->get('module_access.phone_verified_at')) {
            return response()->json([
                'message' => 'Phone verification is required before entering this module.',
                'code' => 'phone_verification_required',
            ], 403);
        }

        if (! $request->user()->pin_hash) {
            return response()->json([
                'message' => 'No PIN is set for this module yet. Set one to continue.',
                'code' => 'pin_setup_required',
            ], 403);
        }

        return response()->json([
            'message' => 'PIN verification is required to enter this module.',
            'code' => 'pin_verification_required',
        ], 403);
    }
}
