<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Contract;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'amount' => fake()->randomFloat(2, 1000, 100000),
            'currency' => 'USD',
            'status' => fake()->randomElement(PaymentStatus::cases()),
            'due_date' => fake()->dateTimeBetween('+7 days', '+60 days'),
            'paid_at' => null,
        ];
    }
}
