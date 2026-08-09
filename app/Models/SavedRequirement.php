<?php

namespace App\Models;

use Database\Factories\SavedRequirementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'buyer_requirement_id'])]
class SavedRequirement extends Model
{
    /** @use HasFactory<SavedRequirementFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buyerRequirement(): BelongsTo
    {
        return $this->belongsTo(BuyerRequirement::class);
    }
}
