<?php

namespace Tests\Feature\Mie;

use App\Enums\DealPipelineStage;
use App\Enums\ShipmentStatus;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Models\SupplyGap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Section 3.15 — proves each notification trigger actually writes a row on its real event.
 * These exercise the observers/command directly (not through module.access-gated HTTP routes),
 * since what's under test is the model-event wiring itself, not the API gate.
 */
class NotificationEngineTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_matching_requirement_notifies_only_suppliers_with_matching_capacity(): void
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);

        $supplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create(['supplier_id' => $supplier->id, 'product_form_id' => $productForm->id]);
        $supplierUser = User::factory()->create(['supplier_id' => $supplier->id]);

        $otherSupplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create(['supplier_id' => $otherSupplier->id, 'product_form_id' => ProductForm::factory()->create()->id]);
        $otherSupplierUser = User::factory()->create(['supplier_id' => $otherSupplier->id]);

        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $supplierUser->id,
            'type' => 'new_matching_requirement',
            'notifiable_type' => BuyerRequirement::class,
            'notifiable_id' => $requirement->id,
        ]);

        $this->assertDatabaseMissing('notifications', [
            'user_id' => $otherSupplierUser->id,
            'type' => 'new_matching_requirement',
        ]);
    }

    public function test_supply_gap_notifies_matching_suppliers_only_once_gap_is_positive(): void
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $supplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create(['supplier_id' => $supplier->id, 'product_form_id' => $productForm->id]);
        $supplierUser = User::factory()->create(['supplier_id' => $supplier->id]);

        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        Notification::query()->delete(); // isolate from the requirement-creation notification above

        // Fully covered — no real gap — must NOT notify.
        $gap = SupplyGap::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'demand_volume' => 100,
            'contracted_volume' => 100,
        ]);
        $this->assertDatabaseCount('notifications', 0);

        // Now a real gap opens — must notify.
        $gap->update(['contracted_volume' => 20]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $supplierUser->id,
            'type' => 'supply_gap_detected',
            'notifiable_type' => BuyerRequirement::class,
            'notifiable_id' => $requirement->id,
        ]);
    }

    public function test_match_creation_notifies_the_matched_suppliers_linked_user(): void
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);

        $supplier = Supplier::factory()->create();
        $supplierUser = User::factory()->create(['supplier_id' => $supplier->id]);

        $match = SupplierMatch::factory()->create([
            'buyer_requirement_id' => $requirement->id,
            'supplier_id' => $supplier->id,
            'score' => 80,
        ]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $supplierUser->id,
            'type' => 'buyer_match_score_computed',
            'notifiable_type' => SupplierMatch::class,
            'notifiable_id' => $match->id,
        ]);
    }

    public function test_match_creation_notifies_nobody_when_the_supplier_has_no_linked_user(): void
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create(); // no linked user
        Notification::query()->delete();

        SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_deal_stage_transition_notifies_the_supplier_linked_user(): void
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $supplierUser = User::factory()->create(['supplier_id' => $supplier->id]);
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);
        $deal = Deal::factory()->create(['negotiation_id' => $negotiation->id, 'pipeline_stage' => DealPipelineStage::Open]);

        Notification::query()->delete(); // isolate from requirement/match creation notifications above

        $deal->update(['pipeline_stage' => DealPipelineStage::Negotiating]);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $supplierUser->id,
            'type' => 'deal_status_change',
            'notifiable_type' => Deal::class,
            'notifiable_id' => $deal->id,
        ]);
    }

    public function test_notify_expiring_contracts_command_only_notifies_soon_undelivered_contracts(): void
    {
        $days = (int) config('mie.contracts.expiring_within_days');

        $soon = $this->buildDealForSupplierUser();
        $soonContract = Contract::factory()->create([
            'deal_id' => $soon['deal']->id,
            'shipment_status' => ShipmentStatus::Pending,
            'delivery_date' => now()->addDays(2),
        ]);

        $delivered = $this->buildDealForSupplierUser();
        Contract::factory()->create([
            'deal_id' => $delivered['deal']->id,
            'shipment_status' => ShipmentStatus::Delivered,
            'delivery_date' => now()->addDays(2),
        ]);

        $far = $this->buildDealForSupplierUser();
        Contract::factory()->create([
            'deal_id' => $far['deal']->id,
            'shipment_status' => ShipmentStatus::Pending,
            'delivery_date' => now()->addDays($days + 30),
        ]);

        Notification::query()->delete();

        $this->artisan('mie:notify-expiring-contracts')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'user_id' => $soon['user']->id,
            'type' => 'contract_expiring',
            'notifiable_type' => Contract::class,
            'notifiable_id' => $soonContract->id,
        ]);
        $this->assertDatabaseMissing('notifications', ['user_id' => $delivered['user']->id, 'type' => 'contract_expiring']);
        $this->assertDatabaseMissing('notifications', ['user_id' => $far['user']->id, 'type' => 'contract_expiring']);
    }

    /**
     * @return array{deal: Deal, user: User}
     */
    private function buildDealForSupplierUser(): array
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $user = User::factory()->create(['supplier_id' => $supplier->id]);
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);
        $deal = Deal::factory()->create(['negotiation_id' => $negotiation->id]);

        return ['deal' => $deal, 'user' => $user];
    }
}
