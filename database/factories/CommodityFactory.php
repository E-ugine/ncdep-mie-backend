<?php

namespace Database\Factories;

use App\Enums\CommodityCategory;
use App\Models\Commodity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commodity>
 */
class CommodityFactory extends Factory
{
    protected $model = Commodity::class;

    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->word()),
            'category' => fake()->randomElement(CommodityCategory::cases()),
            'description' => fake()->sentence(),
        ];
    }
}
