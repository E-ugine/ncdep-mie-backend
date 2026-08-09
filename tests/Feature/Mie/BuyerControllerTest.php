<?php

namespace Tests\Feature\Mie;

use App\Enums\BuyerRequirementStatus;
use App\Enums\BuyerVerificationStatus;
use App\Enums\DealPipelineStage;
use App\Enums\RequirementFrequency;
use App\Models\Buyer;
use App\Models\BuyerRequirement;
use App\Models\Country;
use App\Models\CurrentSource;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuyerControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function actingAsGatedUser(): User
    {
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['module_access.granted_at' => now()->toISOString()]);

        return $user;
    }

    public function test_endpoints_are_blocked_without_module_access(): void
    {
        $user = User::factory()->create();
        $buyer = Buyer::factory()->create();

        $this->actingAs($user)->getJson('/api/mie/buyers')->assertStatus(403);
        $this->actingAs($user)->getJson("/api/mie/buyers/{$buyer->id}")->assertStatus(403);
    }

    public function test_index_filters_by_country_and_reports_unsupported_filters(): void
    {
        $this->actingAsGatedUser();

        $kenya = Buyer::factory()->create(['country_id' => Country::factory()->create(['name' => 'Kenya'])->id]);
        Buyer::factory()->create(['country_id' => Country::factory()->create(['name' => 'Germany'])->id]);

        $response = $this->getJson('/api/mie/buyers?country=Kenya&certification=Organic')->assertOk()->json();

        $this->assertCount(1, $response['buyers']);
        $this->assertSame($kenya->id, $response['buyers'][0]['id']);
        $this->assertSame(['certification'], $response['unsupported_filters']);
    }

    public function test_show_returns_every_section_3_3_field(): void
    {
        $this->actingAsGatedUser();

        $buyer = Buyer::factory()->create([
            'buyer_type' => 'importer',
            'industry' => 'Fresh Produce',
            'hq' => 'Rotterdam, Netherlands',
            'payment_terms' => 'Net 30',
            'currency' => 'EUR',
            'preferred_ports' => ['Rotterdam', 'Hamburg'],
            'logistics_preferences' => ['cold_chain' => true],
            'verification_status' => BuyerVerificationStatus::Verified,
        ]);

        $response = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();

        foreach ([
            'id', 'company', 'country', 'hq', 'buyer_type', 'industry', 'verification_status',
            'payment_terms', 'currency', 'preferred_ports', 'logistics_preferences',
            'operating_markets', 'products_purchased_sold', 'annual_procurement_volume_estimate',
            'procurement_frequency_breakdown', 'typical_order_size', 'preferred_specifications',
            'certifications_required', 'current_suppliers', 'historical_buying_activity',
            'existing_contracts', 'open_requirements_count', 'current_open_needs', 'active_rfqs',
            'market_relationships', 'trade_readiness', 'reliability_indicators',
            'sustainability_indicators', 'notes',
        ] as $field) {
            $this->assertArrayHasKey($field, $response, "Missing expected buyer profile field: {$field}");
        }

        $this->assertSame('Rotterdam, Netherlands', $response['hq']);
        $this->assertSame('importer', $response['buyer_type']);
        $this->assertSame('EUR', $response['currency']);
        $this->assertNull($response['sustainability_indicators']);
    }

    public function test_current_open_needs_reflects_live_data(): void
    {
        $this->actingAsGatedUser();

        $buyer = Buyer::factory()->create();
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);

        $open = BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => BuyerRequirementStatus::Open,
        ]);
        BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => BuyerRequirementStatus::Fulfilled,
        ]);

        $first = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();
        $this->assertCount(1, $first['current_open_needs']);
        $this->assertSame($open->id, $first['current_open_needs'][0]['id']);
        $this->assertSame(1, $first['open_requirements_count']);

        // Adding another open requirement must move the count — proving it's live, not cached.
        BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'status' => BuyerRequirementStatus::Open,
        ]);

        $second = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();
        $this->assertCount(2, $second['current_open_needs']);
        $this->assertSame(2, $second['open_requirements_count']);
    }

    public function test_annual_procurement_and_typical_order_size_are_computed_from_real_data(): void
    {
        $this->actingAsGatedUser();

        $buyer = Buyer::factory()->create();

        BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'volume' => 100,
            'frequency' => RequirementFrequency::Monthly, // 100 * 12 = 1200/yr
        ]);
        BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'volume' => 50,
            'frequency' => RequirementFrequency::Weekly, // 50 * 52 = 2600/yr
        ]);
        BuyerRequirement::factory()->create([
            'buyer_id' => $buyer->id,
            'volume' => 9999,
            'frequency' => null, // excluded from the annual estimate — no stated frequency
        ]);

        $response = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();

        $this->assertEquals(3800.0, $response['annual_procurement_volume_estimate']); // 1200 + 2600
        $this->assertEquals(round((100 + 50 + 9999) / 3, 2), $response['typical_order_size']);
    }

    public function test_trade_readiness_and_reliability_indicators_are_computed_from_real_history(): void
    {
        $this->actingAsGatedUser();

        $buyer = Buyer::factory()->create(['verification_status' => BuyerVerificationStatus::Verified]);

        // No deal history yet, but verified + has an open requirement -> "ready", reliability null.
        BuyerRequirement::factory()->create(['buyer_id' => $buyer->id, 'status' => BuyerRequirementStatus::Open]);

        $before = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();
        $this->assertSame('ready', $before['trade_readiness']);
        $this->assertNull($before['reliability_indicators']);

        // One completed deal, one cancelled-in-progress (non-completed) deal.
        $req1 = BuyerRequirement::factory()->create(['buyer_id' => $buyer->id]);
        $match1 = SupplierMatch::factory()->create(['buyer_requirement_id' => $req1->id]);
        $offer1 = Offer::factory()->create(['match_id' => $match1->id]);
        $negotiation1 = Negotiation::factory()->create(['offer_id' => $offer1->id]);
        Deal::factory()->create(['negotiation_id' => $negotiation1->id, 'pipeline_stage' => DealPipelineStage::Completed]);

        $req2 = BuyerRequirement::factory()->create(['buyer_id' => $buyer->id]);
        $match2 = SupplierMatch::factory()->create(['buyer_requirement_id' => $req2->id]);
        $offer2 = Offer::factory()->create(['match_id' => $match2->id]);
        $negotiation2 = Negotiation::factory()->create(['offer_id' => $offer2->id]);
        Deal::factory()->create(['negotiation_id' => $negotiation2->id, 'pipeline_stage' => DealPipelineStage::Negotiating]);

        $after = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();

        $this->assertSame(1, $after['reliability_indicators']['completed_deal_count']);
        $this->assertSame(2, $after['reliability_indicators']['total_deal_count']);
        $this->assertEquals(50.0, $after['reliability_indicators']['completion_rate']);
    }

    public function test_current_suppliers_and_countries_come_from_current_sources(): void
    {
        $this->actingAsGatedUser();

        $buyer = Buyer::factory()->create();
        $requirement = BuyerRequirement::factory()->create(['buyer_id' => $buyer->id]);
        $country = Country::factory()->create(['name' => 'Peru']);

        CurrentSource::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'country_id' => $country->id,
            'supplier_name' => 'Peru AgroExport SA',
        ]);

        $response = $this->getJson("/api/mie/buyers/{$buyer->id}")->assertOk()->json();

        $this->assertCount(1, $response['current_suppliers']);
        $this->assertSame('Peru AgroExport SA', $response['current_suppliers'][0]['supplier_name']);
        $this->assertSame('Peru', $response['current_suppliers'][0]['country']);
    }
}
