<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\Country;
use App\Models\CurrentSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrentSource>
 */
class CurrentSourceFactory extends Factory
{
    protected $model = CurrentSource::class;

    public function definition(): array
    {
        return [
            'buyer_requirement_id' => BuyerRequirement::factory(),
            'country_id' => Country::factory(),
            'supplier_name' => fake()->company(),
            'estimated_volume' => fake()->randomFloat(2, 10, 3000),
        ];
    }
}
