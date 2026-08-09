<?php

namespace App\Observers;

use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use App\Models\Deal;
use App\Models\DealEvent;
use Illuminate\Support\Facades\Auth;

/**
 * Writes section 3.10's "full commercial timeline/audit trail" automatically — every deal
 * creation and every pipeline_stage change becomes a real deal_events row, with no controller
 * ever calling DealEvent::create() directly. This is the single place that logic lives.
 */
class DealObserver
{
    public function created(Deal $deal): void
    {
        DealEvent::create([
            'deal_id' => $deal->id,
            'event_type' => DealEventType::Created,
            'from_stage' => null,
            'to_stage' => $deal->pipeline_stage,
            'actor_user_id' => Auth::id(),
        ]);
    }

    public function updated(Deal $deal): void
    {
        if (! $deal->wasChanged('pipeline_stage')) {
            return;
        }

        $original = $deal->getOriginal('pipeline_stage');
        $fromStage = $original instanceof DealPipelineStage ? $original : DealPipelineStage::from($original);

        DealEvent::create([
            'deal_id' => $deal->id,
            'event_type' => DealEventType::StageTransition,
            'from_stage' => $fromStage,
            'to_stage' => $deal->pipeline_stage,
            'actor_user_id' => Auth::id(),
        ]);
    }
}
