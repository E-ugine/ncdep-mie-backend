<?php

namespace Tests\Feature\Mie;

use App\Models\BuyerRequirement;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RequirementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function actingAsGatedUser(bool $freshPin = false): User
    {
        $user = User::factory()->create();

        $session = ['module_access.granted_at' => now()->toISOString()];

        if ($freshPin) {
            $session['module_access.last_pin_verified_at'] = now()->toISOString();
        }

        $this->actingAs($user)->withSession($session);

        return $user;
    }

    public function test_show_returns_requirement_detail_via_shared_presenter(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create(['state' => 'fresh']);
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create([
            'product_id' => $product->id,
            'incoterm' => 'fob',
            'specification' => ['grade' => 'Class I', 'packaging' => 'Carton', 'certification' => 'Organic'],
        ]);

        $response = $this->getJson("/api/mie/requirements/{$requirement->id}")->assertOk()->json();

        $this->assertSame($requirement->id, $response['id']);
        $this->assertSame('fob', $response['incoterm']);
        $this->assertSame('Class I', $response['grade']);
        $this->assertSame('Carton', $response['packaging']);
        $this->assertSame('Organic', $response['certification']);
        $this->assertArrayHasKey('destination', $response);
        $this->assertArrayHasKey('uncovered_volume', $response);
        $this->assertArrayHasKey('opportunity_score', $response);
        $this->assertArrayHasKey('composite_score', $response['opportunity_score']);
        $this->assertArrayHasKey('priority_tier', $response['opportunity_score']);
    }

    public function test_match_creates_a_real_scored_match_when_supplier_capacity_is_sufficient(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id, 'volume' => 100]);

        $supplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create([
            'supplier_id' => $supplier->id,
            'product_form_id' => $productForm->id,
            'available_volume' => 500,
        ]);

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/match")->assertCreated()->json();

        $this->assertTrue($response['matched']);
        $this->assertCount(1, $response['matches']);
        $match = $response['matches'][0];

        $this->assertSame($supplier->id, $match['supplier_id']);
        $this->assertGreaterThan(0, $match['score']);
        $this->assertLessThanOrEqual(100, $match['score']);
        $this->assertEquals(100.0, $match['fulfillable_volume']); // min(requirement volume, capacity)
        $this->assertArrayHasKey('capacity_fit', $match['reason']);
        $this->assertArrayHasKey('spec_compliance', $match['reason']);

        $this->assertDatabaseHas('matches', [
            'buyer_requirement_id' => $requirement->id,
            'supplier_id' => $supplier->id,
        ]);
    }

    public function test_match_reports_no_match_when_no_supplier_capacity_exists_for_the_product_form(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id, 'volume' => 1000]);

        // No supplier_capacity row at all for this product form — a genuinely empty candidate pool.
        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/match")->assertOk()->json();

        $this->assertFalse($response['matched']);
        $this->assertSame('no_supplier_capacity_available', $response['code']);
        $this->assertDatabaseCount('matches', 0);
    }

    /**
     * Section 3.16's real scoring must actually differentiate candidates, not just create one
     * placeholder — a supplier who can fully cover the shortfall AND holds the required
     * certification must score higher than one who can barely cover a fraction of it and lacks
     * the certification. Asserts ORDERING, not a brittle exact number, per the task's own
     * instruction on how to test this honestly.
     */
    public function test_match_scoring_ranks_a_better_fit_supplier_above_a_partial_fit_supplier(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create([
            'product_id' => $product->id,
            'volume' => 1000,
            'specification' => ['certification' => 'Organic'],
        ]);

        $strongSupplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create([
            'supplier_id' => $strongSupplier->id,
            'product_form_id' => $productForm->id,
            'available_volume' => 1000, // fully covers the requirement
            'certifications' => ['Organic'], // holds the required certification
        ]);

        $weakSupplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create([
            'supplier_id' => $weakSupplier->id,
            'product_form_id' => $productForm->id,
            'available_volume' => 50, // barely covers 5% of the shortfall
            'certifications' => ['FairTrade'], // does NOT hold the required certification
        ]);

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/match")->assertCreated()->json();

        $scoresBySupplier = collect($response['matches'])->pluck('score', 'supplier_id');

        $this->assertGreaterThan($scoresBySupplier[$weakSupplier->id], $scoresBySupplier[$strongSupplier->id]);
        // Response is sorted best-first.
        $this->assertSame($strongSupplier->id, $response['matches'][0]['supplier_id']);
    }

    public function test_message_creates_conversation_and_reuses_it_on_second_call(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $first = $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'Interested in this volume.'])
            ->assertCreated()->json();

        $second = $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'Following up.'])
            ->assertCreated()->json();

        $this->assertSame($first['conversation_id'], $second['conversation_id']);
        $this->assertDatabaseCount('messages', 2);
        $this->assertDatabaseHas('conversations', [
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
    }

    public function test_offer_without_a_prior_match_returns_422(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $requirement = BuyerRequirement::factory()->create();

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/offer", [
            'price' => 100,
            'volume' => 50,
            'currency' => 'usd',
        ]);

        $response->assertStatus(422)->assertJson(['code' => 'match_required']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_offer_route_requires_a_fresh_pin_even_with_module_access_granted(): void
    {
        // Module access granted, but PIN was never freshly (re-)verified this session.
        $this->actingAsGatedUser(freshPin: false);

        $requirement = BuyerRequirement::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id]);

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/offer", [
            'match_id' => $match->id,
            'price' => 100,
            'volume' => 50,
            'currency' => 'usd',
        ]);

        $response->assertStatus(403)->assertJson(['code' => 'fresh_pin_required']);
        $this->assertDatabaseCount('offers', 0);
    }

    public function test_offer_succeeds_after_a_match_exists_with_fresh_pin(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $requirement = BuyerRequirement::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id]);

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/offer", [
            'match_id' => $match->id,
            'price' => 250.50,
            'volume' => 80,
            'currency' => 'usd',
        ])->assertCreated()->json();

        $this->assertSame($match->id, $response['offer']['match_id']);
        $this->assertEquals(250.5, $response['offer']['price']);
        $this->assertSame('USD', $response['offer']['currency']);
        $this->assertDatabaseHas('offers', ['match_id' => $match->id]);
    }

    public function test_negotiate_without_a_prior_offer_returns_422(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/negotiate", [
            'counter_price' => 90,
        ]);

        $response->assertStatus(422)->assertJson(['code' => 'offer_required']);
        $this->assertDatabaseCount('negotiations', 0);
    }

    public function test_negotiate_succeeds_after_an_offer_exists(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $requirement = BuyerRequirement::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/negotiate", [
            'offer_id' => $offer->id,
            'counter_price' => 199.99,
            'notes' => 'Can we do net 60?',
        ])->assertCreated()->json();

        $this->assertSame($offer->id, $response['negotiation']['offer_id']);
        $this->assertEquals(199.99, $response['negotiation']['counter_price']);
        $this->assertDatabaseHas('negotiations', ['offer_id' => $offer->id]);
    }

    public function test_save_is_idempotent(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $first = $this->postJson("/api/mie/requirements/{$requirement->id}/save")->assertCreated()->json();
        $second = $this->postJson("/api/mie/requirements/{$requirement->id}/save")->assertCreated()->json();

        $this->assertSame($first['saved_requirement_id'], $second['saved_requirement_id']);
        $this->assertDatabaseCount('saved_requirements', 1);
    }

    public function test_share_returns_a_reference_and_canonical_url_with_no_real_infrastructure(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $response = $this->postJson("/api/mie/requirements/{$requirement->id}/share")->assertOk()->json();

        $this->assertArrayHasKey('share_reference', $response);
        $this->assertStringContainsString((string) $requirement->id, $response['share_url']);
        $this->assertStringContainsString('No real sharing infrastructure', $response['note']);
    }
}
