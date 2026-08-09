<?php

namespace App\Models;

use App\Enums\NegotiationStatus;
use Database\Factories\NegotiationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['offer_id', 'status', 'counter_price', 'counter_volume', 'notes'])]
class Negotiation extends Model
{
    /** @use HasFactory<NegotiationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => NegotiationStatus::class,
            'counter_price' => 'decimal:2',
            'counter_volume' => 'decimal:2',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    public function deal(): HasOne
    {
        return $this->hasOne(Deal::class);
    }
}
