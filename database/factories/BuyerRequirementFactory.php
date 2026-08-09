<?php

namespace Database\Factories;

use App\Enums\BuyerRequirementStatus;
use App\Models\Buyer;
use App\Models\BuyerRequirement;
use App\Models\Market;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BuyerRequirement>
 */
class BuyerRequirementFactory extends Factory
{
    protected $model = BuyerRequirement::class;

    public function definition(): array
    {
        return [
            'buyer_id' => Buyer::factory(),
            'product_id' => Product::factory(),
            'market_id' => Market::factory(),
            'volume' => fake()->randomFloat(2, 10, 5000),
            'status' => fake()->randomElement(BuyerRequirementStatus::cases()),
        ];
    }
}
