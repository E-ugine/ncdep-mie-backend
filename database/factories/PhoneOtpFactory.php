<?php

namespace Database\Factories;

use App\Models\PhoneOtp;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<PhoneOtp>
 */
class PhoneOtpFactory extends Factory
{
    protected $model = PhoneOtp::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'code' => Hash::make((string) fake()->numberBetween(100000, 999999)),
            'expires_at' => now()->addMinutes(config('module_access.otp_ttl_minutes')),
            'consumed_at' => null,
        ];
    }
}
