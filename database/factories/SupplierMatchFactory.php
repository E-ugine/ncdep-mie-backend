<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\Supplier;
use App\Models\SupplierMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierMatch>
 */
class SupplierMatchFactory extends Factory
{
    protected $model = SupplierMatch::class;

    public function definition(): array
    {
        return [
            'buyer_requirement_id' => BuyerRequirement::factory(),
            'supplier_id' => Supplier::factory(),
            'score' => fake()->numberBetween(0, 100),
            'reason' => ['factors' => [fake()->word(), fake()->word()]],
            'fulfillable_volume' => fake()->randomFloat(2, 10, 5000),
        ];
    }
}
