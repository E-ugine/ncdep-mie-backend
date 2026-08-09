<?php

namespace App\Models;

use App\Enums\CommodityCategory;
use Database\Factories\CommodityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category', 'description'])]
class Commodity extends Model
{
    /** @use HasFactory<CommodityFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'category' => CommodityCategory::class,
        ];
    }

    public function productForms(): HasMany
    {
        return $this->hasMany(ProductForm::class);
    }
}
