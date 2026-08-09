<?php

namespace App\Models;

use Database\Factories\SupplierCapacityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['supplier_id', 'product_form_id', 'capacity_volume', 'available_volume', 'certifications'])]
class SupplierCapacity extends Model
{
    /** @use HasFactory<SupplierCapacityFactory> */
    use HasFactory;

    protected $table = 'supplier_capacity';

    protected function casts(): array
    {
        return [
            'capacity_volume' => 'decimal:2',
            'available_volume' => 'decimal:2',
            'certifications' => 'array',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }
}
