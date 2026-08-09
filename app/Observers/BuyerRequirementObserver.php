<?php

namespace App\Observers;

use App\Models\BuyerRequirement;
use App\Models\Notification;
use App\Services\Mie\MatchScorer;

/**
 * Section 3.15 — notifies every supplier whose supplier_capacity matches a NEWLY created
 * requirement's product form. Reuses MatchScorer::candidatesFor() — the exact same
 * candidate-finding query RequirementController::match() uses — rather than a second copy of it.
 */
class BuyerRequirementObserver
{
    public function __construct(private readonly MatchScorer $matchScorer) {}

    public function created(BuyerRequirement $requirement): void
    {
        $requirement->loadMissing('product');

        foreach ($this->matchScorer->candidatesFor($requirement) as $capacity) {
            foreach ($capacity->supplier->users as $user) {
                Notification::create([
                    'user_id' => $user->id,
                    'notifiable_type' => BuyerRequirement::class,
                    'notifiable_id' => $requirement->id,
                    'type' => 'new_matching_requirement',
                    'data' => [
                        'buyer_requirement_id' => $requirement->id,
                        'product_form_id' => $capacity->product_form_id,
                        'volume' => (float) $requirement->volume,
                    ],
                ]);
            }
        }
    }
}
