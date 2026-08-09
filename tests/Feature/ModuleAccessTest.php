<?php

namespace Tests\Feature;

use App\Models\ModuleAccessLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only boots the session middleware for requests it recognizes as coming
        // from the configured first-party frontend (matched by Referer/Origin against
        // SANCTUM_STATEFUL_DOMAINS) — the module access gate relies on that session, so
        // tests simulate that frontend origin rather than changing the app's middleware.
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function verifiedUser(): User
    {
        return User::factory()->create([
            'phone' => '+254700000000',
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * Captures the OTP code "delivered" via Log::info during the given action, since it's
     * hashed at rest and never returned in any API response.
     */
    private function captureOtpCode(callable $action): string
    {
        /** @var string|null $captured */
        $captured = null;

        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$captured) {
            if (preg_match('/OTP for user #\d+ \([^)]*\): (\d+)/', $event->message, $matches)) {
                $captured = $matches[1];
            }
        });

        $action();

        $this->assertNotNull($captured, 'Expected an OTP to have been logged.');

        return $captured;
    }

    public function test_otp_request_fails_when_phone_not_verified_on_account(): void
    {
        $user = User::factory()->create(['phone' => null, 'phone_verified_at' => null]);

        $response = $this->actingAs($user)->postJson('/api/module-access/phone/request-otp');

        $response->assertStatus(422)->assertJson(['code' => 'phone_not_verified_on_account']);

        $this->assertDatabaseHas('module_access_logs', [
            'user_id' => $user->id,
            'attempt_type' => 'phone_otp',
            'outcome' => 'failure',
        ]);
    }

    public function test_protected_route_blocks_until_phone_and_pin_are_verified(): void
    {
        $user = $this->verifiedUser();

        // Nothing done yet this session.
        $this->actingAs($user)->getJson('/api/mie/ping')
            ->assertStatus(403)
            ->assertJson(['code' => 'phone_verification_required']);

        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());

        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])->assertOk();

        // Phone done, but no PIN set yet.
        $this->actingAs($user)->getJson('/api/mie/ping')
            ->assertStatus(403)
            ->assertJson(['code' => 'pin_setup_required']);

        $this->actingAs($user)->postJson('/api/module-access/pin/set', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertOk();

        // Now fully granted for this session.
        $this->actingAs($user)->getJson('/api/mie/ping')->assertOk()
            ->assertJson(['status' => 'module access granted']);
    }

    public function test_first_time_user_completes_phone_and_pin_setup(): void
    {
        $user = $this->verifiedUser();

        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());

        $this->assertDatabaseHas('module_access_logs', [
            'user_id' => $user->id,
            'attempt_type' => 'phone_otp',
            'outcome' => 'success',
        ]);

        // Wrong code first — must fail and log.
        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => '000000'])
            ->assertStatus(422)
            ->assertJson(['code' => 'otp_invalid']);

        $this->assertDatabaseHas('module_access_logs', [
            'user_id' => $user->id,
            'attempt_type' => 'phone_otp',
            'outcome' => 'failure',
        ]);

        // Correct code succeeds.
        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])->assertOk();

        // Can't set a PIN twice via /pin/set.
        $this->actingAs($user)->postJson('/api/module-access/pin/set', [
            'pin' => '4321',
            'pin_confirmation' => '4321',
        ])->assertOk();

        $this->actingAs($user)->postJson('/api/module-access/pin/set', [
            'pin' => '9999',
            'pin_confirmation' => '9999',
        ])->assertStatus(409)->assertJson(['code' => 'pin_already_set']);

        $this->assertNotNull($user->fresh()->pin_hash);
        $this->assertNotEquals('4321', $user->fresh()->pin_hash);
    }

    public function test_pin_locks_out_after_five_failed_attempts_and_logs_every_attempt(): void
    {
        $user = $this->verifiedUser();
        $user->forceFill(['pin_hash' => bcrypt('1234')])->save();

        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());
        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/module-access/pin/verify', ['pin' => '0000'])
                ->assertStatus(401)
                ->assertJson(['code' => 'pin_incorrect']);
        }

        // 6th attempt is locked out — distinct 429, even though the PIN entered is correct.
        $response = $this->actingAs($user)->postJson('/api/module-access/pin/verify', ['pin' => '1234']);
        $response->assertStatus(429)->assertJson(['code' => 'pin_locked']);
        $this->assertArrayHasKey('locked_until', $response->json());

        // Every one of the 6 attempts — including the locked-out one — must be audited.
        $this->assertSame(
            6,
            ModuleAccessLog::where('user_id', $user->id)
                ->where('attempt_type', 'pin')
                ->where('outcome', 'failure')
                ->count(),
        );

        // Access was never granted.
        $this->actingAs($user)->getJson('/api/mie/ping')->assertStatus(403);
    }

    public function test_pin_reset_requires_fresh_phone_verification_and_then_grants_access(): void
    {
        $user = $this->verifiedUser();
        $user->forceFill(['pin_hash' => bcrypt('1234')])->save();

        // Forgotten PIN — user has NOT done the phone step yet this session.
        $this->actingAs($user)->postJson('/api/module-access/pin/reset', [
            'pin' => '5678',
            'pin_confirmation' => '5678',
        ])->assertStatus(403)->assertJson(['code' => 'phone_verification_required']);

        // Recovery path IS phone re-verification.
        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());
        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])->assertOk();

        $this->actingAs($user)->postJson('/api/module-access/pin/reset', [
            'pin' => '5678',
            'pin_confirmation' => '5678',
        ])->assertOk();

        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('5678', $user->fresh()->pin_hash));

        // Reset also grants module access for this session, same as a normal verify.
        $this->actingAs($user)->getJson('/api/mie/ping')->assertOk();
    }

    public function test_otp_expires_after_configured_ttl(): void
    {
        $user = $this->verifiedUser();

        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());

        $this->travel(config('module_access.otp_ttl_minutes') + 1)->minutes();

        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])
            ->assertStatus(422)
            ->assertJson(['code' => 'otp_invalid']);
    }

    public function test_requires_fresh_pin_guard_blocks_stale_pin_verification(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'module.access', 'module.access.fresh-pin'])
            ->get('/api/mie/test-sensitive-action', fn () => response()->json(['status' => 'ok']));

        $user = $this->verifiedUser();

        $code = $this->captureOtpCode(fn () => $this->actingAs($user)->postJson('/api/module-access/phone/request-otp')->assertOk());
        $this->actingAs($user)->postJson('/api/module-access/phone/verify-otp', ['code' => $code])->assertOk();
        $this->actingAs($user)->postJson('/api/module-access/pin/set', [
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertOk();

        // PIN was just verified — within the freshness window.
        $this->actingAs($user)->getJson('/api/mie/test-sensitive-action')->assertOk();

        // Move past the freshness window without re-verifying.
        $this->travel(config('module_access.fresh_pin_window_minutes') + 1)->minutes();

        $this->actingAs($user)->getJson('/api/mie/test-sensitive-action')
            ->assertStatus(403)
            ->assertJson(['code' => 'fresh_pin_required']);

        // Re-verifying the PIN restores freshness.
        $this->actingAs($user)->postJson('/api/module-access/pin/verify', ['pin' => '1234'])->assertOk();
        $this->actingAs($user)->getJson('/api/mie/test-sensitive-action')->assertOk();
    }
}
