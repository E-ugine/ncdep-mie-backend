<?php

namespace App\Services\Mie;

/**
 * Shared weighted-composite + renormalization logic for both section 3.16 (MatchScorer) and
 * section 3.17 (OpportunityScorer) — documented once here rather than twice.
 *
 * Renormalization approach: each component contributes a value in [0, 100], or null when there's
 * genuinely no data to compute it from for the thing being scored. A null component is EXCLUDED
 * entirely — its configured weight is not spent, and does not count against the score. The
 * weights of the components that DO have data are scaled up proportionally so they still sum to
 * 100% of the score: a present component's effective weight = its configured weight ÷ (sum of
 * configured weights of all present components). This means a component missing data never drags
 * the score down by default (there is no implicit "0 for missing data"), and the RELATIVE
 * importance between present components — as set in config/mie_scoring.php — is preserved even
 * when some components drop out.
 */
class WeightedScorer
{
    /**
     * @param  array<string, float|null>  $components  component name => value in [0,100], or null if no data
     * @param  array<string, float>  $weights  component name => configured weight (need not sum to 1; only relative size matters)
     * @return array{score: float, breakdown: array<string, array{value: float|null, weight: float, normalized_weight: float|null, contribution: float|null}>}
     */
    public function score(array $components, array $weights): array
    {
        $presentWeightTotal = 0.0;

        foreach ($components as $name => $value) {
            if ($value !== null) {
                $presentWeightTotal += $weights[$name] ?? 0.0;
            }
        }

        $breakdown = [];
        $score = 0.0;

        foreach ($components as $name => $value) {
            $weight = $weights[$name] ?? 0.0;
            $normalizedWeight = ($value !== null && $presentWeightTotal > 0) ? $weight / $presentWeightTotal : null;
            $contribution = $normalizedWeight !== null ? $value * $normalizedWeight : null;

            $breakdown[$name] = [
                'value' => $value,
                'weight' => $weight,
                'normalized_weight' => $normalizedWeight !== null ? round($normalizedWeight, 4) : null,
                'contribution' => $contribution !== null ? round($contribution, 2) : null,
            ];

            $score += $contribution ?? 0.0;
        }

        return [
            'score' => $presentWeightTotal > 0 ? round(max(0, min(100, $score)), 2) : 0.0,
            'breakdown' => $breakdown,
        ];
    }
}
