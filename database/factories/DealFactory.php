<?php

namespace Database\Factories;

use App\Enums\DealPipelineStage;
use App\Models\Deal;
use App\Models\Negotiation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deal>
 */
class DealFactory extends Factory
{
    protected $model = Deal::class;

    public function definition(): array
    {
        return [
            'negotiation_id' => Negotiation::factory(),
            'pipeline_stage' => fake()->randomElement(DealPipelineStage::cases()),
            'agreed_price' => fake()->randomFloat(2, 100, 2000),
            'agreed_volume' => fake()->randomFloat(2, 10, 5000),
            'currency' => 'USD',
        ];
    }
}
