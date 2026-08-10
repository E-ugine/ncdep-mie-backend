<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Message;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplyGap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

            'supply_gaps' => $this->supplyGaps($productFormIds),

            'activity_feed' => $this->activityFeed($supplier, $productFormIds),
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

    /**
     * Ranked "largest supply gaps" table — same predicate and scoping as openSupplyGapsCount,
     * just returning rows instead of a count, ordered by gap size descending at the SQL level
     * rather than sorting every row in PHP.
     */
    private function supplyGaps(?array $productFormIds): Collection
    {
        $query = SupplyGap::whereRaw('demand_volume - contracted_volume > 0')
            ->with(['buyerRequirement.buyer', 'buyerRequirement.product.commodity', 'buyerRequirement.market']);

        if ($productFormIds !== null) {
            $query->whereHas('buyerRequirement.product.productForm', fn ($q) => $q->whereIn('id', $productFormIds));
        }

        return $query->orderByRaw('(demand_volume - contracted_volume) DESC')
            ->limit((int) config('mie.command_center.supply_gaps_limit'))
            ->get()
            ->map(fn (SupplyGap $gap) => [
                'buyer_requirement_id' => $gap->buyer_requirement_id,
                'commodity' => $gap->buyerRequirement->product->commodity->name,
                'market' => $gap->buyerRequirement->market?->name,
                'buyer' => $gap->buyerRequirement->buyer->name,
                'demand_volume' => (float) $gap->demand_volume,
                'contracted_volume' => (float) $gap->contracted_volume,
                'gap' => $gap->gap(),
            ])
            ->values();
    }

    /**
     * "Needs your action" — a mixed, real activity feed assembled from three genuinely queryable
     * sources (never narrative/invented text, see design-reference/design-tokens-v3.md on the
     * frontend for why): new requirements, real deal-stage transitions (DealEvent, written by
     * DealObserver — never a narrative audit trail), and confirmed payments (there is no
     * deposit/balance distinction in the schema, so this is always "Payment confirmed", never
     * "Deposit confirmed"). No offer/negotiation item type: neither has an audit table, only
     * created_at/updated_at, which isn't enough to truthfully describe what happened.
     */
    private function activityFeed(?Supplier $supplier, ?array $productFormIds): Collection
    {
        $since = now()->subDays((int) config('mie.command_center.activity_feed_days'));

        $newRequirements = BuyerRequirement::where('created_at', '>=', $since)
            ->with('buyer', 'product.commodity')
            ->when($productFormIds !== null, fn ($q) => $q->whereHas(
                'product.productForm',
                fn ($q2) => $q2->whereIn('id', $productFormIds)
            ))
            ->get()
            ->map(fn (BuyerRequirement $requirement) => [
                'type' => 'new_requirement',
                'text' => "New requirement: {$requirement->volume} {$requirement->product->commodity->name}, {$requirement->buyer->name}",
                'link' => ['type' => 'requirement', 'id' => $requirement->id],
                'created_at' => $requirement->created_at,
            ]);

        $stageChanges = DealEvent::where('event_type', DealEventType::StageTransition)
            ->where('created_at', '>=', $since)
            ->when($supplier, fn ($q) => $q->whereHas(
                'deal.negotiation.offer.match',
                fn ($q2) => $q2->where('supplier_id', $supplier->id)
            ))
            ->get()
            ->map(fn (DealEvent $event) => [
                'type' => 'deal_stage_change',
                'text' => "Deal #{$event->deal_id} advanced to {$this->humanizeStage($event->to_stage->value)}",
                'link' => ['type' => 'deal', 'id' => $event->deal_id],
                'created_at' => $event->created_at,
            ]);

        $payments = Payment::where('status', PaymentStatus::Paid)
            ->whereNotNull('paid_at')
            ->where('paid_at', '>=', $since)
            ->with('contract')
            ->when($supplier, fn ($q) => $q->whereHas(
                'contract.deal.negotiation.offer.match',
                fn ($q2) => $q2->where('supplier_id', $supplier->id)
            ))
            ->get()
            ->map(fn (Payment $payment) => [
                'type' => 'payment_confirmed',
                'text' => 'Payment of '.number_format((float) $payment->amount, 2)." {$payment->currency} confirmed against Deal #{$payment->contract->deal_id}",
                'link' => ['type' => 'deal', 'id' => $payment->contract->deal_id],
                'created_at' => $payment->paid_at,
            ]);

        return $newRequirements->concat($stageChanges)->concat($payments)
            ->sortByDesc(fn (array $item) => $item['created_at'])
            ->take((int) config('mie.command_center.activity_feed_limit'))
            ->map(fn (array $item) => [
                'type' => $item['type'],
                'text' => $item['text'],
                'link' => $item['link'],
                'created_at' => $item['created_at']->toISOString(),
            ])
            ->values();
    }

    private function humanizeStage(string $stage): string
    {
        return implode(' ', array_map('ucfirst', explode('_', $stage)));
    }
}
