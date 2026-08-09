<?php

namespace App\Services\Mie;

use App\Enums\BuyerRequirementStatus;
use App\Models\BuyerRequirement;
use App\Models\CurrentSource;
use App\Models\SupplierCapacity;
use App\Models\SupplyGap;

/**
 * Section 3.17 — Opportunity Scoring, for real: replaces stage 3's
 * `opportunity_assessment_preliminary` gap/demand percentage (which now lives on inside this
 * composite as the `supply_gap_size` component, not the whole score). No specific supplier in
 * mind here — this assesses the requirement itself as a market opportunity, in general. See
 * config/mie_scoring.php for weights and WeightedScorer for the shared renormalization rule.
 */
class OpportunityScorer
{
    public function __construct(
        private readonly WeightedScorer $weightedScorer,
        private readonly MatchScorer $matchScorer,
    ) {}

    public function score(BuyerRequirement $requirement): array
    {
        $gap = $requirement->supplyGap;
        $uncoveredVolume = $gap?->gap() ?? (float) $requirement->volume;
        $productFormId = $requirement->product->product_form_id;

        $components = [
            'demand_strength' => $this->demandStrength($requirement),
            'price' => $this->price($requirement),
            'supply_gap_size' => $this->supplyGapSize($gap),
            'origin_suitability' => $this->originSuitability($productFormId),
            // Reuses MatchScorer's exact same precedent-based proxy, at the market level (no
            // specific supplier country — pass null) instead of recomputing it.
            'logistics_feasibility' => $this->matchScorer->logisticsFeasibility($requirement->market->country_id, null),
            'competitive_intensity' => $this->competitiveIntensity($requirement),
            'compliance_fit' => $this->complianceFit($requirement, $productFormId),
        ];

        $result = $this->weightedScorer->score($components, config('mie_scoring.opportunity.weights'));

        return [
            'composite_score' => $result['score'],
            'breakdown' => $result['breakdown'],
            'priority_tier' => $this->priorityTier($result['score']),
            'estimated_annual_opportunity_value' => $this->estimatedAnnualValue($requirement, $uncoveredVolume),
            'buyer_count' => $this->buyerCount($requirement),
            'supply_gap_volume' => $gap?->gap(),
        ];
    }

    /**
     * Requirement volume relative to the mean volume of every OTHER requirement for the same
     * product — a real average, not a hardcoded scale. A ratio of 1.0 (exactly typical) scores
     * 50; double the typical volume caps the score at 100. Null when there's no other requirement
     * for this product yet to compare against (no baseline exists).
     */
    private function demandStrength(BuyerRequirement $requirement): ?float
    {
        $averageOthers = BuyerRequirement::where('product_id', $requirement->product_id)
            ->where('id', '!=', $requirement->id)
            ->avg('volume');

        if ($averageOthers === null || (float) $averageOthers <= 0) {
            return null;
        }

        $ratio = (float) $requirement->volume / (float) $averageOthers;

        return round(min(100, $ratio * 50), 2);
    }

    /**
     * Whether real price discovery has occurred for this requirement (at least one existing
     * matched+offered price) — not whether the price is favorable, since no market-price
     * benchmark exists anywhere in this schema to compare against. Null (excluded) when no
     * priced offer exists yet for this requirement.
     */
    private function price(BuyerRequirement $requirement): ?float
    {
        $hasPricedOffer = $requirement->matches->pluck('offer.price')->filter()->isNotEmpty();

        return $hasPricedOffer ? 100.0 : null;
    }

    /**
     * The requirement's own real SupplyGap as a % of its total demand — this is exactly stage 3's
     * former `opportunity_assessment_preliminary` formula, now just one input into the composite
     * rather than the whole score. Null when no SupplyGap row exists for this requirement at all
     * (genuinely no data, not "0 gap").
     */
    private function supplyGapSize(?SupplyGap $gap): ?float
    {
        if (! $gap || (float) $gap->demand_volume <= 0) {
            return null;
        }

        return round(max(0, min(100, ($gap->gap() / (float) $gap->demand_volume) * 100)), 2);
    }

    private function originSuitability(int $productFormId): float
    {
        return SupplierCapacity::where('product_form_id', $productFormId)->exists() ? 100.0 : 0.0;
    }

    private function competitiveIntensity(BuyerRequirement $requirement): float
    {
        $existingSourceCount = CurrentSource::where('buyer_requirement_id', $requirement->id)->count();
        $penaltyPerSource = (float) config('mie_scoring.opportunity.competitive_intensity_penalty_per_source');

        return max(0.0, 100.0 - ($existingSourceCount * $penaltyPerSource));
    }

    /**
     * Whether at least one supplier IN THE SYSTEM (not a specific candidate) meets the
     * requirement's certification requirement, if it has one. Null only when the requirement
     * needs a certification AND no supplier_capacity for this product form has recorded ANY
     * certification data at all — a genuine data gap, distinct from "we checked and nobody
     * qualifies" (which is a real, decisive 0).
     */
    private function complianceFit(BuyerRequirement $requirement, int $productFormId): ?float
    {
        $requiredCertification = $requirement->specification['certification'] ?? null;

        if (! $requiredCertification) {
            return 100.0;
        }

        $capacitiesWithCertData = SupplierCapacity::where('product_form_id', $productFormId)
            ->whereNotNull('certifications')
            ->get();

        if ($capacitiesWithCertData->isEmpty()) {
            return null;
        }

        $anyMatch = $capacitiesWithCertData->contains(
            fn ($capacity) => collect($capacity->certifications)
                ->contains(fn ($certification) => strcasecmp((string) $certification, (string) $requiredCertification) === 0)
        );

        return $anyMatch ? 100.0 : 0.0;
    }

    private function priorityTier(float $score): string
    {
        $tiers = config('mie_scoring.opportunity.priority_tiers');

        if ($score >= $tiers['high']) {
            return 'high';
        }

        if ($score >= $tiers['medium']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * uncovered_volume × frequency-annualized × average matched offer price. Null whenever no
     * priced offer exists for this requirement — not fabricated. An unset frequency is treated
     * as one-time (multiplier 1), matching RequirementFrequency::OneTime's own semantics.
     */
    private function estimatedAnnualValue(BuyerRequirement $requirement, float $uncoveredVolume): ?float
    {
        $prices = $requirement->matches->pluck('offer.price')->filter();

        if ($prices->isEmpty()) {
            return null;
        }

        $multiplier = $requirement->frequency?->annualMultiplier() ?? 1;

        return round($uncoveredVolume * $multiplier * (float) $prices->avg(), 2);
    }

    private function buyerCount(BuyerRequirement $requirement): int
    {
        return BuyerRequirement::where('product_id', $requirement->product_id)
            ->where('status', BuyerRequirementStatus::Open->value)
            ->distinct('buyer_id')
            ->count('buyer_id');
    }
}
