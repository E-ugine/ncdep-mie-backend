<?php

namespace Database\Factories;

use App\Enums\NegotiationStatus;
use App\Models\Negotiation;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Negotiation>
 */
class NegotiationFactory extends Factory
{
    protected $model = Negotiation::class;

    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'status' => fake()->randomElement(NegotiationStatus::cases()),
            'counter_price' => fake()->randomFloat(2, 100, 2000),
            'counter_volume' => fake()->randomFloat(2, 10, 5000),
            'notes' => fake()->sentence(),
        ];
    }
}
