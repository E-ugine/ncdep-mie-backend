<?php

namespace App\Models;

use App\Enums\BuyerType;
use App\Enums\BuyerVerificationStatus;
use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'country_id', 'name', 'description',
    'buyer_type', 'industry', 'hq', 'payment_terms', 'currency',
    'preferred_ports', 'logistics_preferences', 'verification_status',
])]
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'buyer_type' => BuyerType::class,
            'verification_status' => BuyerVerificationStatus::class,
            'preferred_ports' => 'array',
            'logistics_preferences' => 'array',
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(BuyerRequirement::class);
    }
}
