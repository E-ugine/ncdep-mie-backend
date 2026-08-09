<?php

namespace App\Services;

use App\Enums\ModuleAccessAttemptType;
use App\Enums\ModuleAccessOutcome;
use App\Models\ModuleAccessLog;
use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Second-factor gate (phone OTP + PIN) for the Market Intelligence and Exchange
 * module, per spec section 1.1. This module CONSUMES account-level phone
 * verification status — it does not own or perform identity/phone verification.
 */
class ModuleAccessService
{
    public function requestPhoneOtp(User $user, Request $request): bool
    {
        if (! $user->phone || ! $user->phone_verified_at) {
            $this->log($user, ModuleAccessAttemptType::PhoneOtp, ModuleAccessOutcome::Failure, $request);

            return false;
        }

        // Invalidate any still-outstanding code so only the newest OTP is ever valid.
        PhoneOtp::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        $code = (string) random_int(10 ** (config('module_access.otp_length') - 1), (10 ** config('module_access.otp_length')) - 1);

        PhoneOtp::create([
            'user_id' => $user->id,
            'code' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('module_access.otp_ttl_minutes')),
        ]);

        $this->deliverOtp($user, $code);

        $this->log($user, ModuleAccessAttemptType::PhoneOtp, ModuleAccessOutcome::Success, $request);

        return true;
    }

    public function verifyPhoneOtp(User $user, string $code, Request $request): bool
    {
        $otp = PhoneOtp::where('user_id', $user->id)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || ! Hash::check($code, $otp->code)) {
            $this->log($user, ModuleAccessAttemptType::PhoneOtp, ModuleAccessOutcome::Failure, $request);

            return false;
        }

        $otp->update(['consumed_at' => now()]);

        $this->log($user, ModuleAccessAttemptType::PhoneOtp, ModuleAccessOutcome::Success, $request);

        return true;
    }

    public function setPin(User $user, string $pin, Request $request): bool
    {
        if ($user->pin_hash) {
            $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Failure, $request);

            return false;
        }

        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();

        $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Success, $request);

        return true;
    }

    /**
     * @return array{locked: bool, success: bool, locked_until: ?\Illuminate\Support\Carbon}
     */
    public function verifyPin(User $user, string $pin, Request $request): array
    {
        $key = $this->pinRateLimitKey($user);
        $maxAttempts = (int) config('module_access.pin_max_attempts');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $lockedUntil = now()->addSeconds(RateLimiter::availableIn($key));

            // Locked-out attempts must still be auditable, not silently dropped.
            $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Failure, $request);

            return ['locked' => true, 'success' => false, 'locked_until' => $lockedUntil];
        }

        if (! $user->pin_hash || ! Hash::check($pin, $user->pin_hash)) {
            RateLimiter::hit($key, (int) config('module_access.pin_lockout_minutes') * 60);

            $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Failure, $request);

            return ['locked' => false, 'success' => false, 'locked_until' => null];
        }

        RateLimiter::clear($key);

        $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Success, $request);

        return ['locked' => false, 'success' => true, 'locked_until' => null];
    }

    public function resetPin(User $user, string $pin, Request $request): void
    {
        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();

        RateLimiter::clear($this->pinRateLimitKey($user));

        $this->log($user, ModuleAccessAttemptType::Pin, ModuleAccessOutcome::Success, $request);
    }

    protected function pinRateLimitKey(User $user): string
    {
        return "module-access-pin:{$user->id}";
    }

    /**
     * Stand-in for a real SMS gateway (Twilio, Africa's Talking, etc.), which is out of this
     * module's scope — phone delivery is an external integration, not part of the access gate
     * logic itself. Logging keeps the flow exercisable end-to-end without a real provider wired up.
     */
    protected function deliverOtp(User $user, string $code): void
    {
        Log::info("[module-access] OTP for user #{$user->id} ({$user->phone}): {$code}");
    }

    protected function log(User $user, ModuleAccessAttemptType $type, ModuleAccessOutcome $outcome, Request $request): void
    {
        ModuleAccessLog::create([
            'user_id' => $user->id,
            'attempt_type' => $type,
            'outcome' => $outcome,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
