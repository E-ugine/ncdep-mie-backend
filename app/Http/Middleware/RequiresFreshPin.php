<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Extra guard for the two points in the module where financial/legal commitment
 * happens: contract creation/signing (section 3.11) and offer submission (section 3.4).
 * Not wired into any route yet — those routes don't exist yet (Section 3+) — this
 * middleware is built now so it's ready when they land, per the task's explicit ask.
 *
 * Requires the PIN to have been verified within a short recency window rather than
 * requiring re-entry on literally every request to these routes. That balances the same
 * usability-vs-risk tradeoff as EnsureModuleAccessGranted's session policy: a user
 * submitting several offers in one sitting isn't re-prompted for every single one, but
 * a PIN verified an hour ago (or on a session that's since been left unattended) no
 * longer counts as confirming *this* commitment.
 */
class RequiresFreshPin
{
    public function handle(Request $request, Closure $next): Response
    {
        $lastVerified = $request->session()->get('module_access.last_pin_verified_at');
        $windowMinutes = (int) config('module_access.fresh_pin_window_minutes');

        if (! $lastVerified || Carbon::parse($lastVerified)->lt(now()->subMinutes($windowMinutes))) {
            return response()->json([
                'message' => 'Please re-enter your PIN to confirm this action.',
                'code' => 'fresh_pin_required',
            ], 403);
        }

        return $next($request);
    }
}
