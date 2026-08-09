<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 3.13 — User Dashboard. Every figure traces to a real query, same discipline as the
 * section 3.1 command center. `my_supply`/`my_deals`/`my_money`/`my_documents` are all only
 * reachable via the user's linked supplier (users.supplier_id, closed in stage 4's Part A) —
 * deals/contracts in this schema connect to a supplier via match, never directly to a user, so
 * "mine" can only mean "my linked supplier's." Without that link, each section returns an honest
 * empty state with a note, mirroring the command center's scope_note pattern, rather than a
 * global fallback (a personal dashboard showing everyone's numbers would be actively misleading).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $supplier = $user->supplier;

        return response()->json([
            'my_market' => $this->myMarket($user),
            'my_supply' => $this->mySupply($supplier),
            'my_deals' => $this->myDeals($supplier),
            'my_money' => $this->myMoney($supplier),
            'my_documents' => $this->myDocuments($supplier),
        ]);
    }

    private function myMarket(User $user): array
    {
        $saved = $user->savedRequirements()->with(['buyerRequirement.buyer', 'buyerRequirement.product'])->get();

        return [
            'saved_requirements' => $saved->map(fn ($saved) => [
                'saved_requirement_id' => $saved->id,
                'buyer_requirement_id' => $saved->buyer_requirement_id,
                'product' => $saved->buyerRequirement?->product?->name,
                'buyer' => $saved->buyerRequirement?->buyer?->name,
                'volume' => $saved->buyerRequirement ? (float) $saved->buyerRequirement->volume : null,
                'status' => $saved->buyerRequirement?->status?->value,
                'saved_at' => $saved->created_at->toISOString(),
            ])->values(),

            // Section 3.18 (Market Watch) territory, explicitly out of this stage's scope — no
            // "follow a market" or watchlist concept exists in the schema. Honest empty state,
            // not a placeholder feature.
            'followed_markets' => [],
            'followed_markets_note' => "No 'follow a market' concept exists in the schema — that's section 3.18 (Market Watch), not built this stage.",
            'price_watchlist' => [],
            'price_watchlist_note' => 'Same as followed_markets — price watchlisting belongs to section 3.18, not this stage.',
        ];
    }

    private function mySupply(?Supplier $supplier): array
    {
        if (! $supplier) {
            return [
                'capacity' => [],
                'note' => 'This user has no linked supplier profile (users.supplier_id is null) — returning an empty array. Link a supplier profile to see capacity here.',
            ];
        }

        $capacity = $supplier->capacity()->with('productForm.commodity')->get();

        return [
            'capacity' => $capacity->map(fn ($row) => [
                'id' => $row->id,
                'product_form' => [
                    'id' => $row->productForm->id,
                    'state' => $row->productForm->state->value,
                    'commodity' => $row->productForm->commodity->name,
                ],
                'capacity_volume' => (float) $row->capacity_volume,
                'available_volume' => (float) $row->available_volume,
                // No certifications field exists on suppliers or supplier_capacity anywhere in
                // the schema — null per row rather than fabricated, see certifications_note.
                'certifications' => null,
            ])->values(),
            'certifications_note' => 'No certifications field exists on suppliers or supplier_capacity in the schema yet — returned as null rather than invented.',
        ];
    }

    private function myDeals(?Supplier $supplier): array
    {
        if (! $supplier) {
            return [
                'by_stage' => collect(DealPipelineStage::cases())->mapWithKeys(fn ($stage) => [$stage->value => []]),
                'total_count' => 0,
                'note' => 'This user has no linked supplier profile — returning empty groups for every pipeline stage. Deals connect to a supplier only via match -> offer -> negotiation -> deal, never directly to a user.',
            ];
        }

        $deals = $this->supplierDeals($supplier)->with('negotiation.offer.match.buyerRequirement.buyer')->get();

        $byStage = collect(DealPipelineStage::cases())->mapWithKeys(function ($stage) use ($deals) {
            $inStage = $deals->where('pipeline_stage', $stage)->values();

            return [$stage->value => $inStage->map(fn (Deal $deal) => [
                'id' => $deal->id,
                'agreed_price' => (float) $deal->agreed_price,
                'agreed_volume' => (float) $deal->agreed_volume,
                'currency' => $deal->currency,
                'buyer' => $deal->negotiation?->offer?->match?->buyerRequirement?->buyer?->name,
            ])->values()];
        });

        return ['by_stage' => $byStage, 'total_count' => $deals->count()];
    }

    private function myMoney(?Supplier $supplier): array
    {
        if (! $supplier) {
            return [
                'value_by_status' => ['draft' => 0.0, 'active' => 0.0, 'completed' => 0.0],
                'expected_revenue' => 0.0,
                'receivables' => 0.0,
                'note' => "This user has no linked supplier profile — every figure is 0 rather than a global aggregate, since 'my money' implies this user's own contracts specifically.",
            ];
        }

        $contracts = fn () => $this->supplierContracts($supplier);

        return [
            'value_by_status' => [
                'draft' => (float) $contracts()->where('status', ContractStatus::Draft->value)->sum('value'),
                'active' => (float) $contracts()->where('status', ContractStatus::Active->value)->sum('value'),
                'completed' => (float) $contracts()->where('status', ContractStatus::Completed->value)->sum('value'),
            ],
            // Sum of value for every contract that hasn't been cancelled — record-keeping only.
            'expected_revenue' => (float) $contracts()->where('status', '!=', ContractStatus::Cancelled->value)->sum('value'),
            // Receivables: goods already delivered (contract.shipment_status = delivered) but
            // payment not yet received (the deal's pipeline_stage is still payment_pending, i.e.
            // hasn't reached 'completed' yet). Pure aggregation of already-recorded state, no
            // payment processing logic.
            'receivables' => (float) $contracts()
                ->where('shipment_status', ShipmentStatus::Delivered->value)
                ->whereHas('deal', fn ($query) => $query->where('pipeline_stage', DealPipelineStage::PaymentPending->value))
                ->sum('value'),
            'receivables_definition' => "Contracts with shipment_status = 'delivered' whose deal's pipeline_stage = 'payment_pending' — delivered, not yet paid.",
        ];
    }

    private function myDocuments(?Supplier $supplier): array
    {
        if (! $supplier) {
            return [
                'contract_documents' => [],
                'supplier_certifications' => null,
                'note' => 'This user has no linked supplier profile — returning an empty document set.',
            ];
        }

        $contracts = $this->supplierContracts($supplier)->whereNotNull('documents')->get();

        return [
            'contract_documents' => $contracts->map(fn (Contract $contract) => [
                'contract_id' => $contract->id,
                'contract_number' => $contract->contract_number,
                'documents' => $contract->documents,
            ])->values(),
            // Same schema gap as my_supply's certifications field — no column exists yet.
            'supplier_certifications' => null,
            'certifications_note' => 'No certifications field exists on the suppliers table yet — returned as null rather than invented.',
        ];
    }

    private function supplierDeals(Supplier $supplier)
    {
        return Deal::whereHas('negotiation.offer.match', fn ($query) => $query->where('supplier_id', $supplier->id));
    }

    private function supplierContracts(Supplier $supplier)
    {
        return Contract::whereHas('deal.negotiation.offer.match', fn ($query) => $query->where('supplier_id', $supplier->id));
    }
}
