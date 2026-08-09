<?php

namespace App\Models;

use App\Enums\SupplierType;
use Database\Factories\SupplierFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['country_id', 'name', 'type'])]
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => SupplierType::class,
        ];
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function capacity(): HasMany
    {
        return $this->hasMany(SupplierCapacity::class);
    }

    public function matches(): HasMany
    {
        return $this->hasMany(SupplierMatch::class);
    }

    /**
     * Platform users representing this supplier (part A of the user↔supplier gap closure —
     * the FK lives on `users`, this is the reverse side).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
