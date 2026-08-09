<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ProductFormState;
use App\Http\Controllers\Controller;
use App\Models\BuyerRequirement;
use App\Models\SupplyGap;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Section 3.2 — Global Market Scan. Filters that map to real (or section-3.1/3.2-anticipated)
 * schema are actually enforced; filters with no schema anywhere (variety, region, industry,
 * certification) are accepted so the frontend never gets a spurious 422, but are listed back
 * in `unsupported_filters` rather than silently ignored — see task summary for why.
 */
class MarketScanController extends Controller
{
    private const UNSUPPORTED_FILTER_KEYS = ['variety', 'region', 'industry', 'certification'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'commodity' => ['sometimes', 'string'],
            'product' => ['sometimes', 'string'],
            'variety' => ['sometimes', 'string'],
            'form' => ['sometimes', Rule::enum(ProductFormState::class)],
            'country' => ['sometimes', 'string'],
            'region' => ['sometimes', 'string'],
            'buyer' => ['sometimes', 'string'],
            'industry' => ['sometimes', 'string'],
            'processing_level' => ['sometimes', Rule::enum(ProductFormState::class)],
            'certification' => ['sometimes', 'string'],
            'volume_min' => ['sometimes', 'numeric', 'min:0'],
            'price_min' => ['sometimes', 'numeric', 'min:0'],
            'price_max' => ['sometimes', 'numeric', 'min:0', 'gte:price_min'],
            'delivery_period' => ['sometimes', 'date'],
        ]);

        // Two independent query instances on purpose: the aggregate below applies a custom
        // select()/groupBy() and must NOT carry the other query's eager loads — a single shared,
        // eager-loaded builder here previously leaked empty relation keys (buyer, matches, etc.)
        // into the aggregate rows, since Eloquent tries to hydrate `with()` relations onto
        // whatever the select() produced.
        $demandByRegion = tap(BuyerRequirement::query(), fn ($q) => $this->applyFilters($q, $validated))
            ->join('markets', 'markets.id', '=', 'buyer_requirements.market_id')
            ->join('countries', 'countries.id', '=', 'markets.country_id')
            ->select('countries.id as country_id', 'countries.name as country')
            ->selectRaw('SUM(buyer_requirements.volume) as total_demand_volume')
            ->selectRaw('COUNT(*) as requirement_count')
            ->groupBy('countries.id', 'countries.name')
            ->get()
            ->map(fn ($row) => [
                'country_id' => $row->country_id,
                'country' => $row->country,
                'total_demand_volume' => (float) $row->total_demand_volume,
                'requirement_count' => (int) $row->requirement_count,
            ]);

        $query = BuyerRequirement::query()->with([
            'buyer.country',
            'product.productForm.commodity',
            'market.country',
            'supplyGap',
            'matches.offer',
            'currentSources.country',
        ]);

        $this->applyFilters($query, $validated);

        $requirements = $query->get();

        $unsupportedFilters = array_values(array_intersect(array_keys($validated), self::UNSUPPORTED_FILTER_KEYS));

        return response()->json([
            'filters_applied' => collect($validated)->except($unsupportedFilters)->all(),
            'unsupported_filters' => $unsupportedFilters,
            'demand_by_region' => $demandByRegion,
            'buyers' => $requirements->pluck('buyer')->unique('id')->values()->map(fn ($buyer) => [
                'id' => $buyer->id,
                'name' => $buyer->name,
                'country' => $buyer->country->name,
            ]),
            'requirements' => $requirements->map(fn ($requirement) => $this->presentRequirement($requirement))->values(),
        ]);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['commodity'])) {
            $query->whereHas('product.productForm.commodity', fn ($q) => $q->where('name', 'like', "%{$filters['commodity']}%"));
        }

        if (! empty($filters['product'])) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'like', "%{$filters['product']}%"));
        }

        // `form` and `processing_level` are the same underlying column (product_forms.state) —
        // the schema only models one degree-of-processing concept. If both are given, both apply
        // (harmless — they either agree or the query correctly returns nothing).
        if (! empty($filters['form'])) {
            $query->whereHas('product.productForm', fn ($q) => $q->where('state', $filters['form']));
        }

        if (! empty($filters['processing_level'])) {
            $query->whereHas('product.productForm', fn ($q) => $q->where('state', $filters['processing_level']));
        }

        if (! empty($filters['country'])) {
            $query->whereHas('market.country', fn ($q) => $q->where('name', 'like', "%{$filters['country']}%")
                ->orWhere('iso_code', strtoupper($filters['country'])));
        }

        if (! empty($filters['buyer'])) {
            $query->whereHas('buyer', fn ($q) => $q->where('name', 'like', "%{$filters['buyer']}%"));
        }

        if (isset($filters['volume_min'])) {
            $query->where('volume', '>=', $filters['volume_min']);
        }

        if (isset($filters['price_min']) || isset($filters['price_max'])) {
            $query->whereHas('matches.offer', function ($q) use ($filters) {
                if (isset($filters['price_min'])) {
                    $q->where('price', '>=', $filters['price_min']);
                }
                if (isset($filters['price_max'])) {
                    $q->where('price', '<=', $filters['price_max']);
                }
            });
        }

        if (! empty($filters['delivery_period'])) {
            $query->where('delivery_window_start', '<=', $filters['delivery_period'])
                ->where('delivery_window_end', '>=', $filters['delivery_period']);
        }
    }

    private function presentRequirement(BuyerRequirement $requirement): array
    {
        $gap = $requirement->supplyGap;
        $prices = $requirement->matches->pluck('offer.price')->filter()->map(fn ($price) => (float) $price);

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
            'volume' => (float) $requirement->volume,
            'frequency' => $requirement->frequency?->value,
            'status' => $requirement->status->value,
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
            'price_range' => [
                'min' => $prices->isNotEmpty() ? $prices->min() : null,
                'max' => $prices->isNotEmpty() ? $prices->max() : null,
            ],
            'delivery_window' => [
                'start' => $requirement->delivery_window_start?->toDateString(),
                'end' => $requirement->delivery_window_end?->toDateString(),
            ],
            'specification' => $requirement->specification,
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
