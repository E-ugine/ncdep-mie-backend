<?php

namespace Database\Factories;

use App\Models\Buyer;
use App\Models\Country;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Buyer>
 */
class BuyerFactory extends Factory
{
    protected $model = Buyer::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->company(),
            'description' => fake()->sentence(),
        ];
    }
}
