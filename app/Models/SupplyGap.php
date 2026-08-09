<?php

namespace App\Models;

use Database\Factories\SupplyGapFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['buyer_requirement_id', 'demand_volume', 'contracted_volume'])]
class SupplyGap extends Model
{
    /** @use HasFactory<SupplyGapFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'demand_volume' => 'decimal:2',
            'contracted_volume' => 'decimal:2',
        ];
    }

    public function buyerRequirement(): BelongsTo
    {
        return $this->belongsTo(BuyerRequirement::class);
    }

    public function gap(): float
    {
        return round((float) $this->demand_volume - (float) $this->contracted_volume, 2);
    }
}
