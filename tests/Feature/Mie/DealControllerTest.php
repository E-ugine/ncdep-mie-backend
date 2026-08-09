<?php

namespace Tests\Feature\Mie;

use App\Enums\DealPipelineStage;
use App\Models\BuyerRequirement;
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

class DealControllerTest extends TestCase
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
     * Full chain up to a fresh, unconverted negotiation: requirement -> match -> offer -> negotiation.
     */
    private function buildNegotiation(): Negotiation
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);

        return Negotiation::factory()->create(['offer_id' => $offer->id]);
    }

    public function test_endpoints_are_blocked_without_module_access(): void
    {
        $user = User::factory()->create();
        $negotiation = $this->buildNegotiation();

        $this->actingAs($user)->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")->assertStatus(403);
        $this->actingAs($user)->getJson('/api/mie/deals')->assertStatus(403);
    }

    public function test_convert_to_deal_creates_a_deal_and_rejects_a_duplicate(): void
    {
        $this->actingAsGatedUser();

        $negotiation = $this->buildNegotiation();

        $response = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")
            ->assertCreated()->json();

        $this->assertSame('open', $response['deal']['pipeline_stage']);
        $this->assertDatabaseHas('deals', ['negotiation_id' => $negotiation->id]);

        // One negotiation -> at most one deal.
        $second = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal");
        $second->assertStatus(422)->assertJson(['code' => 'deal_already_exists']);
        $this->assertDatabaseCount('deals', 1);
    }

    public function test_convert_to_deal_writes_exactly_one_initial_deal_event(): void
    {
        $this->actingAsGatedUser();

        $negotiation = $this->buildNegotiation();
        $response = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")->assertCreated()->json();

        $this->assertDatabaseCount('deal_events', 1);
        $this->assertDatabaseHas('deal_events', [
            'deal_id' => $response['deal']['id'],
            'event_type' => 'created',
            'from_stage' => null,
            'to_stage' => 'open',
        ]);
    }

    public function test_valid_stage_transition_writes_exactly_one_new_deal_event(): void
    {
        $this->actingAsGatedUser();

        $negotiation = $this->buildNegotiation();
        $dealId = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")->json('deal.id');

        $this->assertDatabaseCount('deal_events', 1); // just the creation event so far

        $response = $this->patchJson("/api/mie/deals/{$dealId}/stage", ['pipeline_stage' => 'negotiating'])
            ->assertOk()->json();

        $this->assertSame('negotiating', $response['deal']['pipeline_stage']);
        $this->assertDatabaseCount('deal_events', 2); // exactly one new row, not zero or several
        $this->assertDatabaseHas('deal_events', [
            'deal_id' => $dealId,
            'event_type' => 'stage_transition',
            'from_stage' => 'open',
            'to_stage' => 'negotiating',
        ]);
    }

    public function test_invalid_stage_transition_is_rejected_and_writes_no_event(): void
    {
        $this->actingAsGatedUser();

        $negotiation = $this->buildNegotiation();
        $dealId = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")->json('deal.id');

        // open -> completed directly is not in the transition map.
        $response = $this->patchJson("/api/mie/deals/{$dealId}/stage", ['pipeline_stage' => 'completed']);

        $response->assertStatus(422)->assertJson(['code' => 'invalid_stage_transition', 'current_stage' => 'open']);
        $this->assertSame(
            DealPipelineStage::Open,
            Deal::find($dealId)->pipeline_stage,
        );
        $this->assertDatabaseCount('deal_events', 1); // still just the creation event
    }

    public function test_index_filters_by_pipeline_stage_and_reports_event_count_and_requirement_summary(): void
    {
        $this->actingAsGatedUser();

        $negotiationA = $this->buildNegotiation();
        $dealAId = $this->postJson("/api/mie/negotiations/{$negotiationA->id}/convert-to-deal")->json('deal.id');
        $this->patchJson("/api/mie/deals/{$dealAId}/stage", ['pipeline_stage' => 'negotiating'])->assertOk();

        $negotiationB = $this->buildNegotiation();
        $this->postJson("/api/mie/negotiations/{$negotiationB->id}/convert-to-deal")->assertCreated();

        $filtered = $this->getJson('/api/mie/deals?pipeline_stage=negotiating')->assertOk()->json();

        $this->assertCount(1, $filtered['deals']);
        $this->assertSame($dealAId, $filtered['deals'][0]['id']);
        $this->assertSame(2, $filtered['deals'][0]['event_count']); // created + one transition
        $this->assertNotNull($filtered['deals'][0]['buyer_requirement']);

        $all = $this->getJson('/api/mie/deals')->assertOk()->json();
        $this->assertCount(2, $all['deals']);
    }

    public function test_show_returns_timeline_in_chronological_order(): void
    {
        $this->actingAsGatedUser();

        $negotiation = $this->buildNegotiation();
        $dealId = $this->postJson("/api/mie/negotiations/{$negotiation->id}/convert-to-deal")->json('deal.id');
        $this->patchJson("/api/mie/deals/{$dealId}/stage", ['pipeline_stage' => 'negotiating'])->assertOk();
        $this->patchJson("/api/mie/deals/{$dealId}/stage", ['pipeline_stage' => 'contract_pending'])->assertOk();

        $response = $this->getJson("/api/mie/deals/{$dealId}")->assertOk()->json();

        $this->assertCount(3, $response['timeline']);
        $this->assertSame(['created', 'stage_transition', 'stage_transition'], array_column($response['timeline'], 'event_type'));
        $this->assertSame([null, 'open', 'negotiating'], array_column($response['timeline'], 'from_stage'));
        $this->assertSame(['open', 'negotiating', 'contract_pending'], array_column($response['timeline'], 'to_stage'));
        $this->assertSame(['contract_signed'], $response['allowed_next_stages']);
    }
}
