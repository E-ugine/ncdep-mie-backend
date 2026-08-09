<?php

namespace Tests\Feature\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Enums\ShipmentStatus;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\SavedRequirement;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function actingAsGatedUser(?int $supplierId = null): User
    {
        $user = User::factory()->create(['supplier_id' => $supplierId]);
        $this->actingAs($user)->withSession(['module_access.granted_at' => now()->toISOString()]);

        return $user;
    }

    /**
     * Full chain from a product_form through to a Deal for the given supplier, optionally with a
     * Contract at a given status/shipment_status/delivery_date.
     */
    private function buildDealForSupplier(Supplier $supplier, DealPipelineStage $stage = DealPipelineStage::Open): Deal
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);

        return Deal::factory()->create(['negotiation_id' => $negotiation->id, 'pipeline_stage' => $stage]);
    }

    public function test_endpoint_is_blocked_without_module_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/mie/dashboard')->assertStatus(403);
    }

    public function test_my_market_reflects_saved_requirements_live_and_flags_unbuilt_sections(): void
    {
        $user = $this->actingAsGatedUser();

        $first = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertCount(0, $first['my_market']['saved_requirements']);
        $this->assertSame([], $first['my_market']['followed_markets']);
        $this->assertSame([], $first['my_market']['price_watchlist']);
        $this->assertStringContainsString('section 3.18', $first['my_market']['followed_markets_note']);

        $requirement = BuyerRequirement::factory()->create();
        SavedRequirement::factory()->create(['user_id' => $user->id, 'buyer_requirement_id' => $requirement->id]);

        $second = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertCount(1, $second['my_market']['saved_requirements']);
        $this->assertSame($requirement->id, $second['my_market']['saved_requirements'][0]['buyer_requirement_id']);
    }

    public function test_my_supply_is_empty_with_note_when_unlinked_and_populated_when_linked(): void
    {
        $this->actingAsGatedUser();

        $unlinked = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertSame([], $unlinked['my_supply']['capacity']);
        $this->assertStringContainsString('no linked supplier profile', $unlinked['my_supply']['note']);

        $supplier = Supplier::factory()->create();
        $productForm = ProductForm::factory()->create();
        SupplierCapacity::factory()->create([
            'supplier_id' => $supplier->id,
            'product_form_id' => $productForm->id,
            'capacity_volume' => 1000,
            'available_volume' => 400,
        ]);

        $this->actingAsGatedUser($supplier->id);

        $linked = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertCount(1, $linked['my_supply']['capacity']);
        $this->assertEquals(400.0, $linked['my_supply']['capacity'][0]['available_volume']);
        $this->assertNull($linked['my_supply']['capacity'][0]['certifications']);
    }

    public function test_my_deals_groups_by_pipeline_stage_and_reflects_live_changes(): void
    {
        $supplier = Supplier::factory()->create();
        $this->actingAsGatedUser($supplier->id);

        $first = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertSame(0, $first['my_deals']['total_count']);
        $this->assertSame([], $first['my_deals']['by_stage']['open']);

        $this->buildDealForSupplier($supplier, DealPipelineStage::Open);

        $second = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertSame(1, $second['my_deals']['total_count']);
        $this->assertCount(1, $second['my_deals']['by_stage']['open']);
        $this->assertCount(0, $second['my_deals']['by_stage']['negotiating']);

        $this->buildDealForSupplier($supplier, DealPipelineStage::Negotiating);

        $third = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $this->assertSame(2, $third['my_deals']['total_count']);
        $this->assertCount(1, $third['my_deals']['by_stage']['negotiating']);
    }

    public function test_my_deals_is_empty_for_every_stage_when_unlinked(): void
    {
        $this->actingAsGatedUser();

        $response = $this->getJson('/api/mie/dashboard')->assertOk()->json();

        $this->assertSame(0, $response['my_deals']['total_count']);
        foreach (DealPipelineStage::cases() as $stage) {
            $this->assertSame([], $response['my_deals']['by_stage'][$stage->value]);
        }
    }

    public function test_my_money_computes_value_by_status_expected_revenue_and_receivables(): void
    {
        $supplier = Supplier::factory()->create();
        $this->actingAsGatedUser($supplier->id);

        $draftDeal = $this->buildDealForSupplier($supplier);
        Contract::factory()->create(['deal_id' => $draftDeal->id, 'status' => ContractStatus::Draft, 'value' => 1000]);

        $activeDeal = $this->buildDealForSupplier($supplier);
        Contract::factory()->create(['deal_id' => $activeDeal->id, 'status' => ContractStatus::Active, 'value' => 2000]);

        $cancelledDeal = $this->buildDealForSupplier($supplier);
        Contract::factory()->create(['deal_id' => $cancelledDeal->id, 'status' => ContractStatus::Cancelled, 'value' => 5000]);

        // Delivered but the deal is still awaiting payment -> a real receivable.
        $receivableDeal = $this->buildDealForSupplier($supplier, DealPipelineStage::PaymentPending);
        Contract::factory()->create([
            'deal_id' => $receivableDeal->id,
            'status' => ContractStatus::Active,
            'shipment_status' => ShipmentStatus::Delivered,
            'value' => 750,
        ]);

        // Delivered AND already completed (paid) -> must NOT count as a receivable.
        $paidDeal = $this->buildDealForSupplier($supplier, DealPipelineStage::Completed);
        Contract::factory()->create([
            'deal_id' => $paidDeal->id,
            'status' => ContractStatus::Completed,
            'shipment_status' => ShipmentStatus::Delivered,
            'value' => 999,
        ]);

        $response = $this->getJson('/api/mie/dashboard')->assertOk()->json();
        $money = $response['my_money'];

        $this->assertEquals(1000.0, $money['value_by_status']['draft']);
        // Two contracts are status=active: the plain one (2000) and the receivable one (750).
        $this->assertEquals(2750.0, $money['value_by_status']['active']);
        $this->assertEquals(999.0, $money['value_by_status']['completed']);

        // expected_revenue = every non-cancelled contract: 1000 + 2000 + 750 + 999 = 4749
        $this->assertEquals(4749.0, $money['expected_revenue']);

        // receivables = only the delivered-but-payment-pending one = 750
        $this->assertEquals(750.0, $money['receivables']);
    }

    public function test_my_money_is_zeroed_out_with_note_when_unlinked(): void
    {
        $this->actingAsGatedUser();

        $response = $this->getJson('/api/mie/dashboard')->assertOk()->json();

        $this->assertEquals(0.0, $response['my_money']['expected_revenue']);
        $this->assertEquals(0.0, $response['my_money']['receivables']);
        $this->assertStringContainsString('no linked supplier profile', $response['my_money']['note']);
    }

    public function test_my_documents_aggregates_contract_documents(): void
    {
        $supplier = Supplier::factory()->create();
        $this->actingAsGatedUser($supplier->id);

        $deal = $this->buildDealForSupplier($supplier);
        Contract::factory()->create([
            'deal_id' => $deal->id,
            'documents' => ['proforma_invoice.pdf', 'phytosanitary_cert.pdf'],
        ]);

        $response = $this->getJson('/api/mie/dashboard')->assertOk()->json();

        $this->assertCount(1, $response['my_documents']['contract_documents']);
        $this->assertSame(
            ['proforma_invoice.pdf', 'phytosanitary_cert.pdf'],
            $response['my_documents']['contract_documents'][0]['documents'],
        );
        $this->assertNull($response['my_documents']['supplier_certifications']);
    }
}
