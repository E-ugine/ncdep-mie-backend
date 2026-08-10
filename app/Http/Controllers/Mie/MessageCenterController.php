<?php

namespace App\Http\Controllers\Mie;

use App\Http\Controllers\Controller;
use App\Models\Buyer;
use App\Models\BuyerRequirement;
use App\Models\Contract;
use App\Models\Conversation;
use App\Models\Deal;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Section 3.12 — Message Center. The spec's literal segmentation ("buyer conversations, supplier
 * conversations, deal conversations, contract conversations") maps onto this schema as three
 * buckets, not four: there is no separate Buyer- or Supplier-conversable path distinct from a
 * requirement — buyer-side and supplier-side communication both happen on the requirement's
 * conversation (stage 4's messaging). So segmentation here is: requirement conversations, deal
 * conversations, contract conversations, system notifications.
 *
 * "Party to a conversation" uses the same proxy as the command center's unread-messages count
 * (no participants table exists): a conversation counts as this user's if they've sent at least
 * one message in it.
 */
class MessageCenterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        // Build the notifications payload from their read_at as of THIS request (so a
        // notification that just became unread is still reported as unread here), then mark
        // them seen for next time — opening the message center is the closest thing this app has
        // to a per-notification "open" action, since there's no dedicated notification screen.
        $notifications = Notification::where('user_id', $userId)
            ->latest('id')
            ->get()
            ->map(fn (Notification $notification) => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at?->toISOString(),
                'created_at' => $notification->created_at->toISOString(),
            ])->values();

        Notification::where('user_id', $userId)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json([
            'requirement_conversations' => $this->conversationsFor(BuyerRequirement::class, $userId),
            'deal_conversations' => $this->conversationsFor(Deal::class, $userId),
            'contract_conversations' => $this->conversationsFor(Contract::class, $userId),
            'system_notifications' => $notifications,
        ]);
    }

    private function conversationsFor(string $conversableClass, int $userId): array
    {
        $eagerLoad = match ($conversableClass) {
            BuyerRequirement::class => ['conversable.buyer'],
            Deal::class => ['conversable.negotiation.offer.match.buyerRequirement.buyer'],
            Contract::class => ['conversable.deal.negotiation.offer.match.buyerRequirement.buyer'],
            default => ['conversable'],
        };

        return Conversation::where('conversable_type', $conversableClass)
            ->whereHas('messages', fn ($query) => $query->where('sender_id', $userId))
            ->with($eagerLoad)
            ->withCount('messages')
            ->get()
            ->map(function (Conversation $conversation) use ($userId) {
                $unreadCount = $conversation->messages()
                    ->whereNull('read_at')
                    ->where('sender_id', '!=', $userId)
                    ->count();

                return [
                    'conversation_id' => $conversation->id,
                    'conversable_type' => class_basename($conversation->conversable_type),
                    'conversable_id' => $conversation->conversable_id,
                    'label' => $this->label($conversation),
                    'subject' => $conversation->subject,
                    'message_count' => $conversation->messages_count,
                    'unread_count' => $unreadCount,
                ];
            })
            ->values()
            ->all();
    }

    private function label(Conversation $conversation): string
    {
        $conversable = $conversation->conversable;

        return match (true) {
            $conversable instanceof BuyerRequirement => "Requirement #{$conversable->id} — {$this->buyerName($conversable->buyer)}",
            $conversable instanceof Deal => "Deal #{$conversable->id} — {$this->buyerNameForDeal($conversable)}",
            $conversable instanceof Contract => "Contract #{$conversable->id} — {$this->buyerNameForDeal($conversable->deal)}",
            default => "Conversation #{$conversation->id}",
        };
    }

    private function buyerNameForDeal(?Deal $deal): string
    {
        $buyer = $deal?->negotiation?->offer?->match?->buyerRequirement?->buyer;

        return $this->buyerName($buyer);
    }

    private function buyerName(?Buyer $buyer): string
    {
        return $buyer?->name ?? 'Unknown buyer';
    }
}
