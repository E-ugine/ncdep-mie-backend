<?php

namespace Tests\Feature\Mie;

use App\Models\BuyerRequirement;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\SupplyGap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpportunityScoreTest extends TestCase
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

    public function test_endpoint_is_blocked_without_module_access(): void
    {
        $user = User::factory()->create();
        $requirement = BuyerRequirement::factory()->create();

        $this->actingAs($user)->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")
            ->assertStatus(403);
    }

    public function test_returns_composite_score_breakdown_and_priority_tier(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $response = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();

        $this->assertArrayHasKey('composite_score', $response);
        $this->assertArrayHasKey('breakdown', $response);
        $this->assertContains($response['priority_tier'], ['high', 'medium', 'low']);
        $this->assertArrayHasKey('estimated_annual_opportunity_value', $response);
        $this->assertArrayHasKey('buyer_count', $response);
        $this->assertArrayHasKey('supply_gap_volume', $response);

        foreach (['demand_strength', 'price', 'supply_gap_size', 'origin_suitability', 'logistics_feasibility', 'competitive_intensity', 'compliance_fit'] as $component) {
            $this->assertArrayHasKey($component, $response['breakdown']);
        }
    }

    /**
     * The composite must be a live computation, not a cached/static number — growing the real
     * SupplyGap must move the supply_gap_size component (and therefore the composite).
     */
    public function test_opportunity_score_changes_when_the_supply_gap_changes(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();
        $gap = SupplyGap::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'demand_volume' => 1000,
            'contracted_volume' => 900, // gap = 100, 10% of demand
        ]);

        $before = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();
        $this->assertEquals(10.0, $before['breakdown']['supply_gap_size']['value']);
        $this->assertEquals(100.0, $before['supply_gap_volume']);

        $gap->update(['contracted_volume' => 200]); // gap = 800, 80% of demand

        $after = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();
        $this->assertEquals(80.0, $after['breakdown']['supply_gap_size']['value']);
        $this->assertEquals(800.0, $after['supply_gap_volume']);

        $this->assertNotEquals($before['composite_score'], $after['composite_score']);
    }

    public function test_demand_strength_is_null_with_no_baseline_and_real_once_other_requirements_exist(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id, 'volume' => 1000]);

        // No other requirement for this product yet — genuinely no baseline to compare against.
        $alone = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();
        $this->assertNull($alone['breakdown']['demand_strength']['value']);
        $this->assertNull($alone['breakdown']['demand_strength']['contribution']);

        // Two other requirements for the same product, averaging 500 -> this one (1000) is 2x
        // typical -> demand_strength = min(100, 2 * 50) = 100.
        BuyerRequirement::factory()->create(['product_id' => $product->id, 'volume' => 400]);
        BuyerRequirement::factory()->create(['product_id' => $product->id, 'volume' => 600]);

        $withBaseline = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();
        $this->assertEquals(100.0, $withBaseline['breakdown']['demand_strength']['value']);
    }

    public function test_estimated_annual_opportunity_value_is_null_without_a_priced_offer(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        $response = $this->getJson("/api/mie/requirements/{$requirement->id}/opportunity-score")->assertOk()->json();

        $this->assertNull($response['estimated_annual_opportunity_value']);
    }
}
