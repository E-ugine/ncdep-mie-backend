<?php

namespace App\Observers;

use App\Models\BuyerRequirement;
use App\Models\Notification;
use App\Models\SupplyGap;
use App\Services\Mie\MatchScorer;

/**
 * Section 3.15 — notifies every supplier with capacity in this product form whenever a SupplyGap
 * is created or updated such that gap > 0. Literal to the spec's wording: this fires on every
 * create/update that results in a positive gap, not only the first time a gap opens — an ongoing
 * gap can produce more than one notification over its lifetime, by design, not an oversight.
 */
class SupplyGapObserver
{
    public function __construct(private readonly MatchScorer $matchScorer) {}

    public function created(SupplyGap $supplyGap): void
    {
        $this->notifyIfOpen($supplyGap);
    }

    public function updated(SupplyGap $supplyGap): void
    {
        $this->notifyIfOpen($supplyGap);
    }

    private function notifyIfOpen(SupplyGap $supplyGap): void
    {
        if ($supplyGap->gap() <= 0) {
            return;
        }

        $requirement = $supplyGap->buyerRequirement()->with('product')->first();

        if (! $requirement) {
            return;
        }

        foreach ($this->matchScorer->candidatesFor($requirement) as $capacity) {
            foreach ($capacity->supplier->users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'notifiable_type' => BuyerRequirement::class,
                    'notifiable_id' => $requirement->id,
                    'type' => 'supply_gap_detected',
                    'data' => [
                        'buyer_requirement_id' => $requirement->id,
                        'gap' => $supplyGap->gap(),
                    ],
                ]);
            }
        }
    }
}
