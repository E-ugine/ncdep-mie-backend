<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\SupplierMatch;

/**
 * Section 3.15 — notifies the matched supplier's linked user(s), if any exist, whenever a real
 * match is created. Every `matches` row created via RequirementController::match() is already
 * above config('mie_scoring.match.minimum_score_threshold') by construction (see MatchScorer), so
 * no extra threshold check is needed here — every creation IS an above-threshold match.
 */
class SupplierMatchObserver
{
    public function created(SupplierMatch $match): void
    {
        $supplier = $match->supplier()->with('users')->first();

        if (! $supplier) {
            return;
        }

        foreach ($supplier->users as $user) {
            Notification::create([
                'user_id' => $user->id,
                'notifiable_type' => SupplierMatch::class,
                'notifiable_id' => $match->id,
                'type' => 'buyer_match_score_computed',
                'data' => [
                    'buyer_requirement_id' => $match->buyer_requirement_id,
                    'score' => $match->score,
                ],
            ]);
        }
    }
}
