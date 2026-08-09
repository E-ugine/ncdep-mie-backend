<?php

namespace App\Models;

use Database\Factories\SupplierMatchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use InvalidArgumentException;

#[Fillable(['buyer_requirement_id', 'supplier_id', 'score', 'reason', 'fulfillable_volume'])]
class SupplierMatch extends Model
{
    /** @use HasFactory<SupplierMatchFactory> */
    use HasFactory;

    protected $table = 'matches';

    protected function casts(): array
    {
        return [
            'reason' => 'array',
            'fulfillable_volume' => 'decimal:2',
        ];
    }

    protected function score(): Attribute
    {
        return Attribute::make(
            set: function (int $value) {
                if ($value < 0 || $value > 100) {
                    throw new InvalidArgumentException('Match score must be between 0 and 100.');
                }

                return $value;
            },
        );
    }

    public function buyerRequirement(): BelongsTo
    {
        return $this->belongsTo(BuyerRequirement::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function offer(): HasOne
    {
        return $this->hasOne(Offer::class, 'match_id');
    }
}
