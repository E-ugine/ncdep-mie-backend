<?php

namespace Tests\Feature\Mie;

use App\Models\Buyer;
use App\Models\BuyerRequirement;
use App\Models\Commodity;
use App\Models\Country;
use App\Models\CurrentSource;
use App\Models\Market;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierMatch;
use App\Models\SupplyGap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketScanTest extends TestCase
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

    /**
     * Builds one fully-connected requirement: commodity -> product_form -> product,
     * country -> market, buyer, supply gap, a match+offer (price signal), and a current source.
     */
    private function buildRequirement(array $overrides = []): BuyerRequirement
    {
        $country = Country::factory()->create($overrides['country'] ?? []);
        $commodity = Commodity::factory()->create($overrides['commodity'] ?? []);
        $productForm = ProductForm::factory()->create(array_merge(['commodity_id' => $commodity->id], $overrides['product_form'] ?? []));
        $product = Product::factory()->create(array_merge(['product_form_id' => $productForm->id], $overrides['product'] ?? []));
        $market = Market::factory()->create(array_merge(['country_id' => $country->id], $overrides['market'] ?? []));
        $buyer = Buyer::factory()->create($overrides['buyer'] ?? []);

        $requirement = BuyerRequirement::factory()->create(array_merge([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'market_id' => $market->id,
            'volume' => 500,
        ], $overrides['requirement'] ?? []));

        SupplyGap::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'demand_volume' => 1000,
            'contracted_volume' => 600,
        ]);

        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'supplier_id' => $supplier->id,
        ]);
        Offer::factory()->create(['match_id' => $match->id, 'price' => 20]);

        CurrentSource::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'country_id' => $country->id,
        ]);

        return $requirement->fresh();
    }

    public function test_endpoint_is_blocked_without_module_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/mie/market-scan')
            ->assertStatus(403);
    }

    public function test_returns_enriched_requirement_with_real_computed_fields(): void
    {
        $this->actingAsGatedUser();

        $requirement = $this->buildRequirement();

        $response = $this->getJson('/api/mie/market-scan')->assertOk()->json();

        $this->assertCount(1, $response['requirements']);
        $result = $response['requirements'][0];

        // json_encode collapses whole-number floats to bare ints — assertEquals, not assertSame.
        $this->assertSame($requirement->id, $result['id']);
        $this->assertEquals(500.0, $result['volume']);
        $this->assertEquals(1000.0, $result['supply_gap']['demand_volume']);
        $this->assertEquals(600.0, $result['supply_gap']['contracted_volume']);
        $this->assertEquals(400.0, $result['supply_gap']['gap']);
        $this->assertEquals(20.0, $result['price_range']['min']);
        $this->assertEquals(20.0, $result['price_range']['max']);
        $this->assertCount(1, $result['current_source']);

        // Section 3.17's real weighted composite (stage 7) replaced the old flat
        // opportunity_assessment_preliminary stub. The overall composite depends on several
        // components, so assert the one deterministic, precisely-known sub-component
        // (supply_gap_size = gap/demand = 400/1000 = 40%) rather than a brittle exact composite.
        $this->assertArrayHasKey('opportunity_score', $result);
        $this->assertEquals(40.0, $result['opportunity_score']['breakdown']['supply_gap_size']['value']);
        $this->assertContains($result['opportunity_score']['priority_tier'], ['high', 'medium', 'low']);

        $this->assertNotEmpty($response['demand_by_region']);
        $this->assertCount(1, $response['buyers']);
    }

    public function test_demand_by_region_has_exact_shape_with_no_leaked_relation_keys(): void
    {
        $this->actingAsGatedUser();

        $this->buildRequirement(['country' => ['name' => 'Kenya', 'iso_code' => 'KEN']]);

        $response = $this->getJson('/api/mie/market-scan')->assertOk()->json();

        $this->assertCount(1, $response['demand_by_region']);
        $region = $response['demand_by_region'][0];

        // Exact key set, in this order — must not leak eager-loaded relation keys
        // (buyer/product/market/supply_gap/matches/current_sources) from the requirements query.
        $this->assertSame(
            ['country_id', 'country', 'total_demand_volume', 'requirement_count'],
            array_keys($region),
        );

        $this->assertSame('Kenya', $region['country']);
        $this->assertEquals(500.0, $region['total_demand_volume']); // buildRequirement()'s default volume
        $this->assertSame(1, $region['requirement_count']);
    }

    public function test_country_filter_narrows_results(): void
    {
        $this->actingAsGatedUser();

        $kenya = $this->buildRequirement(['country' => ['name' => 'Kenya', 'iso_code' => 'KEN']]);
        $germany = $this->buildRequirement(['country' => ['name' => 'Germany', 'iso_code' => 'DEU']]);

        $unfiltered = $this->getJson('/api/mie/market-scan')->assertOk()->json();
        $this->assertCount(2, $unfiltered['requirements']);

        $filtered = $this->getJson('/api/mie/market-scan?country=Kenya')->assertOk()->json();

        $this->assertCount(1, $filtered['requirements']);
        $this->assertSame($kenya->id, $filtered['requirements'][0]['id']);
        $this->assertNotEquals($germany->id, $filtered['requirements'][0]['id']);
    }

    public function test_volume_min_filter_excludes_smaller_requirements(): void
    {
        $this->actingAsGatedUser();

        $small = $this->buildRequirement(['requirement' => ['volume' => 50]]);
        $large = $this->buildRequirement(['requirement' => ['volume' => 5000]]);

        $response = $this->getJson('/api/mie/market-scan?volume_min=1000')->assertOk()->json();

        $ids = collect($response['requirements'])->pluck('id')->all();

        $this->assertContains($large->id, $ids);
        $this->assertNotContains($small->id, $ids);
    }

    public function test_price_range_filter_matches_on_offer_price(): void
    {
        $this->actingAsGatedUser();

        $cheap = $this->buildRequirement(); // offer price = 20 from buildRequirement()

        $response = $this->getJson('/api/mie/market-scan?price_min=100&price_max=200')->assertOk()->json();
        $this->assertCount(0, $response['requirements']);

        $response = $this->getJson('/api/mie/market-scan?price_min=10&price_max=30')->assertOk()->json();
        $this->assertCount(1, $response['requirements']);
        $this->assertSame($cheap->id, $response['requirements'][0]['id']);
    }

    public function test_unsupported_filters_are_reported_not_silently_applied(): void
    {
        $this->actingAsGatedUser();

        $this->buildRequirement();

        $response = $this->getJson('/api/mie/market-scan?region=EastAfrica&variety=Hass&industry=Food&certification=Organic')
            ->assertOk()
            ->json();

        // They don't filter anything out...
        $this->assertCount(1, $response['requirements']);

        // ...but the response says explicitly that they were ignored, rather than pretending to honor them.
        $this->assertEqualsCanonicalizing(
            ['region', 'variety', 'industry', 'certification'],
            $response['unsupported_filters'],
        );
    }
}
