<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Models\SupplierMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    protected $model = Offer::class;

    public function definition(): array
    {
        return [
            'match_id' => SupplierMatch::factory(),
            'price' => fake()->randomFloat(2, 100, 2000),
            'volume' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'USD',
            'status' => fake()->randomElement(OfferStatus::cases()),
            'valid_until' => fake()->dateTimeBetween('now', '+30 days'),
        ];
    }
}
