<?php

namespace App\Http\Controllers\Mie;

use App\Enums\ComplianceStatus;
use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Enums\Incoterm;
use App\Enums\ShipmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Services\Mie\ConversationMessenger;
use App\Services\Mie\DealStageTransitioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Section 3.11 — Contract Center. Contracts only originate from
 * Requirement → Match → Negotiation → Offer → Deal → Contract (the same non-negotiable chain
 * stage 4 first enforced at offer-creation) — this is the SECOND enforcement point: a contract
 * can only be created once its deal has reached 'contract_pending'.
 */
class ContractController extends Controller
{
    private const VIEWS = ['draft', 'offers_counteroffers', 'active', 'expiring', 'completed', 'cancelled'];

    public function __construct(
        private readonly DealStageTransitioner $transitioner,
        private readonly ConversationMessenger $messenger,
    ) {}

    /**
     * Section 3.12 — contract messaging, same reused ConversationMessenger as requirement and
     * deal messaging.
     */
    public function message(Request $request, int $id): JsonResponse
    {
        $contract = Contract::findOrFail($id);

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $result = $this->messenger->sendToConversable(
            $contract,
            $request->user()->id,
            $validated['message'],
            "Contract #{$contract->id}",
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

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'view' => ['sometimes', Rule::in(self::VIEWS)],
        ]);

        $view = $validated['view'] ?? null;

        // No contract row exists at the offers/counteroffers stage — a contract, by definition,
        // doesn't exist yet there. Same honest-alias treatment as stage 4's "active RFQs": there's
        // no separate "counteroffer" entity distinct from negotiations either, so this surfaces
        // negotiations that haven't converted to a deal yet, not contracts.
        if ($view === 'offers_counteroffers') {
            $negotiations = Negotiation::whereDoesntHave('deal')->with('offer')->get();

            return response()->json([
                'view' => 'offers_counteroffers',
                'note' => "No contracts exist at the offers/counteroffers stage, and no distinct 'counteroffer' entity exists separate from negotiations — this view surfaces negotiations that haven't converted to a deal yet, not contract rows.",
                'negotiations' => $negotiations->map(fn (Negotiation $negotiation) => [
                    'id' => $negotiation->id,
                    'status' => $negotiation->status->value,
                    'offer_id' => $negotiation->offer_id,
                    'counter_price' => $negotiation->counter_price !== null ? (float) $negotiation->counter_price : null,
                    'counter_volume' => $negotiation->counter_volume !== null ? (float) $negotiation->counter_volume : null,
                ])->values(),
            ]);
        }

        $query = Contract::query();

        match ($view) {
            'draft' => $query->where('status', ContractStatus::Draft->value),
            'active' => $query->where('status', ContractStatus::Active->value),
            'completed' => $query->where('status', ContractStatus::Completed->value),
            'cancelled' => $query->where('status', ContractStatus::Cancelled->value),
            // Still "in flight" (not completed/cancelled) AND due within the configured window.
            'expiring' => $query->whereIn('status', [ContractStatus::Draft->value, ContractStatus::Active->value])
                ->whereDate('delivery_date', '>=', now()->toDateString())
                ->whereDate('delivery_date', '<=', now()->addDays((int) config('mie.contracts.expiring_within_days'))->toDateString()),
            default => null,
        };

        $contracts = $query->get()->map(fn (Contract $contract) => $this->presentSummary($contract))->values();

        return response()->json([
            'view' => $view,
            'contracts' => $contracts,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $contract = Contract::with([
            'deal.negotiation.offer.match.buyerRequirement.buyer',
            'deal.negotiation.offer.match.supplier',
        ])->findOrFail($id);

        $deal = $contract->deal;
        $match = optional($deal->negotiation)->offer?->match;
        $requirement = optional($match)->buyerRequirement;
        $buyer = optional($requirement)->buyer;
        $supplier = optional($match)->supplier;

        return response()->json([
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'value' => (float) $contract->value,
            'volume' => (float) $contract->volume,
            'price' => (float) $contract->price,
            'currency' => $contract->currency,
            'incoterm' => $contract->incoterm,
            'delivery_date' => $contract->delivery_date->toDateString(),
            'payment_terms' => $contract->payment_terms,
            'status' => $contract->status->value,
            'documents' => $contract->documents,
            'compliance_status' => $contract->compliance_status->value,
            'shipment_status' => $contract->shipment_status->value,
            'parties' => [
                'buyer' => $buyer ? ['id' => $buyer->id, 'name' => $buyer->name] : null,
                'supplier' => $supplier ? ['id' => $supplier->id, 'name' => $supplier->name] : null,
            ],
            'lineage' => [
                'deal_id' => $deal->id,
                'deal_pipeline_stage' => $deal->pipeline_stage->value,
                'negotiation_id' => optional($deal->negotiation)->id,
                'offer_id' => optional($deal->negotiation)->offer?->id,
                'match_id' => optional($match)->id,
                'buyer_requirement_id' => optional($requirement)->id,
            ],
        ]);
    }

    /**
     * The second guard-clause enforcement of section 3.11's chain rule (the first was stage 4's
     * offer-requires-a-match check): a contract can only be created once its deal is in
     * 'contract_pending'. On success, the deal auto-advances to 'contract_signed' through the
     * SAME DealStageTransitioner path PATCH /deals/{id}/stage uses — never by writing
     * pipeline_stage directly — so the deal_events observer still fires.
     */
    public function store(Request $request, int $id): JsonResponse
    {
        $deal = Deal::with('contract')->findOrFail($id);

        if ($deal->pipeline_stage !== DealPipelineStage::ContractPending) {
            return response()->json([
                'message' => "A contract can only be created once the deal reaches 'contract_pending'. Required chain: Requirement → Match → Negotiation → Offer → Deal → Contract.",
                'code' => 'deal_not_contract_pending',
                'current_stage' => $deal->pipeline_stage->value,
            ], 422);
        }

        if ($deal->contract) {
            return response()->json([
                'message' => 'A contract already exists for this deal.',
                'code' => 'contract_already_exists',
                'contract_id' => $deal->contract->id,
            ], 422);
        }

        $validated = $request->validate([
            'price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'incoterm' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! Incoterm::tryFrom(strtolower($value))) {
                    $fail('The selected incoterm is not a valid Incoterm 2020 code.');
                }
            }],
            'delivery_date' => ['required', 'date'],
            'payment_terms' => ['required', 'string'],
            'documents' => ['sometimes', 'array'],
            'compliance_status' => ['sometimes', Rule::enum(ComplianceStatus::class)],
            'shipment_status' => ['sometimes', Rule::enum(ShipmentStatus::class)],
        ]);

        $contract = DB::transaction(function () use ($deal, $validated) {
            $contract = Contract::create([
                'deal_id' => $deal->id,
                'contract_number' => $this->generateContractNumber(),
                // Total value is derived from the deal's already-agreed volume × the per-unit
                // price given here, rather than accepting an independent 'value' that could
                // silently disagree with volume × price.
                'value' => (float) $deal->agreed_volume * $validated['price'],
                'volume' => $deal->agreed_volume,
                'price' => $validated['price'],
                'currency' => strtoupper($validated['currency']),
                'incoterm' => strtoupper($validated['incoterm']),
                'delivery_date' => $validated['delivery_date'],
                'payment_terms' => $validated['payment_terms'],
                // Explicit, not relying on the DB-level default — see stage 5 summary re: the
                // Offer/Negotiation enum-default bug from stage 4. Starts 'draft': the contract
                // record now exists, but nothing in this stage models a draft→active transition.
                'status' => ContractStatus::Draft,
                'documents' => $validated['documents'] ?? null,
                'compliance_status' => isset($validated['compliance_status'])
                    ? ComplianceStatus::from($validated['compliance_status'])
                    : ComplianceStatus::Pending,
                'shipment_status' => isset($validated['shipment_status'])
                    ? ShipmentStatus::from($validated['shipment_status'])
                    : ShipmentStatus::Pending,
            ]);

            $this->transitioner->transition($deal, DealPipelineStage::ContractSigned);

            return $contract;
        });

        return response()->json(['contract' => $this->presentSummary($contract)], 201);
    }

    private function generateContractNumber(): string
    {
        return 'CTR-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));
    }

    private function presentSummary(Contract $contract): array
    {
        return [
            'id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'status' => $contract->status->value,
            'value' => (float) $contract->value,
            'volume' => (float) $contract->volume,
            'price' => (float) $contract->price,
            'currency' => $contract->currency,
            'delivery_date' => $contract->delivery_date->toDateString(),
            'compliance_status' => $contract->compliance_status->value,
            'shipment_status' => $contract->shipment_status->value,
        ];
    }
}
