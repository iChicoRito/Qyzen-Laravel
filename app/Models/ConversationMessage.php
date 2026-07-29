<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ConversationMessage extends Model
{
    protected $table = 'tbl_conversation_messages';

    protected $fillable = ['conversation_id', 'sender_user_id', 'content', 'kind', 'edited_at', 'message_deleted_at'];

    /**
     * Task 30: kind => [alert variant, keenicon, heading] for system messages rendered as alerts.
     * Variants are fixed by the spec — exemption is destructive (something was taken away),
     * special access is success (something was granted).
     */
    public const ALERT_KINDS = [
        'assessment_exempted' => ['destructive', 'shield-cross', 'Assessment exemption'],
        'assessment_access_granted' => ['success', 'shield-tick', 'Special access granted'],
    ];

    protected $casts = [
        'edited_at' => 'datetime',
        'message_deleted_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function isEdited(): bool
    {
        return $this->edited_at !== null;
    }

    public function isDeleted(): bool
    {
        return $this->message_deleted_at !== null;
    }

    /** A system alert is not editable or deletable by the educator who triggered it. */
    public function isAlert(): bool
    {
        return ! $this->isDeleted() && isset(self::ALERT_KINDS[$this->kind]);
    }

    /** @return array{0: string, 1: string, 2: string} variant, icon, heading */
    public function alertStyle(): array
    {
        return self::ALERT_KINDS[$this->kind] ?? ['light', 'information-2', 'Notice'];
    }

    /** What renders in the thread — never the stale content once deleted. */
    public function displayContent(): string
    {
        return $this->isDeleted() ? 'This message was deleted' : $this->content;
    }

    /** Read receipt: has the OTHER participant read past this message's timestamp? */
    public function isReadBy(?Carbon $otherLastReadAt): bool
    {
        return $otherLastReadAt !== null && $otherLastReadAt->gte($this->created_at);
    }
}
