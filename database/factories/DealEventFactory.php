<?php

namespace Database\Factories;

use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DealEvent>
 */
class DealEventFactory extends Factory
{
    protected $model = DealEvent::class;

    public function definition(): array
    {
        return [
            'deal_id' => Deal::factory(),
            'event_type' => DealEventType::StageTransition,
            'from_stage' => DealPipelineStage::Open,
            'to_stage' => DealPipelineStage::Negotiating,
            'actor_user_id' => User::factory(),
            'metadata' => null,
        ];
    }
}
