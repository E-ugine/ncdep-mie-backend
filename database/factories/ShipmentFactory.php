<?php

namespace Database\Factories;

use App\Enums\ShipmentStatus;
use App\Models\Contract;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shipment>
 */
class ShipmentFactory extends Factory
{
    protected $model = Shipment::class;

    public function definition(): array
    {
        return [
            'contract_id' => Contract::factory(),
            'tracking_number' => strtoupper(fake()->bothify('TRK########')),
            'status' => fake()->randomElement(ShipmentStatus::cases()),
            'volume' => fake()->randomFloat(2, 10, 5000),
            'departure_date' => fake()->dateTimeBetween('+7 days', '+30 days'),
            'arrival_date' => fake()->dateTimeBetween('+31 days', '+90 days'),
        ];
    }
}
