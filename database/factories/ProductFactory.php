<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductForm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'product_form_id' => ProductForm::factory(),
            'name' => fake()->words(3, true),
            'unit_of_measure' => 'MT',
            'description' => fake()->sentence(),
        ];
    }
}
