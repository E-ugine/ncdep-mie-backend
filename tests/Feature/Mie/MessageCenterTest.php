<?php

namespace Tests\Feature\Mie;

use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Deal;
use App\Models\Negotiation;
use App\Models\Notification;
use App\Models\Offer;
use App\Models\Product;
use App\Models\ProductForm;
use App\Models\Supplier;
use App\Models\SupplierMatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MessageCenterTest extends TestCase
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
     * Full chain through to a Deal (and, if $withContract, a Contract too).
     */
    private function buildDeal(bool $withContract = false): Deal
    {
        $productForm = ProductForm::factory()->create();
        $product = Product::factory()->create(['product_form_id' => $productForm->id]);
        $requirement = BuyerRequirement::factory()->create(['product_id' => $product->id]);
        $supplier = Supplier::factory()->create();
        $match = SupplierMatch::factory()->create(['buyer_requirement_id' => $requirement->id, 'supplier_id' => $supplier->id]);
        $offer = Offer::factory()->create(['match_id' => $match->id]);
        $negotiation = Negotiation::factory()->create(['offer_id' => $offer->id]);
        $deal = Deal::factory()->create(['negotiation_id' => $negotiation->id]);

        if ($withContract) {
            Contract::factory()->create(['deal_id' => $deal->id]);
        }

        return $deal->fresh('contract');
    }

    public function test_endpoints_are_blocked_without_module_access(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/mie/messages')->assertStatus(403);
    }

    public function test_messages_are_segmented_by_conversable_type_with_labels(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();
        $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'Interested in this volume.'])
            ->assertCreated();

        $deal = $this->buildDeal();
        $this->postJson("/api/mie/deals/{$deal->id}/message", ['message' => 'Can we confirm delivery window?'])
            ->assertCreated();

        $dealWithContract = $this->buildDeal(withContract: true);
        $this->postJson("/api/mie/contracts/{$dealWithContract->contract->id}/message", ['message' => 'Please review the attached documents.'])
            ->assertCreated();

        $response = $this->getJson('/api/mie/messages')->assertOk()->json();

        $this->assertCount(1, $response['requirement_conversations']);
        $this->assertSame('BuyerRequirement', $response['requirement_conversations'][0]['conversable_type']);
        $this->assertStringStartsWith("Requirement #{$requirement->id}", $response['requirement_conversations'][0]['label']);

        $this->assertCount(1, $response['deal_conversations']);
        $this->assertSame('Deal', $response['deal_conversations'][0]['conversable_type']);
        $this->assertStringStartsWith("Deal #{$deal->id}", $response['deal_conversations'][0]['label']);

        $this->assertCount(1, $response['contract_conversations']);
        $this->assertSame('Contract', $response['contract_conversations'][0]['conversable_type']);
        $this->assertStringStartsWith("Contract #{$dealWithContract->contract->id}", $response['contract_conversations'][0]['label']);
    }

    public function test_system_notifications_are_scoped_to_the_authenticated_user(): void
    {
        $user = $this->actingAsGatedUser();
        $otherUser = User::factory()->create();

        Notification::factory()->create(['user_id' => $user->id, 'type' => 'price_movement']);
        Notification::factory()->create(['user_id' => $otherUser->id, 'type' => 'contract_expiring']);

        $response = $this->getJson('/api/mie/messages')->assertOk()->json();

        $this->assertCount(1, $response['system_notifications']);
        $this->assertSame('price_movement', $response['system_notifications'][0]['type']);
    }

    public function test_conversation_history_and_reply(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();
        $conversationId = $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'First message.'])
            ->json('conversation_id');

        $history = $this->getJson("/api/mie/conversations/{$conversationId}/messages")->assertOk()->json();
        $this->assertCount(1, $history['messages']);
        $this->assertSame('First message.', $history['messages'][0]['body']);

        $this->postJson("/api/mie/conversations/{$conversationId}/messages", ['message' => 'A reply.'])->assertCreated();

        $historyAfter = $this->getJson("/api/mie/conversations/{$conversationId}/messages")->assertOk()->json();
        $this->assertCount(2, $historyAfter['messages']);
        $this->assertSame('A reply.', $historyAfter['messages'][1]['body']);
    }

    /**
     * Deal/contract messaging must reuse the exact same conversation-reuse semantics as
     * requirement messaging (ConversationMessenger::sendToConversable's firstOrCreate) — proven
     * behaviorally: calling /message twice on the same deal/contract must create exactly ONE
     * conversation but TWO messages, identical to how requirement messaging already behaves.
     */
    public function test_deal_and_contract_messaging_reuse_the_same_conversation_semantics_as_requirement_messaging(): void
    {
        $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();
        $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'one'])->assertCreated();
        $this->postJson("/api/mie/requirements/{$requirement->id}/message", ['message' => 'two'])->assertCreated();

        $deal = $this->buildDeal();
        $this->postJson("/api/mie/deals/{$deal->id}/message", ['message' => 'one'])->assertCreated();
        $this->postJson("/api/mie/deals/{$deal->id}/message", ['message' => 'two'])->assertCreated();

        $dealWithContract = $this->buildDeal(withContract: true);
        $this->postJson("/api/mie/contracts/{$dealWithContract->contract->id}/message", ['message' => 'one'])->assertCreated();
        $this->postJson("/api/mie/contracts/{$dealWithContract->contract->id}/message", ['message' => 'two'])->assertCreated();

        $this->assertDatabaseCount('conversations', 3); // one per conversable, not one per message
        $this->assertDatabaseCount('messages', 6); // two per conversable

        $this->assertDatabaseHas('conversations', [
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
        $this->assertDatabaseHas('conversations', ['conversable_type' => Deal::class, 'conversable_id' => $deal->id]);
        $this->assertDatabaseHas('conversations', [
            'conversable_type' => Contract::class,
            'conversable_id' => $dealWithContract->contract->id,
        ]);
    }
}
