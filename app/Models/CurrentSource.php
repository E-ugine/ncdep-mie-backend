<?php

namespace App\Models;

use Database\Factories\CurrentSourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['buyer_requirement_id', 'country_id', 'supplier_name', 'estimated_volume'])]
class CurrentSource extends Model
{
    /** @use HasFactory<CurrentSourceFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'estimated_volume' => 'decimal:2',
        ];
    }

    public function buyerRequirement(): BelongsTo
    {
        return $this->belongsTo(BuyerRequirement::class);
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }
}
