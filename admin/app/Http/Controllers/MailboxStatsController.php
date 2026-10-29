<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\WebmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Live message counts per mailbox for the dashboard.
 *
 * Counts come from Dovecot over IMAP, signed in with the master user so no
 * mailbox password is needed. Reading them takes one short IMAP session per
 * mailbox, so results are cached briefly and the dashboard loads them after
 * the page itself has rendered.
 */
class MailboxStatsController extends Controller
{
    private const CACHE_SECONDS = 60;

    /** Stop reading new mailboxes after this many seconds, the rest are reported as skipped. */
    private const TIME_BUDGET_SECONDS = 25;

    public function __invoke(Request $request, WebmailService $webmail): JsonResponse
    {
        $masterPassword = config('mail_server.dovecot_master_password');

        if (blank($masterPassword)) {
            return response()->json(['error' => 'Set DOVECOT_MASTER_PASSWORD to read message counts.'], 422);
        }

        $masterUser = config('mail_server.dovecot_master_user');
        $refresh = $request->boolean('refresh');
        $startedAt = microtime(true);

        $rows = Mailbox::with('domain:id,name')
            ->where('is_active', true)
            ->orderBy('email')
            ->get()
            ->map(function (Mailbox $mailbox) use ($webmail, $masterPassword, $masterUser, $refresh, $startedAt) {
                $key = "mailbox-stats:{$mailbox->email}";
                $row = ['id' => $mailbox->id, 'email' => $mailbox->email, 'name' => $mailbox->name, 'inbox' => null, 'unread' => null, 'total' => null, 'error' => null];

                if ($refresh) {
                    Cache::forget($key);
                }

                if (! Cache::has($key) && microtime(true) - $startedAt > self::TIME_BUDGET_SECONDS) {
                    return [...$row, 'error' => 'Skipped, too many mailboxes. Refresh to continue.'];
                }

                try {
                    $stats = Cache::remember($key, self::CACHE_SECONDS, fn () => $webmail->mailboxStats(
                        $mailbox->email, $masterPassword, $mailbox->email.'*'.$masterUser,
                    ));

                    return [...$row, ...$stats];
                } catch (Throwable) {
                    return [...$row, 'error' => 'Could not read this mailbox.'];
                }
            });

        return response()->json([
            'mailboxes' => $rows->values(),
            'unread_total' => (int) $rows->sum('unread'),
            'inbox_total' => (int) $rows->sum('inbox'),
            'messages_total' => (int) $rows->sum('total'),
        ]);
    }
}
