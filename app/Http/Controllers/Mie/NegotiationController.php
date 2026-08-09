<?php

namespace App\Http\Controllers\Mie;

use App\Enums\DealPipelineStage;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Negotiation;
use Illuminate\Http\JsonResponse;

/**
 * Section 3.10 — Deals Workspace: the single entry point into the deals pipeline. A deal
 * originates from an existing negotiation only, per the section 3.11 chain rule.
 */
class NegotiationController extends Controller
{
    public function convertToDeal(int $id): JsonResponse
    {
        $negotiation = Negotiation::with(['offer', 'deal'])->findOrFail($id);

        if ($negotiation->deal) {
            return response()->json([
                'message' => 'A deal already exists for this negotiation.',
                'code' => 'deal_already_exists',
                'deal_id' => $negotiation->deal->id,
            ], 422);
        }

        $offer = $negotiation->offer;

        $deal = Deal::create([
            'negotiation_id' => $negotiation->id,
            // Explicit, not relying on the DB-level default — see stage 5 summary re: the
            // Offer/Negotiation enum-default bug from stage 4.
            'pipeline_stage' => DealPipelineStage::Open,
            // Use whatever was actually agreed: the negotiation's counter terms if it made any,
            // otherwise the original offer's terms.
            'agreed_price' => $negotiation->counter_price ?? $offer->price,
            'agreed_volume' => $negotiation->counter_volume ?? $offer->volume,
            'currency' => $offer->currency,
        ]);

        return response()->json([
            'deal' => [
                'id' => $deal->id,
                'negotiation_id' => $deal->negotiation_id,
                'pipeline_stage' => $deal->pipeline_stage->value,
                'agreed_price' => (float) $deal->agreed_price,
                'agreed_volume' => (float) $deal->agreed_volume,
                'currency' => $deal->currency,
            ],
        ], 201);
    }
}
