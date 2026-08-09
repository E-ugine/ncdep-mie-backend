<?php

namespace Database\Factories;

use App\Enums\ProductFormState;
use App\Models\Commodity;
use App\Models\ProductForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductForm>
 */
class ProductFormFactory extends Factory
{
    protected $model = ProductForm::class;

    public function definition(): array
    {
        return [
            'commodity_id' => Commodity::factory(),
            'state' => fake()->randomElement(ProductFormState::cases()),
            'name' => fake()->words(2, true),
            'description' => fake()->sentence(),
        ];
    }
}
