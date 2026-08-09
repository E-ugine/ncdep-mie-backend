<?php

namespace App\Models;

use App\Enums\DealPipelineStage;
use Database\Factories\DealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable(['negotiation_id', 'pipeline_stage', 'agreed_price', 'agreed_volume', 'currency'])]
class Deal extends Model
{
    /** @use HasFactory<DealFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'pipeline_stage' => DealPipelineStage::class,
            'agreed_price' => 'decimal:2',
            'agreed_volume' => 'decimal:2',
        ];
    }

    public function negotiation(): BelongsTo
    {
        return $this->belongsTo(Negotiation::class);
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function conversations(): MorphMany
    {
        return $this->morphMany(Conversation::class, 'conversable');
    }
}
