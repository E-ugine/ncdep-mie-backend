<?php

namespace Database\Factories;

use App\Enums\SupplierType;
use App\Models\Country;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'country_id' => Country::factory(),
            'name' => fake()->company(),
            'type' => fake()->randomElement(SupplierType::cases()),
        ];
    }
}
