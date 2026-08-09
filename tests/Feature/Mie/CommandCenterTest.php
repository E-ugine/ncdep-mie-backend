<?php

namespace Tests\Feature\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealPipelineStage;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Message;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierCapacity;
use App\Models\SupplierMatch;
use App\Models\SupplyGap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommandCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Same reasoning as ModuleAccessTest: Sanctum only starts the session for requests
        // it recognizes as first-party frontend traffic.
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function actingAsGatedUser(): User
    {
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['module_access.granted_at' => now()->toISOString()]);

        return $user;
    }

    /**
     * Switches the acting user to one linked to a supplier that has capacity for exactly
     * $capableProductFormId — used to prove the command-center metrics narrow to that supplier's
     * product forms once linked, rather than staying global.
     */
    private function actingAsSupplierLinkedUser(int $capableProductFormId): User
    {
        $supplier = Supplier::factory()->create();
        SupplierCapacity::factory()->create(['supplier_id' => $supplier->id, 'product_form_id' => $capableProductFormId]);

        $user = User::factory()->create(['supplier_id' => $supplier->id]);
        $this->actingAs($user)->withSession(['module_access.granted_at' => now()->toISOString()]);

        return $user;
    }

    private function requirementOnProductForm(int $productFormId, array $overrides = []): BuyerRequirement
    {
        $product = Product::factory()->create(['product_form_id' => $productFormId]);

        return BuyerRequirement::factory()->create(array_merge(['product_id' => $product->id], $overrides));
    }

    public function test_endpoint_is_blocked_without_module_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/mie/command-center')
            ->assertStatus(403);
    }

    public function test_scope_and_scope_note_reflect_supplier_linkage(): void
    {
        $unlinkedUser = $this->actingAsGatedUser();
        $this->assertNull($unlinkedUser->supplier_id);

        $unlinked = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame('global', $unlinked['scope']);
        $this->assertStringContainsString('no linked supplier profile', $unlinked['scope_note']);

        $form = ProductForm::factory()->create();
        $linkedUser = $this->actingAsSupplierLinkedUser($form->id);

        $linked = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame('supplier', $linked['scope']);
        $this->assertStringContainsString((string) $linkedUser->supplier_id, $linked['scope_note']);
    }

    public function test_new_buyer_requirements_count_reflects_real_records_and_changes(): void
    {
        $this->actingAsGatedUser();

        // Two old requirements, outside the "new" window.
        $old = BuyerRequirement::factory()->count(2)->create();
        BuyerRequirement::whereIn('id', $old->pluck('id'))->update(['created_at' => now()->subDays(30)]);

        $first = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(0, $first['new_buyer_requirements']['count']);
        $this->assertSame(7, $first['new_buyer_requirements']['window_days']);

        // Creating a fresh one must move the count — proving it's a live query, not a constant.
        BuyerRequirement::factory()->create();

        $second = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(1, $second['new_buyer_requirements']['count']);
    }

    public function test_open_supply_gaps_count_only_counts_positive_gaps_and_changes(): void
    {
        $this->actingAsGatedUser();

        SupplyGap::factory()->create(['demand_volume' => 100, 'contracted_volume' => 100]); // gap = 0, not open
        SupplyGap::factory()->create(['demand_volume' => 50, 'contracted_volume' => 80]); // gap negative, not open

        $first = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(0, $first['open_supply_gaps_count']);
        $this->assertSame('global', $first['scope']);

        SupplyGap::factory()->create(['demand_volume' => 1000, 'contracted_volume' => 400]); // gap = 600, open

        $second = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(1, $second['open_supply_gaps_count']);

        // Supplier-scoped case: two more open gaps on different product forms; a supplier linked
        // to only ONE of those forms should see the count narrow to just that one, not all three.
        $matchingForm = ProductForm::factory()->create();
        $otherForm = ProductForm::factory()->create();

        $matchingRequirement = $this->requirementOnProductForm($matchingForm->id);
        $otherRequirement = $this->requirementOnProductForm($otherForm->id);

        SupplyGap::factory()->create(['buyer_requirement_id' => $matchingRequirement->id, 'demand_volume' => 500, 'contracted_volume' => 100]);
        SupplyGap::factory()->create(['buyer_requirement_id' => $otherRequirement->id, 'demand_volume' => 500, 'contracted_volume' => 100]);

        $this->actingAsSupplierLinkedUser($matchingForm->id);

        $scoped = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame('supplier', $scoped['scope']);
        $this->assertSame(1, $scoped['open_supply_gaps_count']);
    }

    public function test_active_deals_count_only_counts_active_pipeline_stages(): void
    {
        $this->actingAsGatedUser();

        Deal::factory()->create(['pipeline_stage' => DealPipelineStage::Open]);
        Deal::factory()->create(['pipeline_stage' => DealPipelineStage::Negotiating]);
        Deal::factory()->create(['pipeline_stage' => DealPipelineStage::Completed]);
        Deal::factory()->create(['pipeline_stage' => DealPipelineStage::Delivered]);

        $response = $this->getJson('/api/mie/command-center')->json();

        $this->assertSame(2, $response['active_deals_count']);
    }

    public function test_contracts_awaiting_action_maps_to_draft_status(): void
    {
        $this->actingAsGatedUser();

        Contract::factory()->create(['status' => ContractStatus::Draft]);
        Contract::factory()->create(['status' => ContractStatus::Draft]);
        Contract::factory()->create(['status' => ContractStatus::Active]);
        Contract::factory()->create(['status' => ContractStatus::Completed]);

        $response = $this->getJson('/api/mie/command-center')->json();

        $this->assertSame(2, $response['contracts_awaiting_action_count']);
    }

    public function test_unread_messages_count_is_scoped_to_conversations_the_user_sent_into(): void
    {
        $user = $this->actingAsGatedUser();
        $otherUser = User::factory()->create();
        $thirdUser = User::factory()->create();

        // Conversation the auth user is "party to" (they've sent a message in it).
        $myConversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $myConversation->id, 'sender_id' => $user->id, 'read_at' => null]);
        Message::factory()->create(['conversation_id' => $myConversation->id, 'sender_id' => $otherUser->id, 'read_at' => null]); // unread, counts
        Message::factory()->create(['conversation_id' => $myConversation->id, 'sender_id' => $otherUser->id, 'read_at' => now()]); // already read, doesn't count

        // Conversation the auth user has never sent into — not "party to" it under this proxy.
        $unrelatedConversation = Conversation::factory()->create();
        Message::factory()->create(['conversation_id' => $unrelatedConversation->id, 'sender_id' => $otherUser->id, 'read_at' => null]);
        Message::factory()->create(['conversation_id' => $unrelatedConversation->id, 'sender_id' => $thirdUser->id, 'read_at' => null]);

        $response = $this->getJson('/api/mie/command-center')->json();

        $this->assertSame(1, $response['unread_messages_count']);
    }

    public function test_new_market_opportunities_counts_requirements_without_any_match(): void
    {
        $this->actingAsGatedUser();

        BuyerRequirement::factory()->create();
        $matched = BuyerRequirement::factory()->create();
        SupplierMatch::factory()->create(['buyer_requirement_id' => $matched->id]);

        $response = $this->getJson('/api/mie/command-center')->json();

        $this->assertSame(1, $response['new_market_opportunities_count']);

        // Supplier-scoped case: an unmatched requirement on a form the linked supplier does NOT
        // have capacity in must not count, even though it's unmatched exactly like the one that does.
        $matchingForm = ProductForm::factory()->create();
        $otherForm = ProductForm::factory()->create();

        $this->requirementOnProductForm($matchingForm->id); // unmatched, supplier can produce this
        $this->requirementOnProductForm($otherForm->id); // unmatched, but supplier can't produce this

        $this->actingAsSupplierLinkedUser($matchingForm->id);

        $scoped = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame('supplier', $scoped['scope']);
        $this->assertSame(1, $scoped['new_market_opportunities_count']);
    }

    public function test_total_addressable_opportunity_value_is_null_safe_and_excludes_completed_deals(): void
    {
        $this->actingAsGatedUser();

        // No price data at all — must not error, contributes 0.
        BuyerRequirement::factory()->create(['volume' => 500]);

        $response = $this->getJson('/api/mie/command-center')->json();
        // json_encode collapses a whole-number float (0.0) to a bare 0 — assertEquals, not assertSame.
        $this->assertEquals(0.0, $response['total_addressable_opportunity_value']);

        // Requirement with a priced match and no deal — should be counted.
        $priced = BuyerRequirement::factory()->create(['volume' => 100]);
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $priced->id]);
        \App\Models\Offer::factory()->create(['match_id' => $match->id, 'price' => 10]);

        // Requirement with a priced match but a COMPLETED deal — must be excluded from the total.
        $completed = BuyerRequirement::factory()->create(['volume' => 999]);
        $completedMatch = SupplierMatch::factory()->create(['buyer_requirement_id' => $completed->id]);
        $completedOffer = \App\Models\Offer::factory()->create(['match_id' => $completedMatch->id, 'price' => 500]);
        $negotiation = \App\Models\Negotiation::factory()->create(['offer_id' => $completedOffer->id]);
        \App\Models\Deal::factory()->create(['negotiation_id' => $negotiation->id, 'pipeline_stage' => DealPipelineStage::Completed]);

        $response = $this->getJson('/api/mie/command-center')->json();

        // 100 * 10 = 1000 from the priced/undealt requirement; the completed one is excluded entirely.
        $this->assertEquals(1000.0, $response['total_addressable_opportunity_value']);

        // Supplier-scoped case: a priced, undealt requirement on a form the linked supplier can't
        // produce must not contribute to their scoped total, even though it would count globally.
        $matchingForm = ProductForm::factory()->create();
        $otherForm = ProductForm::factory()->create();

        $matchingRequirement = $this->requirementOnProductForm($matchingForm->id, ['volume' => 50]);
        $matchingMatch = SupplierMatch::factory()->create(['buyer_requirement_id' => $matchingRequirement->id]);
        \App\Models\Offer::factory()->create(['match_id' => $matchingMatch->id, 'price' => 4]);

        $otherRequirement = $this->requirementOnProductForm($otherForm->id, ['volume' => 1000]);
        $otherMatch = SupplierMatch::factory()->create(['buyer_requirement_id' => $otherRequirement->id]);
        \App\Models\Offer::factory()->create(['match_id' => $otherMatch->id, 'price' => 900]);

        $this->actingAsSupplierLinkedUser($matchingForm->id);

        $scoped = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame('supplier', $scoped['scope']);
        // 50 * 4 = 200 from the matching-form requirement only; the other-form one (1000 * 900) is excluded.
        $this->assertEquals(200.0, $scoped['total_addressable_opportunity_value']);
    }
}
