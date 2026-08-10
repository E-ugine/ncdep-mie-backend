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
use App\Models\SavedRequirement;
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
        $this->seedAdditionalCapacity($ref);
        $this->seedAdditionalDeals($ref);
        $this->seedSavedOpportunities($demoUser);
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

        // Three more raw-form buyers so the command center's "largest supply gaps" table has a
        // real ranked spread instead of a single row — same theme (dried hibiscus, EU demand),
        // same firstOrCreate discipline as every other buyer above.
        $this->buyers['hanseatic'] = Buyer::firstOrCreate(
            ['name' => 'Hanseatic Dried Goods GmbH'],
            [
                'country_id' => $ref->countries['DE']->id,
                'description' => 'Hamburg-based importer of dried botanicals for the tea and infusion trade.',
                'buyer_type' => BuyerType::Importer,
                'industry' => 'Tea & Herbal Infusions',
                'hq' => 'Hamburg, Germany',
                'payment_terms' => 'Net 30',
                'currency' => 'EUR',
                'preferred_ports' => ['Hamburg'],
                'logistics_preferences' => ['cold_chain' => false],
                'verification_status' => BuyerVerificationStatus::Verified,
            ],
        );
        $this->buyers['rotterdam_ingredients'] = Buyer::firstOrCreate(
            ['name' => 'Rotterdam Ingredient Partners BV'],
            [
                'country_id' => $ref->countries['NL']->id,
                'description' => 'Rotterdam distributor supplying raw botanical inputs to EU food manufacturers.',
                'buyer_type' => BuyerType::Distributor,
                'industry' => 'Food & Beverage Ingredients',
                'hq' => 'Rotterdam, Netherlands',
                'payment_terms' => 'Net 45',
                'currency' => 'EUR',
                'preferred_ports' => ['Rotterdam'],
                'logistics_preferences' => ['cold_chain' => false],
                'verification_status' => BuyerVerificationStatus::Verified,
            ],
        );
        $this->buyers['bremen'] = Buyer::firstOrCreate(
            ['name' => 'Bremen Specialty Imports GmbH'],
            [
                'country_id' => $ref->countries['DE']->id,
                'description' => 'Specialty-grade dried botanicals importer serving independent herbal retailers.',
                'buyer_type' => BuyerType::Wholesaler,
                'industry' => 'Specialty Foods',
                'hq' => 'Bremen, Germany',
                'payment_terms' => 'LC at sight',
                'currency' => 'EUR',
                'preferred_ports' => ['Bremerhaven'],
                'logistics_preferences' => ['cold_chain' => false],
                'verification_status' => BuyerVerificationStatus::Verified,
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

        // Three more raw-form requirements, each with its own real supply gap below — gives the
        // command center's ranked gap table (and open_supply_gaps_count) an actual spread instead
        // of a single row.
        $this->requirements['hanseatic_raw'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['hanseatic']->id,
                'product_id' => $ref->products['raw']->id,
                'market_id' => $ref->markets['de']->id,
            ],
            [
                'volume' => 280,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::Quarterly,
                'specification' => ['grade' => 'Grade A', 'moisture' => '<12%', 'packaging' => '25kg jute bags'],
                'delivery_window_start' => now()->addDays(25)->toDateString(),
                'delivery_window_end' => now()->addDays(55)->toDateString(),
                'incoterm' => Incoterm::FOB,
            ],
        );
        $this->requirements['rotterdam_raw'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['rotterdam_ingredients']->id,
                'product_id' => $ref->products['raw']->id,
                'market_id' => $ref->markets['nl']->id,
            ],
            [
                'volume' => 150,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::Monthly,
                'specification' => ['grade' => 'Grade A', 'packaging' => '25kg jute bags'],
                'delivery_window_start' => now()->addDays(20)->toDateString(),
                'delivery_window_end' => now()->addDays(50)->toDateString(),
                'incoterm' => Incoterm::CIF,
            ],
        );
        $this->requirements['bremen_raw'] = BuyerRequirement::firstOrCreate(
            [
                'buyer_id' => $this->buyers['bremen']->id,
                'product_id' => $ref->products['raw']->id,
                'market_id' => $ref->markets['de']->id,
            ],
            [
                'volume' => 90,
                'status' => BuyerRequirementStatus::Open,
                'frequency' => RequirementFrequency::OneTime,
                'specification' => ['grade' => 'Grade B', 'packaging' => '10kg cartons'],
                'delivery_window_start' => now()->addDays(18)->toDateString(),
                'delivery_window_end' => now()->addDays(40)->toDateString(),
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

        // Four more real gaps (280/80 -> 200, 150/30 -> 120, 90/45 -> 45, 60/15 -> 45) so the
        // command center's ranked table has an actual spread of commodities/buyers/markets to
        // rank, not just the one primary requirement.
        SupplyGap::firstOrCreate(
            ['buyer_requirement_id' => $this->requirements['hanseatic_raw']->id],
            ['demand_volume' => 280, 'contracted_volume' => 80],
        );
        SupplyGap::firstOrCreate(
            ['buyer_requirement_id' => $this->requirements['rotterdam_raw']->id],
            ['demand_volume' => 150, 'contracted_volume' => 30],
        );
        SupplyGap::firstOrCreate(
            ['buyer_requirement_id' => $this->requirements['bremen_raw']->id],
            ['demand_volume' => 90, 'contracted_volume' => 45],
        );
        SupplyGap::firstOrCreate(
            ['buyer_requirement_id' => $this->requirements['nordic_raw']->id],
            ['demand_volume' => 60, 'contracted_volume' => 15],
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

    /**
     * Rift Valley's real "My Supply" capacity was a single raw-form row — two more forms so the
     * dashboard shows genuine variety instead of one line.
     */
    private function seedAdditionalCapacity(ReferenceDataSeeder $ref): void
    {
        SupplierCapacity::firstOrCreate(
            ['supplier_id' => $this->suppliers['rift']->id, 'product_form_id' => $ref->productForms['processed']->id],
            ['capacity_volume' => 80, 'available_volume' => 55, 'certifications' => ['Organic']],
        );
        SupplierCapacity::firstOrCreate(
            ['supplier_id' => $this->suppliers['rift']->id, 'product_form_id' => $ref->productForms['fresh']->id],
            ['capacity_volume' => 200, 'available_volume' => 200, 'certifications' => null],
        );
    }

    /**
     * Shared match/offer/negotiation setup for the three additional deals below — same real
     * MatchScorer-driven path as walkChainToContract, extracted since it's now used four times.
     */
    private function matchOfferNegotiation(BuyerRequirement $requirement, Supplier $supplier, int $productFormId, float $price, float $volume): Negotiation
    {
        $requirement = $requirement->fresh(['product', 'supplyGap', 'market']);

        $match = SupplierMatch::where('buyer_requirement_id', $requirement->id)
            ->where('supplier_id', $supplier->id)
            ->first();

        if (! $match) {
            $capacity = SupplierCapacity::where('supplier_id', $supplier->id)
                ->where('product_form_id', $productFormId)
                ->first();

            $result = app(MatchScorer::class)->score($requirement, $capacity);

            $match = SupplierMatch::create([
                'buyer_requirement_id' => $requirement->id,
                'supplier_id' => $supplier->id,
                'score' => (int) round($result['score']),
                'reason' => $result['breakdown'],
                'fulfillable_volume' => $result['fulfillable_volume'],
            ]);
        }

        $offer = Offer::firstOrCreate(
            ['match_id' => $match->id],
            ['price' => $price, 'volume' => $volume, 'currency' => 'USD', 'status' => OfferStatus::Accepted, 'valid_until' => now()->addDays(14)],
        );

        return Negotiation::firstOrCreate(
            ['offer_id' => $offer->id],
            ['status' => NegotiationStatus::Agreed, 'counter_price' => $price, 'counter_volume' => $volume, 'notes' => 'Agreed terms.'],
        );
    }

    /**
     * Three more real deals for Rift Valley, deliberately left at different real pipeline stages
     * (via the same DealStageTransitioner every API transition uses) so the dashboard's "My
     * Deals" stage bars and "My Money" receivables have genuine variety instead of a single
     * contract-signed deal.
     */
    private function seedAdditionalDeals(ReferenceDataSeeder $ref): void
    {
        $transitioner = app(DealStageTransitioner::class);

        // Early-stage: still negotiating, no contract yet.
        $negotiation = $this->matchOfferNegotiation(
            $this->requirements['hanseatic_raw'],
            $this->suppliers['rift'],
            $ref->productForms['raw']->id,
            3550,
            200,
        );
        Deal::firstOrCreate(
            ['negotiation_id' => $negotiation->id],
            ['pipeline_stage' => DealPipelineStage::Negotiating, 'agreed_price' => 3550, 'agreed_volume' => 200, 'currency' => 'USD'],
        );

        // Mid-stage: signed contract, currently in production.
        $negotiation = $this->matchOfferNegotiation(
            $this->requirements['rotterdam_raw'],
            $this->suppliers['rift'],
            $ref->productForms['raw']->id,
            3400,
            120,
        );
        $deal = Deal::firstOrCreate(
            ['negotiation_id' => $negotiation->id],
            ['pipeline_stage' => DealPipelineStage::Open, 'agreed_price' => 3400, 'agreed_volume' => 120, 'currency' => 'USD'],
        );
        foreach ([DealPipelineStage::Negotiating, DealPipelineStage::ContractPending] as $stage) {
            $deal->refresh();
            if ($deal->pipeline_stage !== $stage) {
                $transitioner->transition($deal, $stage);
            }
        }
        $deal->refresh();
        if (! $deal->contract) {
            Contract::create([
                'deal_id' => $deal->id,
                'contract_number' => 'CTR-DEMO-'.str_pad((string) $deal->id, 4, '0', STR_PAD_LEFT),
                'value' => 120 * 3400,
                'volume' => 120,
                'price' => 3400,
                'currency' => 'USD',
                'incoterm' => 'CIF',
                'delivery_date' => now()->addDays(35),
                'payment_terms' => 'Net 45',
                'status' => ContractStatus::Active,
                'documents' => ['proforma_invoice.pdf'],
                'compliance_status' => ComplianceStatus::Compliant,
                'shipment_status' => ShipmentStatus::Pending,
            ]);
            $transitioner->transition($deal->fresh(), DealPipelineStage::ContractSigned);
        }
        $deal->refresh();
        if ($deal->pipeline_stage === DealPipelineStage::ContractSigned) {
            $transitioner->transition($deal, DealPipelineStage::InProduction);
        }

        // Late-stage: delivered and awaiting payment — the contract's shipment_status is real
        // 'delivered' paired with the deal at 'payment_pending', exactly DashboardController's
        // receivables_definition, so the dashboard's receivables figure is genuinely non-zero.
        $negotiation = $this->matchOfferNegotiation(
            $this->requirements['bremen_raw'],
            $this->suppliers['rift'],
            $ref->productForms['raw']->id,
            3200,
            90,
        );
        $deal = Deal::firstOrCreate(
            ['negotiation_id' => $negotiation->id],
            ['pipeline_stage' => DealPipelineStage::Open, 'agreed_price' => 3200, 'agreed_volume' => 90, 'currency' => 'USD'],
        );
        foreach ([DealPipelineStage::Negotiating, DealPipelineStage::ContractPending] as $stage) {
            $deal->refresh();
            if ($deal->pipeline_stage !== $stage) {
                $transitioner->transition($deal, $stage);
            }
        }
        $deal->refresh();
        if (! $deal->contract) {
            Contract::create([
                'deal_id' => $deal->id,
                'contract_number' => 'CTR-DEMO-'.str_pad((string) $deal->id, 4, '0', STR_PAD_LEFT),
                'value' => 90 * 3200,
                'volume' => 90,
                'price' => 3200,
                'currency' => 'USD',
                'incoterm' => 'EXW',
                'delivery_date' => now()->subDays(5),
                'payment_terms' => 'Net 30',
                'status' => ContractStatus::Active,
                'documents' => ['proforma_invoice.pdf', 'bill_of_lading.pdf'],
                'compliance_status' => ComplianceStatus::Compliant,
                'shipment_status' => ShipmentStatus::Delivered,
            ]);
            $transitioner->transition($deal->fresh(), DealPipelineStage::ContractSigned);
        }
        foreach ([DealPipelineStage::InProduction, DealPipelineStage::InTransit, DealPipelineStage::Delivered, DealPipelineStage::PaymentPending] as $stage) {
            $deal->refresh();
            if ($deal->pipeline_stage !== $stage) {
                $transitioner->transition($deal, $stage);
            }
        }
    }

    /**
     * Real saved requirements for the demo user's "My Market" section — requirements they haven't
     * (yet) turned into a deal, which is what makes a "save for later" real rather than redundant
     * with an already-active deal.
     */
    private function seedSavedOpportunities(User $demoUser): void
    {
        SavedRequirement::firstOrCreate([
            'user_id' => $demoUser->id,
            'buyer_requirement_id' => $this->requirements['nordic_raw']->id,
        ]);
        SavedRequirement::firstOrCreate([
            'user_id' => $demoUser->id,
            'buyer_requirement_id' => $this->requirements['vanderberg_processed']->id,
        ]);
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
