<?php

namespace App\Models;

use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use Database\Factories\DealEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deal_id', 'event_type', 'from_stage', 'to_stage', 'actor_user_id', 'metadata'])]
class DealEvent extends Model
{
    /** @use HasFactory<DealEventFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'event_type' => DealEventType::class,
            'from_stage' => DealPipelineStage::class,
            'to_stage' => DealPipelineStage::class,
            'metadata' => 'array',
        ];
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
