<?php

namespace App\Services;

use App\Models\Mailbox;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reads message counts for mailboxes from Dovecot over IMAP, signed in with the master
 * user so no mailbox password is needed. One short IMAP session per mailbox, results
 * are cached briefly per mailbox.
 */
class MailboxStatsReader
{
    private const CACHE_SECONDS = 60;

    /** Stop reading new mailboxes after this many seconds, the rest are reported as skipped. */
    private const TIME_BUDGET_SECONDS = 25;

    public function __construct(private readonly WebmailService $webmail) {}

    public function configured(): bool
    {
        return filled(config('mail_server.dovecot_master_password'));
    }

    /**
     * @param  Collection<int, Mailbox>  $mailboxes
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Collection $mailboxes, bool $refresh = false): Collection
    {
        $password = config('mail_server.dovecot_master_password');
        $masterUser = config('mail_server.dovecot_master_user');
        $startedAt = microtime(true);

        return $mailboxes->map(function (Mailbox $mailbox) use ($password, $masterUser, $refresh, $startedAt) {
            $key = "mailbox-stats:{$mailbox->email}";
            $row = ['id' => $mailbox->id, 'email' => $mailbox->email, 'name' => $mailbox->name, 'inbox' => null, 'unread' => null, 'total' => null, 'error' => null];

            if ($refresh) {
                Cache::forget($key);
            }

            if (! Cache::has($key) && microtime(true) - $startedAt > self::TIME_BUDGET_SECONDS) {
                return [...$row, 'error' => 'Skipped, too many mailboxes. Refresh to continue.'];
            }

            try {
                $stats = Cache::remember($key, self::CACHE_SECONDS, fn () => $this->webmail->mailboxStats(
                    $mailbox->email, $password, $mailbox->email.'*'.$masterUser,
                ));

                return [...$row, ...$stats];
            } catch (Throwable) {
                return [...$row, 'error' => 'Could not read this mailbox.'];
            }
        })->values();
    }
}
