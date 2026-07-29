<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\ConversationRead;
use App\Models\Enrolled;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Support\Collection;

// Task 30: business logic for private 1:1 messaging — conversation lookup/creation,
// message writes, read-receipt bookkeeping, and the list/thread queries shared by the
// Inbox tab and the chat drawer. Authorization lives in ConversationPolicy /
// ConversationMessagePolicy; this class assumes the caller already authorized the action.
class ConversationService
{
    public function findOrCreateConversation(User $actor, User $other): Conversation
    {
        $studentId = $actor->hasRole('student') ? $actor->id : $other->id;
        $educatorId = $actor->hasRole('student') ? $other->id : $actor->id;

        return Conversation::firstOrCreate([
            'student_id' => $studentId,
            'educator_id' => $educatorId,
        ]);
    }

    /**
     * People this user is allowed to message: their active-enrollment counterparties (subject-agnostic).
     * Each row carries the user plus the subject ids they share with this user (for the educator's filter).
     */
    public function contactsFor(User $user): Collection
    {
        $isStudent = $user->hasRole('student');
        $mineColumn = $isStudent ? 'student_id' : 'educator_id';   // rows where this user is a party
        $otherColumn = $isStudent ? 'educator_id' : 'student_id';  // the counterparties to list

        $rows = Enrolled::where($mineColumn, $user->id)
            ->where('is_active', true)
            ->get([$otherColumn, 'subject_id']);

        $subjectsByContact = $rows->groupBy($otherColumn);

        return User::whereIn('id', $subjectsByContact->keys())
            ->orderBy('given_name')->orderBy('surname')
            ->get(['id', 'given_name', 'surname', 'profile_picture', 'user_id', 'user_type'])
            ->map(fn (User $u) => (object) [
                'user' => $u,
                'subjectIds' => $subjectsByContact->get($u->id)->pluck('subject_id')->unique()->values()->all(),
            ])
            ->values();
    }

    /** Subjects (with section) the educator teaches — powers the "New message" subject/section filter. */
    public function messagingSubjectsFor(User $user): Collection
    {
        if (! $user->hasRole('educator')) {
            return collect();
        }

        return Subject::where('educator_id', $user->id)
            ->with('section:id,section_name')
            ->orderBy('subject_code')
            ->get(['id', 'subject_code', 'subject_name', 'sections_id']);
    }

    // $kind tags a system-generated message (see ConversationMessage::ALERT_KINDS) so the thread
    // renders it as an alert rather than a chat bubble. NULL is an ordinary typed message.
    public function sendMessage(Conversation $conversation, User $sender, string $content, ?string $kind = null): ConversationMessage
    {
        return $conversation->messages()->create([
            'sender_user_id' => $sender->id,
            'content' => $content,
            'kind' => $kind,
        ]);
    }

    public function editMessage(ConversationMessage $message, string $content): ConversationMessage
    {
        $message->update(['content' => $content, 'edited_at' => now()]);

        return $message;
    }

    public function deleteMessage(ConversationMessage $message): ConversationMessage
    {
        $message->update(['content' => '', 'message_deleted_at' => now()]);

        return $message;
    }

    public function markRead(Conversation $conversation, int $userId): void
    {
        ConversationRead::updateOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $userId],
            ['last_read_at' => now()],
        );
    }

    /** Inbox tab + chat-drawer list: unread-first, then most recently active. */
    public function conversationListFor(User $user): Collection
    {
        // Eager-load everything the list needs so the map() below hits zero extra queries:
        // both participants, the newest message (latestMessage relation), and only THIS user's
        // read row. Unread counts are then one grouped query for all conversations (see below).
        $conversations = Conversation::forParticipant($user->id)
            ->with([
                'student:id,given_name,surname,profile_picture,user_type,user_id',
                'educator:id,given_name,surname,profile_picture,user_type,user_id',
                'latestMessage',
                'reads' => fn ($q) => $q->where('user_id', $user->id),
            ])
            ->get();

        $unreadByConversation = $this->unreadCountsByConversation($conversations->pluck('id'), $user->id);

        return $conversations->map(function (Conversation $conversation) use ($user, $unreadByConversation) {
            $lastMessage = $conversation->latestMessage;

            return (object) [
                'conversation' => $conversation,
                'other' => $conversation->otherParticipant($user->id),
                'lastMessage' => $lastMessage,
                'unreadCount' => (int) ($unreadByConversation[$conversation->id] ?? 0),
                'lastActivityAt' => $lastMessage->created_at ?? $conversation->created_at,
            ];
        })
            ->sortByDesc(fn ($row) => [$row->unreadCount > 0 ? 1 : 0, $row->lastActivityAt->timestamp])
            ->values();
    }

    /**
     * Unread counts for many conversations in one query: messages from the OTHER party that land
     * after this user's last_read_at (or all such messages if they've never opened the thread).
     * Keyed by conversation id; conversations with nothing unread are simply absent.
     */
    private function unreadCountsByConversation(Collection $conversationIds, int $userId): Collection
    {
        if ($conversationIds->isEmpty()) {
            return collect();
        }

        return ConversationMessage::query()
            ->leftJoin('tbl_conversation_reads', function ($join) use ($userId) {
                $join->on('tbl_conversation_reads.conversation_id', '=', 'tbl_conversation_messages.conversation_id')
                    ->where('tbl_conversation_reads.user_id', '=', $userId);
            })
            ->whereIn('tbl_conversation_messages.conversation_id', $conversationIds)
            ->where('tbl_conversation_messages.sender_user_id', '!=', $userId)
            ->where(fn ($q) => $q
                ->whereNull('tbl_conversation_reads.last_read_at')
                ->orWhereColumn('tbl_conversation_messages.created_at', '>', 'tbl_conversation_reads.last_read_at'))
            ->groupBy('tbl_conversation_messages.conversation_id')
            ->selectRaw('tbl_conversation_messages.conversation_id as cid, COUNT(*) as cnt')
            ->pluck('cnt', 'cid');
    }

    public function unreadCountFor(User $user): int
    {
        return $this->conversationListFor($user)->sum('unreadCount');
    }

    /** Active subject/section contexts shared by the educator and student in this 1:1 thread. */
    public function activeContextsFor(Conversation $conversation, User $viewer): Collection
    {
        if (! $viewer->hasRole('educator') || $viewer->id !== $conversation->educator_id) {
            return collect();
        }

        return Enrolled::where('educator_id', $conversation->educator_id)
            ->where('student_id', $conversation->student_id)
            ->where('is_active', true)
            ->with('subject:id,subject_code,subject_name,sections_id', 'subject.section:id,section_name')
            ->get()
            ->pluck('subject')
            ->filter()
            ->unique('id')
            ->sortBy('subject_code')
            ->values();
    }

    /** Ordered messages with the OTHER participant's last_read_at attached for read-receipt checks. */
    public function threadFor(Conversation $conversation, User $viewer): Collection
    {
        $otherId = $conversation->otherParticipant($viewer->id)->id;
        $otherRead = $conversation->reads()->where('user_id', $otherId)->first();

        return $conversation->messages()
            ->with('sender:id,given_name,surname,profile_picture')
            ->oldest('created_at')
            ->get()
            ->map(fn (ConversationMessage $message) => (object) [
                'message' => $message,
                'isRead' => $message->isReadBy($otherRead?->last_read_at),
            ]);
    }
}
