<?php

namespace App\Models;

use App\Enums\BuyerRequirementStatus;
use App\Enums\RequirementFrequency;
use Database\Factories\BuyerRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'buyer_id', 'product_id', 'market_id', 'volume', 'status',
    'frequency', 'specification', 'delivery_window_start', 'delivery_window_end',
])]
class BuyerRequirement extends Model
{
    /** @use HasFactory<BuyerRequirementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => BuyerRequirementStatus::class,
            'volume' => 'decimal:2',
            'frequency' => RequirementFrequency::class,
            'specification' => 'array',
            'delivery_window_start' => 'date',
            'delivery_window_end' => 'date',
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

    /**
     * Currently-supplying countries/suppliers for this demand (section 2's "Current Source"
     * chain node, added in the section 3.1/3.2 pass — see the current_sources migration).
     */
    public function currentSources(): HasMany
    {
        return $this->hasMany(CurrentSource::class);
    }
}
