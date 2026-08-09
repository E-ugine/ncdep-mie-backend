<?php

namespace Database\Factories;

use App\Enums\ModuleAccessAttemptType;
use App\Enums\ModuleAccessOutcome;
use App\Models\ModuleAccessLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModuleAccessLog>
 */
class ModuleAccessLogFactory extends Factory
{
    protected $model = ModuleAccessLog::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'attempt_type' => fake()->randomElement(ModuleAccessAttemptType::cases()),
            'outcome' => fake()->randomElement(ModuleAccessOutcome::cases()),
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
