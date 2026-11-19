<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScheduledEmail extends Model
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    protected $fillable = [
        'mailbox_email', 'to', 'cc', 'bcc', 'subject', 'body', 'in_reply_to', 'attachments',
        'send_at', 'status', 'attempts', 'error', 'sent_at',
    ];

    /** Scheduled mail of the mailboxes the user can see. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->whereIn('mailbox_email', Mailbox::visibleTo($user)->select('email'));
    }

    protected function casts(): array
    {
        return [
            'cc' => 'array',
            'bcc' => 'array',
            'attachments' => 'array',
            'send_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }
}
