<?php

namespace App\Console\Commands;

use App\Models\ScheduledEmail;
use App\Services\WebmailService;
use Illuminate\Console\Command;
use Throwable;

class SendScheduledEmails extends Command
{
    protected $signature = 'webmail:send-scheduled';

    protected $description = 'Send webmail messages whose scheduled time has come';

    private const MAX_ATTEMPTS = 3;

    public function handle(WebmailService $webmail): int
    {
        $masterPassword = config('mail_server.dovecot_master_password');

        if (blank($masterPassword)) {
            $this->warn('DOVECOT_MASTER_PASSWORD is not set, scheduled mail cannot be sent.');

            return self::FAILURE;
        }

        $authSuffix = '*'.config('mail_server.dovecot_master_user');
        $sent = 0;

        ScheduledEmail::query()
            ->where('status', ScheduledEmail::PENDING)
            ->where('send_at', '<=', now())
            ->orderBy('send_at')
            ->limit(50)
            ->get()
            ->each(function (ScheduledEmail $mail) use ($webmail, $masterPassword, $authSuffix, &$sent) {
                // Claim the row first so overlapping runs never double-send.
                $claimed = ScheduledEmail::whereKey($mail->id)
                    ->where('status', ScheduledEmail::PENDING)
                    ->update(['status' => ScheduledEmail::SENDING, 'attempts' => $mail->attempts + 1]);

                if (! $claimed) {
                    return;
                }

                try {
                    $webmail->send(
                        $mail->mailbox_email,
                        $masterPassword,
                        $mail->to,
                        $mail->subject,
                        $mail->body,
                        $mail->cc ?? [],
                        $mail->in_reply_to,
                        $mail->mailbox_email.$authSuffix,
                        $mail->attachments ?? [],
                        null,
                        $mail->bcc ?? [],
                    );

                    $mail->update(['status' => ScheduledEmail::SENT, 'sent_at' => now(), 'error' => null]);
                    $sent++;
                } catch (Throwable $e) {
                    $final = $mail->attempts + 1 >= self::MAX_ATTEMPTS;

                    $mail->update([
                        // Retry on the next tick until attempts run out.
                        'status' => $final ? ScheduledEmail::FAILED : ScheduledEmail::PENDING,
                        'error' => mb_substr($e->getMessage(), 0, 1000),
                    ]);
                }
            });

        $this->info("Sent {$sent} scheduled message(s).");

        return self::SUCCESS;
    }
}
