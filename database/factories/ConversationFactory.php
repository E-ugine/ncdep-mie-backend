<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => BuyerRequirement::factory(),
            'subject' => fake()->sentence(4),
        ];
    }
}
