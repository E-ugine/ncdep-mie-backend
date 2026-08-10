<?php

namespace App\Services\Mie;

use App\Models\BuyerRequirement;
use App\Models\CurrentSource;
use App\Models\SupplierCapacity;
use Illuminate\Database\Eloquent\Collection;

/**
 * Section 3.16 — AI Market Matching, for real: replaces stage 4's hardcoded score-of-50 stub.
 * Scores ONE candidate supplier_capacity row against ONE requirement. See
 * config/mie_scoring.php for weights and WeightedScorer for the renormalization rule shared
 * with section 3.17's OpportunityScorer.
 */
class MatchScorer
{
    public function __construct(private readonly WeightedScorer $weightedScorer) {}

    /**
     * The qualifying candidate pool for a requirement: every supplier_capacity row for the same
     * product_form. Shared by RequirementController::match() (which scores and creates matches
     * rows) AND the notification observers (which only need to know WHO qualifies, not their
     * scores) — one query, not duplicated across both call sites.
     */
    public function candidatesFor(BuyerRequirement $requirement): Collection
    {
        return SupplierCapacity::where('product_form_id', $requirement->product->product_form_id)
            ->with(['supplier.users', 'supplier.country'])
            ->get();
    }

    /**
     * @return array{score: float, breakdown: array, fulfillable_volume: float, fulfillable_share: float}
     */
    public function score(BuyerRequirement $requirement, SupplierCapacity $capacity): array
    {
        $uncoveredVolume = $requirement->supplyGap?->gap() ?? (float) $requirement->volume;

        $components = [
            'capacity_fit' => $this->capacityFit($capacity, $uncoveredVolume),
            'spec_compliance' => $this->specCompliance($requirement, $capacity),
            'logistics_feasibility' => $this->logisticsFeasibility($requirement->market->country_id, $capacity->supplier->country_id),
            // No supplier states an asking price ahead of an offer anywhere in this schema —
            // there is no per-candidate price signal to compare at match time. Always excluded,
            // never fabricated.
            'price_fit' => null,
            'user_capability' => $this->userCapability($requirement, $capacity),
        ];

        $result = $this->weightedScorer->score($components, config('mie_scoring.match.weights'));

        $fulfillableVolume = min((float) $capacity->available_volume, max($uncoveredVolume, 0.0));
        $fulfillableShare = $uncoveredVolume > 0
            ? round(($fulfillableVolume / $uncoveredVolume) * 100, 2)
            : 100.0;

        return [
            'score' => $result['score'],
            'breakdown' => $result['breakdown'],
            'fulfillable_volume' => round($fulfillableVolume, 2),
            'fulfillable_share' => $fulfillableShare,
        ];
    }

    /**
     * Precedent-based logistics proxy, shared verbatim between the per-candidate match score
     * (a specific supplier country) and the requirement-level opportunity score (any supplier
     * country at all, pass null) — a stand-in for the ports/routes/freight data section 2 lists
     * but this build never populated. A route that's never been recorded isn't PROVEN
     * infeasible, so it scores 50 (neutral), not 0.
     */
    public function logisticsFeasibility(int $marketCountryId, ?int $supplierCountryId): float
    {
        $query = CurrentSource::whereHas('buyerRequirement.market', fn ($q) => $q->where('country_id', $marketCountryId));

        if ($supplierCountryId !== null) {
            $query->where('country_id', $supplierCountryId);
        }

        return $query->exists() ? 100.0 : 50.0;
    }

    private function capacityFit(SupplierCapacity $capacity, float $uncoveredVolume): float
    {
        if ($uncoveredVolume <= 0) {
            return 100.0;
        }

        return round(min(100, ((float) $capacity->available_volume / $uncoveredVolume) * 100), 2);
    }

    /**
     * Form-level only, per the task's explicit instruction: candidates are already filtered to
     * matching product_form_id before scoring (see candidatesFor()), so this is always 100 in
     * practice today. A real grade/packaging/moisture-level comparison would need per-supplier
     * spec data that doesn't exist in this schema — certification-level comparison is handled
     * separately by userCapability(), which IS real per-supplier data.
     */
    private function specCompliance(BuyerRequirement $requirement, SupplierCapacity $capacity): float
    {
        return $capacity->product_form_id === $requirement->product->product_form_id ? 100.0 : 0.0;
    }

    private function userCapability(BuyerRequirement $requirement, SupplierCapacity $capacity): ?float
    {
        $requiredCertification = $requirement->specification['certification'] ?? null;

        if (! $requiredCertification) {
            return 100.0; // nothing required of the supplier, trivially satisfied
        }

        if ($capacity->certifications === null) {
            return null; // genuinely no certification data recorded for this supplier — exclude, don't guess
        }

        $hasCertification = collect($capacity->certifications)
            ->contains(fn ($certification) => strcasecmp((string) $certification, (string) $requiredCertification) === 0);

        return $hasCertification ? 100.0 : 0.0;
    }
}
