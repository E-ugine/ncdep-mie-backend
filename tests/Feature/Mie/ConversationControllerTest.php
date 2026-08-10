<?php

namespace Tests\Feature\Mie;

use App\Models\BuyerRequirement;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerTest extends TestCase
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

    public function test_opening_a_thread_marks_the_other_partys_messages_read(): void
    {
        $this->actingAsGatedUser();
        $otherUser = User::factory()->create();

        $requirement = BuyerRequirement::factory()->create();
        $conversation = Conversation::factory()->create([
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
        $theirMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $otherUser->id,
            'read_at' => null,
        ]);

        $response = $this->getJson("/api/mie/conversations/{$conversation->id}/messages")->assertOk()->json();

        $this->assertNotNull($response['messages'][0]['read_at']);
        $this->assertNotNull($theirMessage->fresh()->read_at);
    }

    public function test_opening_a_thread_never_marks_the_users_own_messages_read(): void
    {
        $user = $this->actingAsGatedUser();

        $requirement = BuyerRequirement::factory()->create();
        $conversation = Conversation::factory()->create([
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
        $ownMessage = Message::factory()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'read_at' => null,
        ]);

        $this->getJson("/api/mie/conversations/{$conversation->id}/messages")->assertOk();

        // Own messages were never "unread" for their own sender — the mark-as-read write must
        // leave them exactly as they were (still null), not stamp them incidentally.
        $this->assertNull($ownMessage->fresh()->read_at);
    }

    public function test_opening_a_thread_twice_is_idempotent(): void
    {
        $this->actingAsGatedUser();
        $otherUser = User::factory()->create();

        $requirement = BuyerRequirement::factory()->create();
        $conversation = Conversation::factory()->create([
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $otherUser->id, 'read_at' => null]);

        $this->getJson("/api/mie/conversations/{$conversation->id}/messages")->assertOk();
        $firstReadAt = Message::first()->read_at;

        $this->getJson("/api/mie/conversations/{$conversation->id}/messages")->assertOk();
        $secondReadAt = Message::first()->fresh()->read_at;

        $this->assertNotNull($firstReadAt);
        $this->assertTrue($firstReadAt->equalTo($secondReadAt));
    }

    public function test_opening_a_thread_reduces_the_command_center_unread_count(): void
    {
        $user = $this->actingAsGatedUser();
        $otherUser = User::factory()->create();

        $requirement = BuyerRequirement::factory()->create();
        $conversation = Conversation::factory()->create([
            'conversable_type' => BuyerRequirement::class,
            'conversable_id' => $requirement->id,
        ]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $user->id, 'read_at' => null]);
        Message::factory()->create(['conversation_id' => $conversation->id, 'sender_id' => $otherUser->id, 'read_at' => null]);

        $before = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(1, $before['unread_messages_count']);

        $this->getJson("/api/mie/conversations/{$conversation->id}/messages")->assertOk();

        $after = $this->getJson('/api/mie/command-center')->json();
        $this->assertSame(0, $after['unread_messages_count']);
    }
}
