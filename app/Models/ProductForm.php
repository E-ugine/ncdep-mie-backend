<?php

namespace App\Models;

use App\Enums\ProductFormState;
use Database\Factories\ProductFormFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['commodity_id', 'state', 'name', 'description'])]
class ProductForm extends Model
{
    /** @use HasFactory<ProductFormFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'state' => ProductFormState::class,
        ];
    }

    public function commodity(): BelongsTo
    {
        return $this->belongsTo(Commodity::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function supplierCapacity(): HasMany
    {
        return $this->hasMany(SupplierCapacity::class);
    }
}
