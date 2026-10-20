<?php

namespace App\Models;

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
