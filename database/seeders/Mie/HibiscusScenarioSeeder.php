<?php

namespace Database\Seeders\Mie;

use App\Enums\BuyerRequirementStatus;
use App\Enums\BuyerType;
use App\Enums\BuyerVerificationStatus;
use App\Enums\ComplianceStatus;
use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Enums\Incoterm;
use App\Enums\NegotiationStatus;
use App\Enums\OfferStatus;
use App\Enums\RequirementFrequency;
use App\Enums\ShipmentStatus;
use App\Enums\SupplierType;
use App\Models\Buyer;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\CurrentSource;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Models\Offer;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Models\SupplyGap;
use App\Models\User;
use App\Services\Mie\ConversationMessenger;
use App\Services\Mie\DealStageTransitioner;
use App\Services\Mie\MatchScorer;
use Illuminate\Database\Seeder;

/**
 * Dried hibiscus, Kenya origin, Europe demand — the spec's own example (section 3.19). Buyers,
 * suppliers, requirements, and the deal/contract/message rows below are genuinely variable
 * transactional data, so (unlike ReferenceDataSeeder) factories are used freely — but every row
 * is still firstOrCreate()'d on a sensible natural key so the WHOLE seeder stays rerunnable.
 *
 * Run in two passes (see DatabaseSeeder) because notification correctness depends on ordering:
 * the demo user must already be linked to a supplier BEFORE any buyer_requirement/match/deal is
 * created, or the observers built in stage 7 have no one to notify. seedSupplySide() creates the
 * suppliers first; DatabaseSeeder links the demo user to one of them; seedDemandSideAndChain()
 * then creates requirements/gap/chain, letting the real observers fire naturally.
 */
class HibiscusScenarioSeeder extends Seeder
{
    /** @var array<string, Supplier> */
    public array $suppliers = [];

    /** @var array<string, Buyer> */
    public array $buyers = [];

    /** @var array<string, BuyerRequirement> */
    public array $requirements = [];

    public BuyerRequirement $primaryRequirement;

    public Contract $activeContract;

    public function seedSupplySide(ReferenceDataSeeder $ref): void
    {
        $this->suppliers['rift'] = Supplier::firstOrCreate(
            ['name' => 'Rift Valley Hibiscus Growers Ltd'],
            ['country_id' => $ref->countries['KE']->id, 'type' => SupplierType::Aggregator],
        );
        $this->suppliers['coastal'] = Supplier::firstOrCreate(
            ['name' => 'Coastal Kenya Dried Botanicals Co-op'],
            ['country_id' => $ref->countries['KE']->id, 'type' => SupplierType::Farm],
        );
        $this->suppliers['savannah'] = Supplier::firstOrCreate(
            ['name' => 'Savannah Export Processors Ltd'],
            ['country_id' => $ref->countries['KE']->id, 'type' => SupplierType::Processor],
        );

        // Varying available_volume (and only Rift Valley holding real certifications) so match
        // scoring produces a genuine spread, not identical scores — stage 8 seed requirement.
        SupplierCapacity::firstOrCreate(
            ['supplier_id' => $this->suppliers['rift']->id, 'product_form_id' => $ref->productForms['raw']->id],
            ['capacity_volume' => 500, 'available_volume' => 400, 'certifications' => ['Organic', 'GlobalGAP']],
        );
        SupplierCapacity::firstOrCreate(
            ['supplier_id' => $this->suppliers['coastal']->id, 'product_form_id' => $ref->productForms['raw']->id],
            ['capacity_volume' => 150, 'available_volume' => 80, 'certifications' => null],
        );
        SupplierCapacity::firstOrCreate(
            ['supplier_id' => $this->suppliers['savannah']->id, 'product_form_id' => $ref->productForms['processed']->id],
            ['capacity_volume' => 60, 'available_volume' => 20, 'certifications' => ['FairTrade']],
        );
    }

    public function seedDemandSideAndChain(ReferenceDataSeeder $ref, User $demoUser): void
    {
        $this->seedBuyers($ref);
        $this->seedRequirements($ref);
        $this->seedSupplyGapAndCurrentSource($ref);
        $this->walkChainToContract($ref);
        $this->seedMessages($demoUser);
    }

    private function seedBuyers(ReferenceDataSeeder $ref): void
    {
        $this->buyers['schmidt'] = Buyer::firstOrCreate(
            ['name' => 'Schmidt Botanicals GmbH'],
            [
                'country_id' => $ref->countries['DE']->id,
                'description' => 'German importer specializing in dried botanicals for the tea and herbal infusion industry.',
                'buyer_type' => BuyerType::Importer,
                'industry' => 'Tea & Herbal Infusions',
                'hq' => 'Hamburg, Germany',
                'payment_terms' => 'Net 45',
                'currency' => 'EUR',
                'preferred_ports' => ['Hamburg', 'Bremerhaven'],
                'logistics_preferences' => ['cold_chain' => false, 'container_type' => '20ft dry'],
                'verification_status' => BuyerVerificationStatus::Verified,
            ],
        );

        $this->buyers['vanderberg'] = Buyer::firstOrCreate(
            ['name' => 'Van der Berg Ingredients BV'],
            [
                'country_id' => $ref->countries['NL']->id,
                'description' => 'Dutch distributor sourcing botanical extracts for food & beverage manufacturers across the EU.',
                'buyer_type' => BuyerType::Distributor,
                'industry' => 'Food & Beverage Ingredients',
                'hq' => 'Rotterdam, Netherlands',
                'payment_terms' => 'LC at sight',
                'currency' => 'EUR',
                'preferred_ports' => ['Rotterdam'],
                'logistics_preferences' => ['cold_chain' => false],
                'verification_status' => BuyerVerificationStatus::Verified,
            ],
        );

        $this->buyers['nordic'] = Buyer::firstOrCreate(
            ['name' => 'Nordic Herbal Trading AB'],
            [
                'country_id' => $ref->countries['DE']->id,
                'description' => 'Smaller-volume specialty buyer for premium-grade dried botanicals.',
                'buyer_type' => BuyerType::Wholesaler,
                'industry' => 'Specialty Foods',
                'hq' => 'Hamburg, Germany (EU sourcing office)',
                'payment_terms' => '50% advance, 50% on shipment',
                'currency' => 'EUR',
                'preferred_ports' => ['Hamburg'],
                'logistics_preferences' => ['cold_chain' => false],
                'verification_status' => BuyerVerificationStatus::Pending,
            ],
        );
    }

    private function seedRequirements(ReferenceDataSeeder $ref): void
    {
        // Primary requirement: real uncovered volume (see supply gap below), Organic-certified —
        // matches Rift Valley's real certifications, so compliance_fit/user_capability produce
        // real non-null results (stage 8 issue #3).
        $this->requirements['schmidt_raw'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['schmidt']->id,
                'product_id' => $ref->products['raw']->id,
                'market_id' => $ref->markets['de']->id,
            ],
            [
                'volume' => 500,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::Quarterly,
                'specification' => ['grade' => 'Grade A', 'moisture' => '<12%', 'packaging' => '25kg jute bags', 'certification' => 'Organic'],
                'delivery_window_start' => now()->addDays(30)->toDateString(),
                'delivery_window_end' => now()->addDays(60)->toDateString(),
                'incoterm' => Incoterm::FOB,
            ],
        );
        $this->primaryRequirement = $this->requirements['schmidt_raw'];

        // Different form (processed) and different buyer — buyer-list/market-scan variety.
        $this->requirements['vanderberg_processed'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['vanderberg']->id,
                'product_id' => $ref->products['processed']->id,
                'market_id' => $ref->markets['nl']->id,
            ],
            [
                'volume' => 40,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::Monthly,
                'specification' => ['grade' => 'Food-grade', 'certification' => 'FairTrade'],
                'delivery_window_start' => now()->addDays(20)->toDateString(),
                'delivery_window_end' => now()->addDays(45)->toDateString(),
                'incoterm' => Incoterm::CIF,
            ],
        );

        // Smaller, no-cert-required requirement — rounds out the buyer list with real variety.
        $this->requirements['nordic_raw'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['nordic']->id,
                'product_id' => $ref->products['raw']->id,
                'market_id' => $ref->markets['de']->id,
            ],
            [
                'volume' => 60,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::OneTime,
                'specification' => ['grade' => 'Grade B', 'packaging' => '10kg cartons'],
                'delivery_window_start' => now()->addDays(15)->toDateString(),
                'delivery_window_end' => now()->addDays(30)->toDateString(),
                'incoterm' => Incoterm::EXW,
            ],
        );
    }

    private function seedSupplyGapAndCurrentSource(ReferenceDataSeeder $ref): void
    {
        // demand 500, contracted 150 -> gap = 350: a real, non-trivial uncovered_volume.
        SupplyGap::firstOrCreate(
            ['buyer_requirement_id' => $this->primaryRequirement->id],
            ['demand_volume' => 500, 'contracted_volume' => 150],
        );

        // Egypt is the competing origin currently covering part of Schmidt's demand — this is
        // what makes the gap and Kenya's competitive positioning mean something, not trivial.
        CurrentSource::firstOrCreate(
            [
                'buyer_requirement_id' => $this->primaryRequirement->id,
                'country_id' => $ref->countries['EG']->id,
            ],
            ['supplier_name' => 'Nile Delta Botanicals Export SAE', 'estimated_volume' => 150],
        );
    }

    /**
     * Walks the primary requirement through Requirement -> Match -> Offer -> Negotiation ->
     * Deal -> Contract via the REAL services (MatchScorer, DealStageTransitioner), not the HTTP
     * API (this is seeding) but not hand-faked either — the match score is a genuine
     * MatchScorer::score() result, and every deal stage transition goes through
     * DealStageTransitioner so DealObserver fires for real (deal_events + notifications).
     */
    private function walkChainToContract(ReferenceDataSeeder $ref): void
    {
        $requirement = $this->primaryRequirement->fresh(['product', 'supplyGap', 'market']);

        $match = SupplierMatch::where('buyer_requirement_id', $requirement->id)
            ->where('supplier_id', $this->suppliers['rift']->id)
            ->first();

        if (! $match) {
            $capacity = SupplierCapacity::where('supplier_id', $this->suppliers['rift']->id)
                ->where('product_form_id', $ref->productForms['raw']->id)
                ->first();

            $result = app(MatchScorer::class)->score($requirement, $capacity);

            $match = SupplierMatch::create([
                'buyer_requirement_id' => $requirement->id,
                'supplier_id' => $this->suppliers['rift']->id,
                'score' => (int) round($result['score']),
                'reason' => $result['breakdown'],
                'fulfillable_volume' => $result['fulfillable_volume'],
            ]);
        }

        // Price is $/MT (this product's unit_of_measure), matching ContractController's own
        // value = volume × price convention — 350 MT covers the requirement's real 350 gap.
        $offer = Offer::firstOrCreate(
            ['match_id' => $match->id],
            ['price' => 3650, 'volume' => 350, 'currency' => 'USD', 'status' => OfferStatus::Accepted, 'valid_until' => now()->addDays(14)],
        );

        $negotiation = Negotiation::firstOrCreate(
            ['offer_id' => $offer->id],
            ['status' => NegotiationStatus::Agreed, 'counter_price' => 3600, 'counter_volume' => 350, 'notes' => 'Agreed at $3,600/MT for 350MT, Q3 delivery window.'],
        );

        $deal = Deal::firstOrCreate(
            ['negotiation_id' => $negotiation->id],
            ['pipeline_stage' => DealPipelineStage::Open, 'agreed_price' => 3600, 'agreed_volume' => 350, 'currency' => 'USD'],
        );

        $transitioner = app(DealStageTransitioner::class);

        foreach ([DealPipelineStage::Negotiating, DealPipelineStage::ContractPending] as $stage) {
            $deal->refresh();
            if ($deal->pipeline_stage !== $stage) {
                $transitioner->transition($deal, $stage);
            }
        }

        $deal->refresh();

        if ($deal->contract) {
            $this->activeContract = $deal->contract;

            return;
        }

        // Stage 8 issue #2: no API endpoint ever transitions a contract draft -> active (never
        // requested, correctly out of scope) — a direct seeded row at 'active' is the honest way
        // to give the dashboard's my_money view a believable non-empty state.
        $this->activeContract = Contract::create([
            'deal_id' => $deal->id,
            'contract_number' => 'CTR-DEMO-'.str_pad((string) $deal->id, 4, '0', STR_PAD_LEFT),
            'value' => 350 * 3600,
            'volume' => 350,
            'price' => 3600,
            'currency' => 'USD',
            'incoterm' => 'FOB',
            'delivery_date' => now()->addDays(45),
            'payment_terms' => 'Net 45',
            'status' => ContractStatus::Active,
            'documents' => ['proforma_invoice.pdf', 'phytosanitary_certificate.pdf', 'certificate_of_origin.pdf'],
            'compliance_status' => ComplianceStatus::Compliant,
            'shipment_status' => ShipmentStatus::Pending,
        ]);

        $deal->refresh();
        $transitioner->transition($deal, DealPipelineStage::ContractSigned);
    }

    private function seedMessages(User $demoUser): void
    {
        $messenger = app(ConversationMessenger::class);

        $requirement = $this->primaryRequirement;
        $existing = $requirement->conversations()->first();

        if (! $existing) {
            $result = $messenger->sendToConversable(
                $requirement,
                $demoUser->id,
                'Hello Schmidt Botanicals — we can cover your 350MT Organic-certified dried hibiscus shortfall from our Rift Valley harvest. Interested in a call this week?',
                "Requirement #{$requirement->id}",
            );
            $messenger->reply($result['conversation'], $demoUser->id, 'Following up — happy to share our Organic/GlobalGAP certificates ahead of time.');
        }

        $deal = $this->activeContract->deal;
        $dealConversation = $deal->conversations()->first();

        if (! $dealConversation) {
            $result = $messenger->sendToConversable(
                $deal,
                $demoUser->id,
                'Confirming delivery window and FOB terms for the 350MT lot ahead of shipment.',
                "Deal #{$deal->id}",
            );
            $messenger->reply($result['conversation'], $demoUser->id, 'Contract signed on our end — please confirm receipt of the phytosanitary certificate.');
        }
    }
}
