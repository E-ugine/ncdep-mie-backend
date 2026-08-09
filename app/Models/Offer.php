<?php

namespace App\Models;

use App\Enums\OfferStatus;
use Database\Factories\OfferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['match_id', 'price', 'volume', 'currency', 'status', 'valid_until'])]
class Offer extends Model
{
    /** @use HasFactory<OfferFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OfferStatus::class,
            'price' => 'decimal:2',
            'volume' => 'decimal:2',
            'valid_until' => 'datetime',
        ];
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(SupplierMatch::class, 'match_id');
    }

    public function negotiation(): HasOne
    {
        return $this->hasOne(Negotiation::class);
    }
}
