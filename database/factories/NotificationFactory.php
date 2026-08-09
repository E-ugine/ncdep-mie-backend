<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'notifiable_type' => BuyerRequirement::class,
            'notifiable_id' => BuyerRequirement::factory(),
            'type' => fake()->randomElement([
                'new_requirement', 'price_movement', 'supply_gap', 'match_score',
                'contract_expiring', 'deal_status_change', 'shipment_delay',
            ]),
            'data' => ['message' => fake()->sentence()],
            'read_at' => null,
        ];
    }
}
