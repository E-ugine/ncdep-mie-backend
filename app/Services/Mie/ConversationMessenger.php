<?php

namespace App\Services\Mie;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared core of every "message on a commercial object" action in the module — requirement
 * messaging (section 3.4, stage 4), and now deal/contract messaging (section 3.12). Originally
 * inline in RequirementController::message(); extracted here so all three entry points (plus a
 * direct reply on an existing conversation) go through the same two operations rather than each
 * having its own copy-pasted firstOrCreate()/Message::create() pair.
 */
class ConversationMessenger
{
    /**
     * Finds or creates the conversation bound to this commercial object, then posts a message
     * into it. `$conversable` is whatever model conversations already morph to
     * (BuyerRequirement, Deal, or Contract) — never a generic thread.
     *
     * @return array{conversation: Conversation, message: Message}
     */
    public function sendToConversable(Model $conversable, int $senderId, string $body, string $subject): array
    {
        $conversation = Conversation::firstOrCreate(
            ['conversable_type' => $conversable::class, 'conversable_id' => $conversable->getKey()],
            ['subject' => $subject],
        );

        return [
            'conversation' => $conversation,
            'message' => $this->reply($conversation, $senderId, $body),
        ];
    }

    public function reply(Conversation $conversation, int $senderId, string $body): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $senderId,
            'body' => $body,
        ]);
    }
}
