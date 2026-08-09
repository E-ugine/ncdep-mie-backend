<?php

namespace Database\Seeders\Mie;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The user used by `mie:demo-walkthrough` and for manual API exploration. Phone is pre-verified
 * and a PIN is pre-set directly (bypassing the real phone-otp/PIN-set flow here is deliberate:
 * this is fixture setup, not a demonstration of section 1.1's gate itself — the demo-walkthrough
 * command exercises the REAL gate endpoints separately, using this known PIN).
 */
class DemoUserSeeder extends Seeder
{
    public const EMAIL = 'demo@ncdep-mie.test';

    public const PIN = '1234';

    public User $user;

    public function run(?Supplier $supplier = null): User
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            ['name' => 'Amina Otieno', 'password' => 'password'],
        );

        $user->forceFill([
            'phone' => '+254700123456',
            'phone_verified_at' => $user->phone_verified_at ?? now(),
            'pin_hash' => Hash::make(self::PIN),
            'supplier_id' => $supplier?->id ?? $user->supplier_id,
        ])->save();

        return $this->user = $user->fresh();
    }
}
