<?php

namespace App\Models;

use Database\Factories\CountryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'iso_code'])]
class Country extends Model
{
    /** @use HasFactory<CountryFactory> */
    use HasFactory;

    public function markets(): HasMany
    {
        return $this->hasMany(Market::class);
    }

    public function buyers(): HasMany
    {
        return $this->hasMany(Buyer::class);
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }
}
