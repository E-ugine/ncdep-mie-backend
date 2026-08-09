<?php

namespace Database\Factories;

use App\Enums\ContractStatus;
use App\Models\Contract;
use App\Models\Deal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contract>
 */
class ContractFactory extends Factory
{
    protected $model = Contract::class;

    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'contract_number' => strtoupper(fake()->unique()->bothify('CTR-####-????')),
            'value' => fake()->randomFloat(2, 1000, 100000),
            'volume' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'USD',
            'incoterm' => fake()->randomElement(['FOB', 'CIF', 'EXW', 'DDP']),
            'delivery_date' => fake()->dateTimeBetween('+7 days', '+90 days'),
            'payment_terms' => fake()->randomElement(['Net 30', 'Net 60', 'LC at sight', '50% advance']),
            'status' => fake()->randomElement(ContractStatus::cases()),
        ];
    }
}
