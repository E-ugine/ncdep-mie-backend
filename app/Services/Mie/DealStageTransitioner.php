<?php

namespace App\Services\Mie;

use App\Enums\DealPipelineStage;
use App\Models\Deal;

/**
 * Section 3.10's pipeline stages, transitioned only along sane forward paths — explicit map, not
 * an implicit chain of if-statements, so it can be eyeballed against the spec directly.
 *
 * `open`/`negotiating`/`awaiting_buyer`/`awaiting_supplier` can move laterally among each other —
 * real negotiations bounce between these before firming up, and all four are still "pre-contract"
 * phase, so lateral movement there isn't "jumping ahead." From `contract_pending` onward the path
 * is strictly linear (contract_pending → contract_signed → in_production → in_transit →
 * delivered → payment_pending → completed) with no going back and no skipping — e.g. open cannot
 * jump straight to completed, and once contract_pending is reached there's no returning to open.
 * `completed` is terminal (empty allowed-next list).
 */
class DealStageTransitioner
{
    public const TRANSITIONS = [
        'open' => ['negotiating', 'awaiting_buyer', 'awaiting_supplier'],
        'negotiating' => ['awaiting_buyer', 'awaiting_supplier', 'contract_pending'],
        'awaiting_buyer' => ['negotiating', 'awaiting_supplier', 'contract_pending'],
        'awaiting_supplier' => ['negotiating', 'awaiting_buyer', 'contract_pending'],
        'contract_pending' => ['contract_signed'],
        'contract_signed' => ['in_production'],
        'in_production' => ['in_transit'],
        'in_transit' => ['delivered'],
        'delivered' => ['payment_pending'],
        'payment_pending' => ['completed'],
        'completed' => [],
    ];

    public function allowedNextStages(DealPipelineStage $from): array
    {
        return self::TRANSITIONS[$from->value] ?? [];
    }

    public function canTransition(DealPipelineStage $from, DealPipelineStage $to): bool
    {
        return in_array($to->value, $this->allowedNextStages($from), true);
    }

    /**
     * Performs the transition (saving the model, which fires DealObserver::updated() and writes
     * the deal_events row) only if it's allowed. Returns false without touching the model at all
     * if the transition isn't in the map — the caller is responsible for the 422 response.
     */
    public function transition(Deal $deal, DealPipelineStage $to): bool
    {
        if (! $this->canTransition($deal->pipeline_stage, $to)) {
            return false;
        }

        $deal->pipeline_stage = $to;
        $deal->save();

        return true;
    }
}
