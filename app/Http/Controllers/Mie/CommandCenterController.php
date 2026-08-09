<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Http\Controllers\Controller;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Supplier;
use App\Models\SupplyGap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 3.1 — Market Command Center. Every figure here is a real query against the
 * section 2 schema; where the schema can't yet answer part of the spec's ask (see the
 * `scope`/`*_note` fields below), that's stated in the response rather than faked.
 */
class CommandCenterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $newRequirementDays = (int) config('mie.command_center.new_requirement_days');

        // Part A of the "close the user↔supplier gap" work: users.supplier_id now exists. When
        // this user is linked to a supplier profile, the three metrics the spec frames as
        // capacity-relevant ("new opportunities," "open supply gaps," "total addressable
        // value") are scoped to product forms that supplier actually has capacity in. The other
        // metrics (new requirements, active deals, contracts awaiting action, unread messages)
        // stay global regardless of linkage — nothing in the task asked those to be scoped, and
        // they aren't capacity-relevant in the same way.
        $supplier = $user->supplier;
        $productFormIds = $supplier ? $this->supplierProductFormIds($supplier) : null;

        return response()->json([
            'scope' => $supplier ? 'supplier' : 'global',
            'scope_note' => $supplier
                ? "Scoped to the linked supplier profile (#{$supplier->id}: {$supplier->name}) via its product-form capacity."
                : 'This user has no linked supplier profile (users.supplier_id is null) — returning global aggregates. Link a supplier profile to scope these metrics.',

            'new_buyer_requirements' => [
                'count' => BuyerRequirement::where('created_at', '>=', now()->subDays($newRequirementDays))->count(),
                'window_days' => $newRequirementDays,
            ],

            // gap > 0 is exactly SupplyGap::gap()'s formula (demand_volume - contracted_volume),
            // expressed as a real SQL predicate instead of loading every row into PHP to call it.
            'open_supply_gaps_count' => $this->openSupplyGapsCount($productFormIds),

            'active_deals_count' => Deal::whereIn('pipeline_stage', [
                DealPipelineStage::Open->value,
                DealPipelineStage::Negotiating->value,
                DealPipelineStage::AwaitingBuyer->value,
                DealPipelineStage::AwaitingSupplier->value,
                DealPipelineStage::ContractPending->value,
            ])->count(),

            // "Awaiting action" is mapped to contracts.status = draft: a contract that hasn't
            // reached `active` yet is, by definition, still pending signature/review. No new
            // status was added — `draft` already means this.
            'contracts_awaiting_action_count' => Contract::where('status', ContractStatus::Draft->value)->count(),

            'unread_messages_count' => $this->unreadMessagesCount($user->id),

            // Proxy for "new market opportunities": unaddressed demand, i.e. requirements with
            // no supplier match yet at all. Full opportunity scoring is section 3.17 (stage 7).
            'new_market_opportunities_count' => $this->newMarketOpportunitiesCount($productFormIds),

            'total_addressable_opportunity_value' => $this->totalAddressableOpportunityValue($productFormIds),
        ]);
    }

    /**
     * @return array<int, int>|null the supplier's distinct product_form_ids, or null (meaning
     *                               "no supplier scope, apply no filter") when there's no supplier.
     */
    private function supplierProductFormIds(Supplier $supplier): array
    {
        return $supplier->capacity()->pluck('product_form_id')->unique()->values()->all();
    }

    /**
     * "Unread messages ... across conversations they're party to." There is no
     * conversation-participants concept in the schema (messages only record a sender_id, and
     * conversations morph to a requirement/deal/contract, not to users). As a real, honest
     * proxy: a conversation counts as one the user is "party to" if they've sent at least one
     * message in it; unread = other people's messages in those conversations with no read_at.
     * This should be replaced with real participant modeling once user-buyer/user-supplier
     * linkage (or an explicit conversation_participants table) exists.
     */
    private function unreadMessagesCount(int $userId): int
    {
        return Message::whereNull('read_at')
            ->where('sender_id', '!=', $userId)
            ->whereIn('conversation_id', function ($query) use ($userId) {
                $query->select('conversation_id')
                    ->from('messages')
                    ->where('sender_id', $userId);
            })
            ->count();
    }

    private function openSupplyGapsCount(?array $productFormIds): int
    {
        $query = SupplyGap::whereRaw('demand_volume - contracted_volume > 0');

        if ($productFormIds !== null) {
            $query->whereHas('buyerRequirement.product.productForm', fn ($q) => $q->whereIn('id', $productFormIds));
        }

        return $query->count();
    }

    private function newMarketOpportunitiesCount(?array $productFormIds): int
    {
        $query = BuyerRequirement::doesntHave('matches');

        if ($productFormIds !== null) {
            $query->whereHas('product.productForm', fn ($q) => $q->whereIn('id', $productFormIds));
        }

        return $query->count();
    }

    /**
     * Sum of (volume * average matched offer price) for requirements that don't already have a
     * completed deal. Requirements with no priced offers yet contribute 0 rather than erroring —
     * there's no persisted "market price" table (section 2 never built Prices/Demand/Supply as
     * their own tables), so offers.price via matches is the only real price signal available.
     */
    private function totalAddressableOpportunityValue(?array $productFormIds): float
    {
        $query = BuyerRequirement::with('matches.offer.negotiation.deal');

        if ($productFormIds !== null) {
            $query->whereHas('product.productForm', fn ($q) => $q->whereIn('id', $productFormIds));
        }

        $requirements = $query->get();

        $total = 0.0;

        foreach ($requirements as $requirement) {
            $hasCompletedDeal = $requirement->matches->contains(
                fn ($match) => optional(optional(optional($match->offer)->negotiation)->deal)?->pipeline_stage === DealPipelineStage::Completed
            );

            if ($hasCompletedDeal) {
                continue;
            }

            $prices = $requirement->matches->pluck('offer.price')->filter();

            if ($prices->isEmpty()) {
                continue;
            }

            $averagePrice = $prices->avg();
            $total += (float) $requirement->volume * (float) $averagePrice;
        }

        return round($total, 2);
    }
}
