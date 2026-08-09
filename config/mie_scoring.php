<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Section 3.16 — AI Match Scoring (per candidate supplier_capacity row)
    |--------------------------------------------------------------------------
    |
    | Computed by App\Services\Mie\MatchScorer. Weights need not all contribute on every match —
    | see App\Services\Mie\WeightedScorer for how a component with no data (null) has its
    | configured weight redistributed proportionally among the components that DO have data,
    | rather than dragging the score down or being guessed at.
    */
    'match' => [
        'weights' => [
            // Can the supplier actually cover the shortfall? The single most decisive practical
            // factor in whether a match is usable at all.
            'capacity_fit' => 0.35,
            // Does the supplier's product form match the requirement's exactly? A hard
            // fresh/raw/processed gate — form-level only, not full grade/packaging/moisture
            // (see MatchScorer::specCompliance for why).
            'spec_compliance' => 0.25,
            // Precedent-based proxy for whether this trade route has been done before — real
            // signal, but a stand-in for the ports/routes data section 2 lists that this build
            // never populated.
            'logistics_feasibility' => 0.20,
            // Always null in practice: no supplier states an asking price ahead of an offer
            // anywhere in this schema, so there is no per-candidate price signal to compare at
            // match time. Kept in the table for inspectability; always renormalized away.
            'price_fit' => 0.10,
            // Does the supplier have the requirement's required certification recorded (if one
            // is required at all)?
            'user_capability' => 0.10,
        ],
        // A candidate scoring at or below this isn't worth creating a matches row for at all.
        'minimum_score_threshold' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Section 3.17 — Opportunity Scoring (per requirement, no specific supplier in mind)
    |--------------------------------------------------------------------------
    |
    | Computed by App\Services\Mie\OpportunityScorer, same renormalization discipline as 'match'.
    */
    'opportunity' => [
        'weights' => [
            // Is this a bigger-than-usual ask for this product? Bigger requirements are more
            // commercially attractive to chase.
            'demand_strength' => 0.20,
            // Whether real price discovery has already happened for this requirement (at least
            // one priced offer exists). This can't assess whether the price itself is
            // favorable — there's no market-price benchmark anywhere in this schema — only
            // whether real pricing data exists at all.
            'price' => 0.15,
            // The requirement's own real SupplyGap, as a % of its total demand.
            'supply_gap_size' => 0.20,
            // Does ANY supplier in the system have capacity for this product form at all —
            // is this producible from our supplier base, regardless of who's asking?
            'origin_suitability' => 0.15,
            // Same precedent-based proxy as the match score's logistics_feasibility, reused
            // (not recomputed) at the market level instead of per-candidate.
            'logistics_feasibility' => 0.10,
            // Inverse of how many current_sources already serve this requirement — more
            // existing suppliers means less room for a new entrant.
            'competitive_intensity' => 0.10,
            // Does at least one supplier in the system meet the requirement's certification
            // requirement (if any)?
            'compliance_fit' => 0.10,
        ],
        'priority_tiers' => [
            'high' => 70,
            'medium' => 40,
            // anything below 'medium' is 'low'
        ],
        // competitive_intensity: each existing current_sources row for this requirement reduces
        // the score by this many points, floored at 0.
        'competitive_intensity_penalty_per_source' => 25,
    ],

];
