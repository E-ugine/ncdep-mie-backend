<?php

namespace App\Observers;

use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Writes section 3.10's "full commercial timeline/audit trail" automatically — every deal
 * creation and every pipeline_stage change becomes a real deal_events row, with no controller
 * ever calling DealEvent::create() directly. This is the single place that logic lives.
 *
 * Stage 7 extends this to ALSO write a section 3.15 notification on every stage transition (to
 * the deal's supplier-linked user(s), if any exist) — reusing this same observer rather than a
 * separate one, since "deal status change" and "deal_events" are triggered by the exact same event.
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

        $this->notifyStageChange($deal, $fromStage);
    }

    private function notifyStageChange(Deal $deal, DealPipelineStage $fromStage): void
    {
        $supplier = $deal->negotiation()->with('offer.match.supplier.users')->first()?->offer?->match?->supplier;

        if (! $supplier) {
            return;
        }

        foreach ($supplier->users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'notifiable_type' => Deal::class,
                'notifiable_id' => $deal->id,
                'type' => 'deal_status_change',
                'data' => [
                    'from_stage' => $fromStage->value,
                    'to_stage' => $deal->pipeline_stage->value,
                ],
            ]);
        }
    }
}
