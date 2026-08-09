<?php

namespace Database\Factories;

use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplierCapacity>
 */
class SupplierCapacityFactory extends Factory
{
    protected $model = SupplierCapacity::class;

    public function definition(): array
    {
        return [
            'supplier_id' => Supplier::factory(),
            'product_form_id' => ProductForm::factory(),
            'capacity_volume' => fake()->randomFloat(2, 100, 10000),
            'available_volume' => fake()->randomFloat(2, 10, 5000),
        ];
    }
}
