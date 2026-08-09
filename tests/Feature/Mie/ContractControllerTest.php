<?php

namespace Tests\Feature\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Models\BuyerRequirement;
use App\Models\Contract;
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

class ContractControllerTest extends TestCase
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

    /**
     * Full chain through to a Deal, optionally pre-set to a given pipeline stage (direct model
     * update for test arrangement — not going through the PATCH endpoint, since these tests are
     * about contract creation, not the transition map itself).
     */
    private function buildDeal(?DealPipelineStage $atStage = null): Deal
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);
        $deal = Deal::factory()->create(['negotiation_id' => $negotiation->id, 'pipeline_stage' => DealPipelineStage::Open]);

        if ($atStage) {
            $deal->update(['pipeline_stage' => $atStage]);
        }

        return $deal->fresh();
    }

    public function test_endpoints_are_blocked_without_module_access(): void
    {
        $user = User::factory()->create();
        $deal = $this->buildDeal(DealPipelineStage::ContractPending);

        $this->actingAs($user)->postJson("/api/mie/deals/{$deal->id}/contract", [])->assertStatus(403);
        $this->actingAs($user)->getJson('/api/mie/contracts')->assertStatus(403);
    }

    /**
     * The second guard-clause enforcement of section 3.11's chain rule (mirrors stage 4's
     * offer-without-match 422).
     */
    public function test_contract_creation_on_a_deal_not_in_contract_pending_returns_422(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $deal = $this->buildDeal(DealPipelineStage::Negotiating);

        $response = $this->postJson("/api/mie/deals/{$deal->id}/contract", [
            'price' => 100,
            'currency' => 'usd',
            'incoterm' => 'fob',
            'delivery_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
        ]);

        $response->assertStatus(422)->assertJson([
            'code' => 'deal_not_contract_pending',
            'current_stage' => 'negotiating',
        ]);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_contract_route_requires_a_fresh_pin_even_with_module_access_granted(): void
    {
        $this->actingAsGatedUser(freshPin: false);

        $deal = $this->buildDeal(DealPipelineStage::ContractPending);

        $response = $this->postJson("/api/mie/deals/{$deal->id}/contract", [
            'price' => 100,
            'currency' => 'usd',
            'incoterm' => 'fob',
            'delivery_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
        ]);

        $response->assertStatus(403)->assertJson(['code' => 'fresh_pin_required']);
        $this->assertDatabaseCount('contracts', 0);
    }

    public function test_contract_creation_succeeds_and_auto_transitions_deal_to_contract_signed(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $deal = $this->buildDeal(DealPipelineStage::ContractPending);

        $response = $this->postJson("/api/mie/deals/{$deal->id}/contract", [
            'price' => 250,
            'currency' => 'usd',
            'incoterm' => 'fob',
            'delivery_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
        ])->assertCreated()->json();

        $this->assertEquals((float) $deal->agreed_volume * 250, $response['contract']['value']);
        $this->assertSame('draft', $response['contract']['status']);
        $this->assertSame('pending', $response['contract']['compliance_status']);
        $this->assertSame('pending', $response['contract']['shipment_status']);

        // Same transition path as PATCH /deals/{id}/stage — must still log a deal_event, not
        // bypass the observer by writing pipeline_stage directly.
        $this->assertSame(DealPipelineStage::ContractSigned, $deal->fresh()->pipeline_stage);
        $this->assertDatabaseHas('deal_events', [
            'deal_id' => $deal->id,
            'event_type' => 'stage_transition',
            'from_stage' => 'contract_pending',
            'to_stage' => 'contract_signed',
        ]);
    }

    public function test_contract_creation_rejects_a_duplicate_contract_for_the_same_deal(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $deal = $this->buildDeal(DealPipelineStage::ContractPending);

        $payload = [
            'price' => 100,
            'currency' => 'usd',
            'incoterm' => 'fob',
            'delivery_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
        ];

        $this->postJson("/api/mie/deals/{$deal->id}/contract", $payload)->assertCreated();

        // Deal is now contract_signed, not contract_pending, so this would 422 for that reason
        // too — but a contract already existing is the more specific, more useful error.
        $response = $this->postJson("/api/mie/deals/{$deal->id}/contract", $payload);
        $response->assertStatus(422);
        $this->assertContains($response->json('code'), ['contract_already_exists', 'deal_not_contract_pending']);
        $this->assertDatabaseCount('contracts', 1);
    }

    public function test_index_filters_by_view(): void
    {
        $this->actingAsGatedUser();

        Contract::factory()->create(['status' => ContractStatus::Draft]);
        Contract::factory()->create(['status' => ContractStatus::Active]);
        Contract::factory()->create(['status' => ContractStatus::Completed]);
        Contract::factory()->create(['status' => ContractStatus::Cancelled]);

        $draft = $this->getJson('/api/mie/contracts?view=draft')->assertOk()->json();
        $this->assertCount(1, $draft['contracts']);
        $this->assertSame('draft', $draft['contracts'][0]['status']);

        $active = $this->getJson('/api/mie/contracts?view=active')->assertOk()->json();
        $this->assertCount(1, $active['contracts']);

        $completed = $this->getJson('/api/mie/contracts?view=completed')->assertOk()->json();
        $this->assertCount(1, $completed['contracts']);

        $cancelled = $this->getJson('/api/mie/contracts?view=cancelled')->assertOk()->json();
        $this->assertCount(1, $cancelled['contracts']);
    }

    public function test_expiring_view_uses_the_configured_delivery_window(): void
    {
        $this->actingAsGatedUser();

        $soon = Contract::factory()->create([
            'status' => ContractStatus::Active,
            'delivery_date' => now()->addDays(5),
        ]);
        Contract::factory()->create([
            'status' => ContractStatus::Active,
            'delivery_date' => now()->addDays(60), // well outside the 14-day window
        ]);
        Contract::factory()->create([
            'status' => ContractStatus::Completed,
            'delivery_date' => now()->addDays(5), // soon, but already completed — not "expiring"
        ]);

        $response = $this->getJson('/api/mie/contracts?view=expiring')->assertOk()->json();

        $this->assertCount(1, $response['contracts']);
        $this->assertSame($soon->id, $response['contracts'][0]['id']);
    }

    public function test_offers_counteroffers_view_is_an_honest_alias_to_negotiations_not_contracts(): void
    {
        $this->actingAsGatedUser();

        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);

        $response = $this->getJson('/api/mie/contracts?view=offers_counteroffers')->assertOk()->json();

        $this->assertArrayNotHasKey('contracts', $response);
        $this->assertCount(1, $response['negotiations']);
        $this->assertSame($negotiation->id, $response['negotiations'][0]['id']);
        $this->assertStringContainsString('no distinct', $response['note']);
    }

    public function test_show_returns_full_detail_with_lineage_back_to_the_requirement(): void
    {
        $this->actingAsGatedUser(freshPin: true);

        $deal = $this->buildDeal(DealPipelineStage::ContractPending);

        $contractId = $this->postJson("/api/mie/deals/{$deal->id}/contract", [
            'price' => 100,
            'currency' => 'usd',
            'incoterm' => 'fob',
            'delivery_date' => now()->addDays(30)->toDateString(),
            'payment_terms' => 'Net 30',
        ])->json('contract.id');

        $response = $this->getJson("/api/mie/contracts/{$contractId}")->assertOk()->json();

        $this->assertSame($deal->id, $response['lineage']['deal_id']);
        $this->assertNotNull($response['lineage']['negotiation_id']);
        $this->assertNotNull($response['lineage']['offer_id']);
        $this->assertNotNull($response['lineage']['match_id']);
        $this->assertNotNull($response['lineage']['buyer_requirement_id']);
        $this->assertNotNull($response['parties']['buyer']);
        $this->assertNotNull($response['parties']['supplier']);
    }
}
