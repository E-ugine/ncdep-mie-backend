<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\SupplyGap;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplyGap>
 */
class SupplyGapFactory extends Factory
{
    protected $model = SupplyGap::class;

    public function definition(): array
    {
        return [
            'buyer_requirement_id' => BuyerRequirement::factory(),
            'demand_volume' => fake()->randomFloat(2, 100, 5000),
            'contracted_volume' => fake()->randomFloat(2, 0, 2500),
        ];
    }
}
