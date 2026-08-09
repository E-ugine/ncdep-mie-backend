<?php

namespace App\Http\Controllers\Mie;

use App\Enums\NegotiationStatus;
use App\Enums\OfferStatus;
use App\Http\Controllers\Controller;
use App\Models\BuyerRequirement;
use App\Models\Negotiation;
use App\Models\Offer;
use App\Models\SavedRequirement;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Services\Mie\ConversationMessenger;
use App\Services\Mie\RequirementPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Section 3.4 — Requirements Exchange: single-requirement detail plus the six user actions.
 * Detail reuses the same RequirementPresenter as market-scan and the buyer profile's
 * current_open_needs — see that class for why. message() reuses ConversationMessenger, shared
 * with section 3.12's deal/contract messaging (DealController/ContractController).
 */
class RequirementController extends Controller
{
    public function __construct(
        private readonly RequirementPresenter $presenter,
        private readonly ConversationMessenger $messenger,
    ) {}

    public function show(int $id): JsonResponse
    {
        $requirement = BuyerRequirement::with(RequirementPresenter::EAGER_LOADS)->findOrFail($id);

        return response()->json($this->presenter->present($requirement));
    }

    /**
     * Naive match: does any supplier_capacity row for this requirement's product_form have
     * enough available volume? If so, create a real `matches` row against that supplier. This is
     * NOT section 3.16's AI matching — the score is a fixed, obviously-fake placeholder, not a
     * formula, so nobody downstream mistakes it for a real assessment. That's stage 7's job.
     */
    public function match(int $id): JsonResponse
    {
        $requirement = BuyerRequirement::with('product')->findOrFail($id);

        $capacity = SupplierCapacity::where('product_form_id', $requirement->product->product_form_id)
            ->where('available_volume', '>=', $requirement->volume)
            ->orderByDesc('available_volume')
            ->first();

        if (! $capacity) {
            return response()->json([
                'matched' => false,
                'message' => 'No supplier currently has enough available capacity for this requirement.',
                'code' => 'no_supplier_capacity_available',
            ]);
        }

        $match = SupplierMatch::create([
            'buyer_requirement_id' => $requirement->id,
            'supplier_id' => $capacity->supplier_id,
            // PLACEHOLDER — not a real score. Section 3.16's AI matching (stage 7) replaces this.
            // Fixed at 50 deliberately so it can never be mistaken for a computed assessment.
            'score' => 50,
            'reason' => [
                'note' => 'Placeholder match: naive product-form + available-volume check only. No real scoring applied (section 3.16 AI matching is stage 7).',
            ],
            'fulfillable_volume' => min((float) $requirement->volume, (float) $capacity->available_volume),
        ]);

        return response()->json([
            'matched' => true,
            'match' => [
                'id' => $match->id,
                'supplier_id' => $match->supplier_id,
                'score' => $match->score,
                'fulfillable_volume' => (float) $match->fulfillable_volume,
                'reason' => $match->reason,
            ],
        ], 201);
    }

    public function message(Request $request, int $id): JsonResponse
    {
        $requirement = BuyerRequirement::findOrFail($id);

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $result = $this->messenger->sendToConversable(
            $requirement,
            $request->user()->id,
            $validated['message'],
            "Requirement #{$requirement->id}",
        );

        return response()->json([
            'conversation_id' => $result['conversation']->id,
            'message' => [
                'id' => $result['message']->id,
                'body' => $result['message']->body,
                'sender_id' => $result['message']->sender_id,
                'created_at' => $result['message']->created_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Section 3.11's non-negotiable rule: contracts only originate from
     * Requirement → Match → Negotiation → Offer → Contract. An offer needs a match to attach to
     * — if none exists yet, this returns a clear 422 rather than silently creating one, enforcing
     * the chain at the earliest point it can be enforced.
     */
    public function offer(Request $request, int $id): JsonResponse
    {
        $requirement = BuyerRequirement::findOrFail($id);

        $validated = $request->validate([
            'match_id' => ['sometimes', 'integer', 'exists:matches,id'],
            'price' => ['required', 'numeric', 'min:0'],
            'volume' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'valid_until' => ['sometimes', 'date'],
        ]);

        $match = $this->resolveMatch($requirement, $validated['match_id'] ?? null);

        if (! $match) {
            return response()->json([
                'message' => 'No match exists for this requirement yet. Call /match first.',
                'code' => 'match_required',
            ], 422);
        }

        $offer = Offer::create([
            'match_id' => $match->id,
            'price' => $validated['price'],
            'volume' => $validated['volume'],
            'currency' => strtoupper($validated['currency']),
            'status' => OfferStatus::Pending,
            'valid_until' => $validated['valid_until'] ?? null,
        ]);

        return response()->json([
            'offer' => [
                'id' => $offer->id,
                'match_id' => $offer->match_id,
                'price' => (float) $offer->price,
                'volume' => (float) $offer->volume,
                'currency' => $offer->currency,
                'status' => $offer->status->value,
            ],
        ], 201);
    }

    /**
     * Same chain-integrity principle as offer(): a negotiation belongs to an offer (required FK
     * per section 2), so this needs an existing offer to attach to.
     */
    public function negotiate(Request $request, int $id): JsonResponse
    {
        $requirement = BuyerRequirement::findOrFail($id);

        $validated = $request->validate([
            'offer_id' => ['sometimes', 'integer', 'exists:offers,id'],
            'counter_price' => ['sometimes', 'numeric', 'min:0'],
            'counter_volume' => ['sometimes', 'numeric', 'min:0'],
            'notes' => ['sometimes', 'string'],
        ]);

        $offer = $this->resolveOffer($requirement, $validated['offer_id'] ?? null);

        if (! $offer) {
            return response()->json([
                'message' => 'No offer exists for this requirement yet. Call /offer first.',
                'code' => 'offer_required',
            ], 422);
        }

        $negotiation = Negotiation::create([
            'offer_id' => $offer->id,
            'status' => NegotiationStatus::Open,
            'counter_price' => $validated['counter_price'] ?? null,
            'counter_volume' => $validated['counter_volume'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json([
            'negotiation' => [
                'id' => $negotiation->id,
                'offer_id' => $negotiation->offer_id,
                'status' => $negotiation->status->value,
                'counter_price' => $negotiation->counter_price !== null ? (float) $negotiation->counter_price : null,
                'counter_volume' => $negotiation->counter_volume !== null ? (float) $negotiation->counter_volume : null,
            ],
        ], 201);
    }

    public function save(Request $request, int $id): JsonResponse
    {
        $requirement = BuyerRequirement::findOrFail($id);

        $saved = SavedRequirement::firstOrCreate([
            'user_id' => $request->user()->id,
            'buyer_requirement_id' => $requirement->id,
        ]);

        return response()->json([
            'saved' => true,
            'saved_requirement_id' => $saved->id,
        ], 201);
    }

    /**
     * No real sharing infrastructure this stage — no share tokens, expiry, or access control.
     * Just a fresh reference plus the canonical URL, exactly as the task asked for, not more.
     */
    public function share(int $id): JsonResponse
    {
        $requirement = BuyerRequirement::findOrFail($id);

        return response()->json([
            'share_reference' => (string) Str::uuid(),
            'share_url' => url("/api/mie/requirements/{$requirement->id}"),
            'note' => 'No real sharing infrastructure exists yet (no tokens, expiry, or access control) — this is a generated reference plus the canonical URL only.',
        ]);
    }

    private function resolveMatch(BuyerRequirement $requirement, ?int $matchId): ?SupplierMatch
    {
        if ($matchId) {
            return SupplierMatch::where('id', $matchId)
                ->where('buyer_requirement_id', $requirement->id)
                ->first();
        }

        return SupplierMatch::where('buyer_requirement_id', $requirement->id)->latest('id')->first();
    }

    private function resolveOffer(BuyerRequirement $requirement, ?int $offerId): ?Offer
    {
        $base = Offer::whereHas('match', fn ($q) => $q->where('buyer_requirement_id', $requirement->id));

        if ($offerId) {
            return $base->where('id', $offerId)->first();
        }

        return $base->latest('id')->first();
    }
}
