<?php

namespace Database\Factories;

use App\Models\BuyerRequirement;
use App\Models\SavedRequirement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SavedRequirement>
 */
class SavedRequirementFactory extends Factory
{
    protected $model = SavedRequirement::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'buyer_requirement_id' => BuyerRequirement::factory(),
        ];
    }
}
