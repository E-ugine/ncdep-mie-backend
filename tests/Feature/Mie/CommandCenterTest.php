<?php

namespace Tests\Feature\Mie;

use App\Enums\ContractStatus;
use App\Enums\DealEventType;
use App\Enums\DealPipelineStage;
use App\Enums\PaymentStatus;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\DealEvent;
use App\Models\Message;
use App\Models\Payment;
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

    public function test_supply_gaps_are_ranked_largest_first_and_exclude_non_positive_gaps(): void
    {
        $this->actingAsGatedUser();

        SupplyGap::factory()->create(['demand_volume' => 500, 'contracted_volume' => 400]); // gap 100
        SupplyGap::factory()->create(['demand_volume' => 900, 'contracted_volume' => 100]); // gap 800
        SupplyGap::factory()->create(['demand_volume' => 300, 'contracted_volume' => 250]); // gap 50
        SupplyGap::factory()->create(['demand_volume' => 100, 'contracted_volume' => 100]); // gap 0, excluded
        SupplyGap::factory()->create(['demand_volume' => 50, 'contracted_volume' => 80]); // negative, excluded

        $gaps = $this->getJson('/api/mie/command-center')->json()['supply_gaps'];

        $this->assertCount(3, $gaps);
        $this->assertEquals([800.0, 100.0, 50.0], array_column($gaps, 'gap'));
        $this->assertArrayHasKey('commodity', $gaps[0]);
        $this->assertArrayHasKey('buyer', $gaps[0]);
    }

    public function test_supply_gaps_respect_the_configured_limit(): void
    {
        $this->actingAsGatedUser();
        config(['mie.command_center.supply_gaps_limit' => 2]);

        SupplyGap::factory()->count(4)->create(['demand_volume' => 500, 'contracted_volume' => 100]);

        $gaps = $this->getJson('/api/mie/command-center')->json()['supply_gaps'];

        $this->assertCount(2, $gaps);
    }

    public function test_supply_gaps_are_scoped_to_the_linked_suppliers_product_forms(): void
    {
        $matchingForm = ProductForm::factory()->create();
        $otherForm = ProductForm::factory()->create();

        $matchingRequirement = $this->requirementOnProductForm($matchingForm->id);
        $otherRequirement = $this->requirementOnProductForm($otherForm->id);

        SupplyGap::factory()->create(['buyer_requirement_id' => $matchingRequirement->id, 'demand_volume' => 500, 'contracted_volume' => 100]);
        SupplyGap::factory()->create(['buyer_requirement_id' => $otherRequirement->id, 'demand_volume' => 900, 'contracted_volume' => 100]);

        $this->actingAsSupplierLinkedUser($matchingForm->id);

        $gaps = $this->getJson('/api/mie/command-center')->json()['supply_gaps'];

        $this->assertCount(1, $gaps);
        $this->assertSame($matchingRequirement->id, $gaps[0]['buyer_requirement_id']);
    }

    public function test_activity_feed_includes_new_requirements_stage_changes_and_confirmed_payments(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();

        // Deal::factory() cascades through negotiation/offer/match down to its OWN fresh
        // BuyerRequirement, so matching purely on `type` isn't enough below — every assertion
        // also checks link.id against the specific record this test created.
        $deal = Deal::factory()->create();
        DealEvent::factory()->create([
            'deal_id' => $deal->id,
            'event_type' => DealEventType::StageTransition,
            'to_stage' => DealPipelineStage::ContractPending,
        ]);

        $contract = Contract::factory()->create(['deal_id' => $deal->id]);
        Payment::factory()->create([
            'contract_id' => $contract->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now(),
            'amount' => 5000,
            'currency' => 'USD',
        ]);

        $feed = collect($this->getJson('/api/mie/command-center')->json()['activity_feed']);

        $requirementItem = $feed->first(fn ($item) => $item['type'] === 'new_requirement' && $item['link']['id'] === $requirement->id);
        $this->assertNotNull($requirementItem);
        $this->assertStringContainsString($requirement->buyer->name, $requirementItem['text']);
        $this->assertSame('requirement', $requirementItem['link']['type']);

        $stageItem = $feed->first(fn ($item) => $item['type'] === 'deal_stage_change' && $item['link']['id'] === $deal->id);
        $this->assertNotNull($stageItem);
        $this->assertSame("Deal #{$deal->id} advanced to Contract Pending", $stageItem['text']);
        $this->assertSame('deal', $stageItem['link']['type']);

        $paymentItem = $feed->first(fn ($item) => $item['type'] === 'payment_confirmed' && $item['link']['id'] === $deal->id);
        $this->assertNotNull($paymentItem);
        $this->assertStringContainsString('5,000.00', $paymentItem['text']);
    }

    public function test_activity_feed_excludes_items_outside_the_window_and_never_says_deposit(): void
    {
        $this->actingAsGatedUser();

        BuyerRequirement::factory()->create();
        $deal = Deal::factory()->create();

        // Deal::factory()'s cascade creates its own fresh (today) BuyerRequirement along the way —
        // push every requirement row (including that one and the explicit one above) out of the
        // window in one shot, then age the deal-side rows individually.
        BuyerRequirement::query()->update(['created_at' => now()->subDays(30)]);

        $oldEvent = DealEvent::factory()->create(['deal_id' => $deal->id, 'event_type' => DealEventType::StageTransition]);
        DealEvent::where('id', $oldEvent->id)->update(['created_at' => now()->subDays(30)]);

        $contract = Contract::factory()->create(['deal_id' => $deal->id]);
        Payment::factory()->create([
            'contract_id' => $contract->id,
            'status' => PaymentStatus::Paid,
            'paid_at' => now()->subDays(30),
        ]);

        $feed = $this->getJson('/api/mie/command-center')->json()['activity_feed'];

        $this->assertCount(0, $feed);
        $this->assertStringNotContainsString('deposit', strtolower(json_encode($feed)));
    }

    public function test_activity_feed_sorts_newest_first_and_respects_the_configured_limit(): void
    {
        $this->actingAsGatedUser();
        config(['mie.command_center.activity_feed_limit' => 2]);

        $older = BuyerRequirement::factory()->create();
        $newer = BuyerRequirement::factory()->create();
        $deal = Deal::factory()->create();

        // Push every OTHER buyer requirement (including the one Deal::factory()'s cascade just
        // created) out of the window, so only $older and $newer are in contention below.
        BuyerRequirement::where('id', $older->id)->update(['created_at' => now()->subDays(2)]);
        BuyerRequirement::where('id', $newer->id)->update(['created_at' => now()->subHours(1)]);
        BuyerRequirement::whereNotIn('id', [$older->id, $newer->id])->update(['created_at' => now()->subDays(30)]);

        DealEvent::factory()->create([
            'deal_id' => $deal->id,
            'event_type' => DealEventType::StageTransition,
            'created_at' => now()->subMinutes(5),
        ]);

        $feed = $this->getJson('/api/mie/command-center')->json()['activity_feed'];

        $this->assertCount(2, $feed);
        // Newest first: the deal-stage change (5 min ago) beats the newer requirement (1 hour ago),
        // and both beat the older requirement (2 days ago) — which must be pushed out by the limit.
        $this->assertSame('deal_stage_change', $feed[0]['type']);
        $this->assertSame('new_requirement', $feed[1]['type']);
    }

    public function test_activity_feed_deal_stage_changes_are_scoped_to_the_linked_supplier(): void
    {
        // Deliberately not using actingAsSupplierLinkedUser() here — that helper mints its OWN
        // supplier for capacity/product-form scoping tests. This test needs the acting user linked
        // to the EXACT supplier behind $myDeal's match, since deal scoping goes by supplier_id
        // directly (mirroring DashboardController::supplierDeals), not by product-form capacity.
        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create(['supplier_id' => $supplier->id]);
        $offer = \App\Models\Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = \App\Models\Negotiation::factory()->create(['offer_id' => $offer->id]);
        $myDeal = Deal::factory()->create(['negotiation_id' => $negotiation->id]);
        DealEvent::factory()->create(['deal_id' => $myDeal->id, 'event_type' => DealEventType::StageTransition]);

        $unrelatedDeal = Deal::factory()->create();
        DealEvent::factory()->create(['deal_id' => $unrelatedDeal->id, 'event_type' => DealEventType::StageTransition]);

        $user = User::factory()->create(['supplier_id' => $supplier->id]);
        $this->actingAs($user)->withSession(['module_access.granted_at' => now()->toISOString()]);

        $feed = $this->getJson('/api/mie/command-center')->json()['activity_feed'];
        $dealIds = collect($feed)->where('type', 'deal_stage_change')->pluck('link.id');

        $this->assertTrue($dealIds->contains($myDeal->id));
        $this->assertFalse($dealIds->contains($unrelatedDeal->id));
    }
}
