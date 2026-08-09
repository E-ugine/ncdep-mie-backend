<?php

namespace App\Http\Controllers\Mie;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\Mie\ConversationMessenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 3.12 — conversation history and replies, regardless of what a conversation is
 * attached to (requirement, deal, or contract).
 */
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationMessenger $messenger) {}

    public function messages(int $id): JsonResponse
    {
        $conversation = Conversation::with('messages.sender')->findOrFail($id);

        return response()->json([
            'conversation_id' => $conversation->id,
            'subject' => $conversation->subject,
            'conversable_type' => class_basename($conversation->conversable_type),
            'conversable_id' => $conversation->conversable_id,
            'messages' => $conversation->messages->map(fn ($message) => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'sender_name' => $message->sender->name,
                'body' => $message->body,
                'read_at' => $message->read_at?->toISOString(),
                'created_at' => $message->created_at->toISOString(),
            ])->values(),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        $message = $this->messenger->reply($conversation, $request->user()->id, $validated['message']);

        return response()->json([
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'body' => $message->body,
                'created_at' => $message->created_at->toISOString(),
            ],
        ], 201);
    }
}
