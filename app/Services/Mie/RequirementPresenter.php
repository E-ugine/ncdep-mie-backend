<?php

namespace App\Services\Mie;

use App\Models\BuyerRequirement;
use App\Models\SupplyGap;

/**
 * Shared per-requirement enrichment logic. Originally inline in MarketScanController (section
 * 3.2); section 3.4's requirement-detail endpoint and section 3.3's buyer profile
 * (`current_open_needs`) need almost the identical field set, so it's extracted here and reused
 * by all three rather than duplicated three times — the task explicitly asked for this reuse.
 */
class RequirementPresenter
{
    public const EAGER_LOADS = [
        'buyer.country',
        'product.productForm.commodity',
        'market.country',
        'supplyGap',
        'matches.offer',
        'currentSources.country',
    ];

    public function present(BuyerRequirement $requirement): array
    {
        $gap = $requirement->supplyGap;
        $prices = $requirement->matches->pluck('offer.price')->filter()->map(fn ($price) => (float) $price);
        $specification = $requirement->specification;

        return [
            'id' => $requirement->id,
            'buyer' => [
                'id' => $requirement->buyer->id,
                'name' => $requirement->buyer->name,
            ],
            'product' => [
                'id' => $requirement->product->id,
                'name' => $requirement->product->name,
                'form' => $requirement->product->productForm->state->value,
                'commodity' => $requirement->product->productForm->commodity->name,
            ],
            'market' => [
                'id' => $requirement->market->id,
                'name' => $requirement->market->name,
                'country' => $requirement->market->country->name,
            ],
            // Section 3.4 calls this "destination" — an additive alias of `market`, not a
            // replacement: section 3.2's market-scan response already ships `market` and its
            // tests assert that key, so it stays as-is.
            'destination' => [
                'country' => $requirement->market->country->name,
                'market' => $requirement->market->name,
            ],
            'volume' => (float) $requirement->volume,
            'frequency' => $requirement->frequency?->value,
            'status' => $requirement->status->value,
            'incoterm' => $requirement->incoterm?->value,
            'specification' => $specification,
            // Section 3.4 lists grade/packaging/certification separately from "specification"
            // even though they live inside the same open-ended JSON blob — pulled out here as
            // convenience aliases, not new columns.
            'grade' => $specification['grade'] ?? null,
            'packaging' => $specification['packaging'] ?? null,
            'certification' => $specification['certification'] ?? null,
            'current_source' => $requirement->currentSources->map(fn ($source) => [
                'country' => $source->country->name,
                'supplier_name' => $source->supplier_name,
                'estimated_volume' => $source->estimated_volume !== null ? (float) $source->estimated_volume : null,
            ])->values(),
            'supply_gap' => $gap ? [
                'demand_volume' => (float) $gap->demand_volume,
                'contracted_volume' => (float) $gap->contracted_volume,
                'gap' => $gap->gap(),
            ] : null,
            // Section 3.4 names this "additional/uncovered volume" — same number as supply_gap.gap.
            'uncovered_volume' => $gap?->gap(),
            'price_range' => [
                'min' => $prices->isNotEmpty() ? $prices->min() : null,
                'max' => $prices->isNotEmpty() ? $prices->max() : null,
            ],
            'delivery_window' => [
                'start' => $requirement->delivery_window_start?->toDateString(),
                'end' => $requirement->delivery_window_end?->toDateString(),
            ],
            'opportunity_assessment_preliminary' => $this->preliminaryOpportunityAssessment($gap),
        ];
    }

    /**
     * Honest, simple proxy — NOT the weighted section 3.17 engine (that's stage 7).
     * = (gap / demand_volume) * 100, clamped to [0, 100]: the share of total demand for this
     * requirement that remains uncontracted. Null when there's no supply-gap data to compute
     * from (no fake fallback number).
     */
    private function preliminaryOpportunityAssessment(?SupplyGap $gap): ?float
    {
        if (! $gap || (float) $gap->demand_volume <= 0) {
            return null;
        }

        $ratio = $gap->gap() / (float) $gap->demand_volume;

        return round(max(0, min(100, $ratio * 100)), 2);
    }
}
