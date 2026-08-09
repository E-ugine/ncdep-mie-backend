<?php

namespace App\Http\Controllers\Mie;

use App\Enums\BuyerRequirementStatus;
use App\Enums\BuyerVerificationStatus;
use App\Enums\DealPipelineStage;
use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\CurrentSource;
use App\Services\Mie\RequirementPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Section 3.3 — Buyer Intelligence Profiles.
 *
 * Most of the spec's field list is deliberately NOT stored as columns: operating markets,
 * products purchased/sold, current suppliers + countries, historical buying activity, existing
 * contracts, open requirements — all of these are computed live from buyer_requirements /
 * current_sources / matches / offers / negotiations / deals / contracts, per the task's explicit
 * instruction to use real relations rather than free-text duplicates of data that already has a
 * home in the schema.
 */
class BuyerController extends Controller
{
    private const SUPPORTED_FILTER_KEYS = ['country', 'buyer_type', 'industry'];

    public function __construct(private readonly RequirementPresenter $presenter) {}

    public function index(Request $request): JsonResponse
    {
        $query = Buyer::query()->with('country');

        if ($request->filled('country')) {
            $countryTerm = $request->string('country')->toString();
            $query->whereHas('country', fn ($q) => $q->where('name', 'like', "%{$countryTerm}%")
                ->orWhere('iso_code', strtoupper($countryTerm)));
        }

        if ($request->filled('buyer_type')) {
            $query->where('buyer_type', $request->string('buyer_type')->toString());
        }

        if ($request->filled('industry')) {
            $industryTerm = $request->string('industry')->toString();
            $query->where('industry', 'like', "%{$industryTerm}%");
        }

        $unsupportedFilters = array_values(array_diff(array_keys($request->query()), self::SUPPORTED_FILTER_KEYS));

        $buyers = $query->get()->map(fn (Buyer $buyer) => [
            'id' => $buyer->id,
            'company' => $buyer->name,
            'country' => $buyer->country->name,
            'buyer_type' => $buyer->buyer_type?->value,
            'industry' => $buyer->industry,
            'verification_status' => $buyer->verification_status->value,
        ])->values();

        return response()->json([
            'unsupported_filters' => $unsupportedFilters,
            'buyers' => $buyers,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $buyer = Buyer::with([
            'country',
            'requirements.market.country',
            'requirements.product.productForm.commodity',
            'requirements.currentSources.country',
            'requirements.matches.offer.negotiation.deal.contract',
            'requirements.supplyGap',
        ])->findOrFail($id);

        return response()->json($this->buildProfile($buyer));
    }

    private function buildProfile(Buyer $buyer): array
    {
        $requirements = $buyer->requirements;

        $operatingMarkets = $requirements->pluck('market')->filter()->unique('id')->values()
            ->map(fn ($market) => [
                'id' => $market->id,
                'name' => $market->name,
                'country' => $market->country->name,
            ])->values();

        $productsPurchased = $requirements->pluck('product')->filter()->unique('id')->values()
            ->map(fn ($product) => ['id' => $product->id, 'name' => $product->name])->values();

        $frequencyBreakdown = $requirements->whereNotNull('frequency')
            ->groupBy(fn ($requirement) => $requirement->frequency->value)
            ->map(fn ($group, $frequency) => ['frequency' => $frequency, 'requirement_count' => $group->count()])
            ->values();

        $annualProcurementEstimate = $requirements->whereNotNull('frequency')
            ->sum(fn ($requirement) => (float) $requirement->volume * $requirement->frequency->annualMultiplier());

        $typicalOrderSize = $requirements->isNotEmpty() ? round((float) $requirements->avg('volume'), 2) : null;

        $specifications = $requirements->pluck('specification')->filter();
        $certificationsRequired = $specifications->pluck('certification')->filter()->unique()->values();

        $currentSuppliers = CurrentSource::whereIn('buyer_requirement_id', $requirements->pluck('id'))
            ->with('country')
            ->get()
            ->map(fn (CurrentSource $source) => [
                'supplier_name' => $source->supplier_name,
                'country' => $source->country->name,
                'estimated_volume' => $source->estimated_volume !== null ? (float) $source->estimated_volume : null,
            ])->values();

        [$deals, $contracts] = $this->historicalActivity($requirements);

        $openRequirements = $requirements->where('status', BuyerRequirementStatus::Open)->values();
        $currentOpenNeeds = $openRequirements->map(fn ($requirement) => $this->presenter->present($requirement))->values();

        $tradeReadiness = ($buyer->verification_status === BuyerVerificationStatus::Verified && $openRequirements->isNotEmpty())
            ? 'ready'
            : 'incomplete';

        $totalDeals = $deals->count();
        $completedDeals = $deals->where('pipeline_stage', DealPipelineStage::Completed)->count();
        $reliabilityIndicators = $totalDeals > 0 ? [
            'completed_deal_count' => $completedDeals,
            'total_deal_count' => $totalDeals,
            'completion_rate' => round($completedDeals / $totalDeals * 100, 2),
        ] : null;

        return [
            'id' => $buyer->id,
            'company' => $buyer->name,
            'country' => [
                'id' => $buyer->country->id,
                'name' => $buyer->country->name,
                'iso_code' => $buyer->country->iso_code,
            ],
            'hq' => $buyer->hq,
            'buyer_type' => $buyer->buyer_type?->value,
            'industry' => $buyer->industry,
            'verification_status' => $buyer->verification_status->value,
            'payment_terms' => $buyer->payment_terms,
            'currency' => $buyer->currency,
            'preferred_ports' => $buyer->preferred_ports,
            'logistics_preferences' => $buyer->logistics_preferences,

            'operating_markets' => $operatingMarkets,
            'products_purchased_sold' => $productsPurchased,
            'annual_procurement_volume_estimate' => round($annualProcurementEstimate, 2),
            'procurement_frequency_breakdown' => $frequencyBreakdown,
            'typical_order_size' => $typicalOrderSize,
            'preferred_specifications' => $specifications->unique()->values(),
            'certifications_required' => $certificationsRequired,
            'current_suppliers' => $currentSuppliers,

            'historical_buying_activity' => $deals->map(fn ($deal) => [
                'deal_id' => $deal->id,
                'pipeline_stage' => $deal->pipeline_stage->value,
                'agreed_price' => (float) $deal->agreed_price,
                'agreed_volume' => (float) $deal->agreed_volume,
                'currency' => $deal->currency,
            ])->values(),
            'existing_contracts' => $contracts->map(fn ($contract) => [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'status' => $contract->status->value,
                'value' => (float) $contract->value,
                'volume' => (float) $contract->volume,
                'currency' => $contract->currency,
            ])->values(),

            'open_requirements_count' => $openRequirements->count(),
            'current_open_needs' => $currentOpenNeeds,
            'active_rfqs' => $currentOpenNeeds,
            'market_relationships' => $operatingMarkets,

            'trade_readiness' => $tradeReadiness,
            'reliability_indicators' => $reliabilityIndicators,
            'sustainability_indicators' => null,

            'notes' => [
                'trade_readiness' => 'Simple real proxy: verification_status == verified AND at least one open requirement. Not a scored readiness engine (that would be later-stage work).',
                'reliability_indicators' => "Computed from this buyer's real deal history (completed vs. total deals reachable via its requirements' matches/offers/negotiations/deals). Null when no deal history exists yet.",
                'sustainability_indicators' => 'No certification/sustainability data exists anywhere in the schema yet — returned as null rather than a fabricated score.',
                'annual_procurement_volume_estimate' => "Estimated by annualizing each requirement's volume by its stated frequency (weekly=52/yr, monthly=12/yr, etc.); requirements without a stated frequency are excluded from the sum.",
                'active_rfqs' => "No distinct RFQ entity exists in the schema (deferred at section 2's build) — presented as this buyer's open requirements.",
                'market_relationships' => 'Alias of operating_markets — no separate relationship-strength data exists yet.',
            ],
        ];
    }

    /**
     * @return array{0: Collection, 1: Collection} [deals, contracts] reachable from this buyer's
     *                                              requirements via matches → offer → negotiation → deal (→ contract).
     */
    private function historicalActivity(Collection $requirements): array
    {
        $deals = collect();
        $contracts = collect();

        foreach ($requirements as $requirement) {
            foreach ($requirement->matches as $match) {
                $deal = optional(optional($match->offer)->negotiation)->deal;

                if ($deal) {
                    $deals->push($deal);

                    if ($deal->contract) {
                        $contracts->push($deal->contract);
                    }
                }
            }
        }

        return [$deals->unique('id')->values(), $contracts->unique('id')->values()];
    }
}
