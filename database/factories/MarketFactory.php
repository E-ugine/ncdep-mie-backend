<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\Market;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Market>
 */
class MarketFactory extends Factory
{
    protected $model = Market::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }
}
