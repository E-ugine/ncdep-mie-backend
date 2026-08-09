<?php

namespace App\Models;

use Database\Factories\BuyerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['country_id', 'name', 'description'])]
class Buyer extends Model
{
    /** @use HasFactory<BuyerFactory> */
    use HasFactory;

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(BuyerRequirement::class);
    }
}
