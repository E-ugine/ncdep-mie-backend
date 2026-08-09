<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Http\Controllers\Controller;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Message;
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

        return response()->json([
            // No user-to-supplier linkage exists anywhere in the schema yet (section 2 and
            // section 1.1 both deliberately left buyers/suppliers unlinked to `users`), so
            // these aggregates cannot be scoped to "the user's capacity" as the spec envisions.
            // Once that linkage exists, this should become 'supplier' for linked users and
            // filter accordingly.
            'scope' => 'global',
            'scope_note' => 'No user-to-supplier linkage exists in the schema yet; returning global aggregates. See task summary.',

            'new_buyer_requirements' => [
                'count' => BuyerRequirement::where('created_at', '>=', now()->subDays($newRequirementDays))->count(),
                'window_days' => $newRequirementDays,
            ],

            // gap > 0 is exactly SupplyGap::gap()'s formula (demand_volume - contracted_volume),
            // expressed as a real SQL predicate instead of loading every row into PHP to call it.
            'open_supply_gaps_count' => SupplyGap::whereRaw('demand_volume - contracted_volume > 0')->count(),

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
            'new_market_opportunities_count' => BuyerRequirement::doesntHave('matches')->count(),

            'total_addressable_opportunity_value' => $this->totalAddressableOpportunityValue(),
        ]);
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

    /**
     * Sum of (volume * average matched offer price) for requirements that don't already have a
     * completed deal. Requirements with no priced offers yet contribute 0 rather than erroring —
     * there's no persisted "market price" table (section 2 never built Prices/Demand/Supply as
     * their own tables), so offers.price via matches is the only real price signal available.
     */
    private function totalAddressableOpportunityValue(): float
    {
        $requirements = BuyerRequirement::with('matches.offer.negotiation.deal')->get();

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
