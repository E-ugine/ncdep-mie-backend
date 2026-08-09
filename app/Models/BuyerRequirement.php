<?php

namespace App\Models;

use App\Enums\BuyerRequirementStatus;
use Database\Factories\BuyerRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['buyer_id', 'product_id', 'market_id', 'volume', 'status'])]
class BuyerRequirement extends Model
{
    /** @use HasFactory<BuyerRequirementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => BuyerRequirementStatus::class,
            'volume' => 'decimal:2',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Buyer::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function market(): BelongsTo
    {
        return $this->belongsTo(Market::class);
    }

    public function supplyGap(): HasOne
    {
        return $this->hasOne(SupplyGap::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SupplierMatch::class);
    }

    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'conversable');
    }
}
