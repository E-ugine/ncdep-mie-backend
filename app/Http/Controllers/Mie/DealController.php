<?php

namespace App\Http\Controllers\Mie;

use App\Enums\DealPipelineStage;
use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Services\Mie\DealStageTransitioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DealController extends Controller
{
    public function __construct(private readonly DealStageTransitioner $transitioner) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pipeline_stage' => ['sometimes', Rule::enum(DealPipelineStage::class)],
        ]);

        $query = Deal::query()
            ->withCount('events')
            ->with('negotiation.offer.match.buyerRequirement');

        if (isset($validated['pipeline_stage'])) {
            $query->where('pipeline_stage', $validated['pipeline_stage']);
        }

        $deals = $query->get()->map(fn (Deal $deal) => [
            'id' => $deal->id,
            'pipeline_stage' => $deal->pipeline_stage->value,
            'agreed_price' => (float) $deal->agreed_price,
            'agreed_volume' => (float) $deal->agreed_volume,
            'currency' => $deal->currency,
            'event_count' => $deal->events_count,
            'buyer_requirement' => $this->buyerRequirementSummary($deal),
        ])->values();

        return response()->json(['deals' => $deals]);
    }

    public function show(int $id): JsonResponse
    {
        $deal = Deal::with([
            'negotiation.offer.match.buyerRequirement.buyer',
            'negotiation.offer.match.supplier',
            'contract',
            'events' => fn ($query) => $query->orderBy('created_at')->orderBy('id'),
        ])->findOrFail($id);

        $match = optional($deal->negotiation?->offer)->match;
        $supplier = optional($match)->supplier;

        return response()->json([
            'id' => $deal->id,
            'pipeline_stage' => $deal->pipeline_stage->value,
            'agreed_price' => (float) $deal->agreed_price,
            'agreed_volume' => (float) $deal->agreed_volume,
            'currency' => $deal->currency,
            'buyer_requirement' => $this->buyerRequirementSummary($deal),
            'negotiation' => $deal->negotiation ? [
                'id' => $deal->negotiation->id,
                'status' => $deal->negotiation->status->value,
            ] : null,
            'offer' => $deal->negotiation?->offer ? [
                'id' => $deal->negotiation->offer->id,
                'price' => (float) $deal->negotiation->offer->price,
                'volume' => (float) $deal->negotiation->offer->volume,
            ] : null,
            'supplier' => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name] : null,
            'contract' => $deal->contract ? [
                'id' => $deal->contract->id,
                'contract_number' => $deal->contract->contract_number,
                'status' => $deal->contract->status->value,
            ] : null,
            'allowed_next_stages' => $this->transitioner->allowedNextStages($deal->pipeline_stage),
            'timeline' => $deal->events->map(fn ($event) => [
                'id' => $event->id,
                'event_type' => $event->event_type->value,
                'from_stage' => $event->from_stage?->value,
                'to_stage' => $event->to_stage->value,
                'actor_user_id' => $event->actor_user_id,
                'metadata' => $event->metadata,
                'created_at' => $event->created_at->toISOString(),
            ])->values(),
        ]);
    }

    /**
     * Enforces the explicit DealStageTransitioner::TRANSITIONS map — a rejected transition
     * returns 422 with the actual current stage and what IS allowed next, rather than a bare error.
     */
    public function updateStage(Request $request, int $id): JsonResponse
    {
        $deal = Deal::findOrFail($id);

        $validated = $request->validate([
            'pipeline_stage' => ['required', Rule::enum(DealPipelineStage::class)],
        ]);

        $to = DealPipelineStage::from($validated['pipeline_stage']);
        $from = $deal->pipeline_stage;

        if (! $this->transitioner->transition($deal, $to)) {
            return response()->json([
                'message' => "Cannot transition from '{$from->value}' to '{$to->value}'.",
                'code' => 'invalid_stage_transition',
                'current_stage' => $from->value,
                'allowed_next_stages' => $this->transitioner->allowedNextStages($from),
            ], 422);
        }

        return response()->json([
            'deal' => [
                'id' => $deal->id,
                'pipeline_stage' => $deal->pipeline_stage->value,
            ],
        ]);
    }

    private function buyerRequirementSummary(Deal $deal): ?array
    {
        $requirement = optional($deal->negotiation?->offer?->match)->buyerRequirement;

        if (! $requirement) {
            return null;
        }

        return [
            'id' => $requirement->id,
            'volume' => (float) $requirement->volume,
            'status' => $requirement->status->value,
        ];
    }
}
