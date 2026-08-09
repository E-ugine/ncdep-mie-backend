<?php

namespace App\Models;

use App\Enums\ContractStatus;
use Database\Factories\ContractFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['deal_id', 'contract_number', 'value', 'volume', 'currency', 'incoterm', 'delivery_date', 'payment_terms', 'status'])]
class Contract extends Model
{
    /** @use HasFactory<ContractFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ContractStatus::class,
            'value' => 'decimal:2',
            'volume' => 'decimal:2',
            'delivery_date' => 'date',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'conversable');
    }
}
