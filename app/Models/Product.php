<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

#[Fillable(['product_form_id', 'name', 'unit_of_measure', 'description'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    public function productForm(): BelongsTo
    {
        return $this->belongsTo(ProductForm::class);
    }

    public function commodity(): HasOneThrough
    {
        return $this->hasOneThrough(
            Commodity::class,
            ProductForm::class,
            'id',
            'id',
            'product_form_id',
            'commodity_id',
        );
    }

    public function buyerRequirements(): HasMany
    {
        return $this->hasMany(BuyerRequirement::class);
    }
}
