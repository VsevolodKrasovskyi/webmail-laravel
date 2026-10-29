<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use App\Services\WebmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WebmailController extends Controller
{
    public function __construct(private readonly WebmailService $webmail) {}

    /**
     * The mail client itself. There is no page for choosing or signing in to a
     * mailbox: it is opened with the "Open" button in the Mailboxes list (Dovecot
     * master-user login), so without an open mailbox this goes back to that list.
     */
    public function index(Request $request): Response|RedirectResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return redirect('/mailboxes')->with('error', 'Open a mailbox with the Open button in the list.');
        }

        return Inertia::render('Webmail', [
            'email' => $credentials['email'],
        ]);
    }

    public function compose(Request $request): Response|RedirectResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return redirect('/mailboxes');
        }

        $to = (string) $request->query('to', '');
        $subject = (string) $request->query('subject', '');
        $body = '';
        $inReplyTo = null;
        $draftId = null;
        $cc = [];
        $bcc = [];

        if ($draftUid = $request->query('draft_uid')) {
            try {
                $draft = $this->webmail->getDraft(
                    $credentials['email'], $credentials['password'], (int) $draftUid, $credentials['auth_user'] ?? null,
                );
                $to = $draft['to'];
                $cc = $draft['cc'];
                $bcc = $draft['bcc'];
                $subject = $draft['subject'];
                $body = $draft['body'];
                $inReplyTo = $draft['in_reply_to'];
                $draftId = $draft['draft_id'];
            } catch (Throwable) {
                // Fall back to a blank compose rather than blocking the page.
            }
        }

        $quoteUid = $request->query('quote_uid');
        $quoteFolder = (string) $request->query('quote_folder', 'INBOX');

        if ($quoteUid && ! $draftId) {
            try {
                $thread = $this->webmail->getThread(
                    $credentials['email'], $credentials['password'], (int) $quoteUid, $quoteFolder, $credentials['auth_user'] ?? null,
                );
                $last = end($thread);

                if ($last) {
                    $to = $this->extractEmailAddress($last['from']);
                    $subject = preg_match('/^re:/i', $last['subject']) ? $last['subject'] : "Re: {$last['subject']}";
                    $body = '<p></p><blockquote>On '.e($last['date']).', '.e($last['from']).' wrote:<br>'.$last['body'].'</blockquote>';
                    $inReplyTo = $last['message_id'];
                }
            } catch (Throwable) {
                // Fall back to a blank compose rather than blocking the page.
            }
        }

        return Inertia::render('Webmail/Compose', [
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'cc' => $cc,
            'bcc' => $bcc,
            'inReplyTo' => $inReplyTo,
            'draftId' => $draftId,
            'canSchedule' => filled(config('mail_server.dovecot_master_password')),
        ]);
    }

    private function extractEmailAddress(string $address): string
    {
        return preg_match('/<([^>]+)>/', $address, $m) ? $m[1] : $address;
    }

    /**
     * Admin shortcut: open a mailbox's webmail without knowing its real
     * password, via Dovecot's master-user auth (see docker/dovecot/10-auth.conf).
     */
    public function loginAsMailbox(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $masterPassword = config('mail_server.dovecot_master_password');

        if (blank($masterPassword)) {
            return back()->with('error', 'Set DOVECOT_MASTER_PASSWORD to enable opening webmail from the mailbox list.');
        }

        $authUser = $mailbox->email.'*'.config('mail_server.dovecot_master_user');

        try {
            $this->webmail->testLogin($mailbox->email, $masterPassword, $authUser);
        } catch (Throwable) {
            return back()->with('error', "Could not open webmail for {$mailbox->email}.");
        }

        $request->session()->put('webmail_credentials', encrypt([
            'email' => $mailbox->email,
            'password' => $masterPassword,
            'auth_user' => $authUser,
        ]));

        return redirect('/webmail');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('webmail_credentials');

        return back();
    }

    public function messages(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $folder = $request->query('folder', 'INBOX');
        $search = $request->query('search');

        if ($folder === WebmailService::SCHEDULED) {
            return response()->json(['messages' => $this->scheduledItems($credentials['email'], $search)]);
        }

        try {
            $messages = $this->webmail->listMessages(
                $credentials['email'], $credentials['password'], $folder, 50, $credentials['auth_user'] ?? null, $search,
            );

            // "All mail" is everything except Junk and Trash: every IMAP folder plus the queued
            // (scheduled) messages, which live in the database and not in IMAP.
            if ($folder === WebmailService::ALL_MAIL) {
                $messages = [...$messages, ...$this->scheduledItems($credentials['email'], $search)];
                usort($messages, fn ($a, $b) => ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0));
                $messages = array_slice($messages, 0, 50);
            }

            return response()->json(['messages' => $messages]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function show(Request $request, int $uid): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $folder = $request->query('folder', 'INBOX');

        if ($folder === WebmailService::SCHEDULED) {
            $mail = $this->ownScheduled($credentials['email'], $uid);

            return response()->json(['messages' => [$this->scheduledDetail($mail)]]);
        }

        try {
            return response()->json([
                'messages' => $this->webmail->getThread(
                    $credentials['email'], $credentials['password'], $uid, $folder, $credentials['auth_user'] ?? null,
                ),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function downloadAttachment(Request $request, int $uid, string $attachmentId)
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            abort(401);
        }

        $folder = $request->query('folder', 'INBOX');

        $attachment = $this->webmail->getAttachment(
            $credentials['email'], $credentials['password'], $uid, $attachmentId, $folder, $credentials['auth_user'] ?? null,
        );

        abort_if(! $attachment, 404);

        return response($attachment['content'], 200, [
            'Content-Type' => $attachment['contentType'],
            'Content-Disposition' => 'attachment; filename="'.addslashes($attachment['name']).'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroyMessage(Request $request, int $uid): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $folder = $request->query('folder', 'INBOX');

        if ($folder === WebmailService::SCHEDULED) {
            $this->ownScheduled($credentials['email'], $uid)->delete();

            return response()->json(['ok' => true]);
        }

        try {
            $this->webmail->deleteMessage(
                $credentials['email'], $credentials['password'], $uid, $folder, $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function markUnread(Request $request, int $uid): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $folder = $request->query('folder', 'INBOX');

        try {
            $this->webmail->markUnread(
                $credentials['email'], $credentials['password'], $uid, $folder, $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function folders(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        try {
            return response()->json([
                'folders' => $this->webmail->listFolders(
                    $credentials['email'], $credentials['password'], $credentials['auth_user'] ?? null,
                ),
            ]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function storeFolder(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        try {
            $this->webmail->createFolder(
                $credentials['email'], $credentials['password'], $data['name'], $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function destroyFolder(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate(['path' => ['required', 'string']]);

        try {
            $this->webmail->deleteFolder(
                $credentials['email'], $credentials['password'], $data['path'], $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function emptyFolder(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate(['path' => ['required', 'string']]);

        try {
            $this->webmail->emptyFolder(
                $credentials['email'], $credentials['password'], $data['path'], $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function moveMessage(Request $request, int $uid): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate([
            'from' => ['required', 'string'],
            'to' => ['required', 'string'],
        ]);

        try {
            $this->webmail->moveMessage(
                $credentials['email'], $credentials['password'], $uid, $data['from'], $data['to'], $credentials['auth_user'] ?? null,
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'in_reply_to' => ['nullable', 'string', 'max:255'],
            'draft_id' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.contentType' => ['required', 'string', 'max:255'],
            'attachments.*.content' => ['required', 'string'],
        ]);

        try {
            $this->webmail->send(
                $credentials['email'],
                $credentials['password'],
                $data['to'],
                $data['subject'],
                $data['body'],
                $data['cc'] ?? [],
                $data['in_reply_to'] ?? null,
                $credentials['auth_user'] ?? null,
                $data['attachments'] ?? [],
                $data['draft_id'] ?? null,
                $data['bcc'] ?? [],
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'in_reply_to' => ['nullable', 'string', 'max:255'],
            'draft_id' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $draftId = $this->webmail->saveDraft(
                $credentials['email'],
                $credentials['password'],
                $data['to'] ?? null,
                $data['subject'] ?? '',
                $data['body'] ?? '',
                $data['cc'] ?? [],
                $data['in_reply_to'] ?? null,
                $data['draft_id'] ?? null,
                $credentials['auth_user'] ?? null,
                $data['bcc'] ?? [],
            );

            return response()->json(['draft_id' => $draftId]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Queues a message in the scheduled_emails table; `php artisan
     * schedule:run` (cron) sends it once send_at has passed.
     */
    public function schedule(Request $request): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        if (blank(config('mail_server.dovecot_master_password'))) {
            return response()->json(['error' => 'Set DOVECOT_MASTER_PASSWORD to enable scheduled sending.'], 422);
        }

        $data = $request->validate([
            'to' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'cc' => ['nullable', 'array'],
            'cc.*' => ['email'],
            'bcc' => ['nullable', 'array'],
            'bcc.*' => ['email'],
            'in_reply_to' => ['nullable', 'string', 'max:255'],
            'draft_id' => ['nullable', 'string', 'max:255'],
            'send_at' => ['required', 'date', 'after:now'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*.name' => ['required', 'string', 'max:255'],
            'attachments.*.contentType' => ['required', 'string', 'max:255'],
            'attachments.*.content' => ['required', 'string'],
        ]);

        $mail = ScheduledEmail::create([
            'mailbox_email' => $credentials['email'],
            'to' => $data['to'],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'],
            'body' => $data['body'],
            'in_reply_to' => $data['in_reply_to'] ?? null,
            'attachments' => $data['attachments'] ?? [],
            // The browser sends UTC. Convert to the app time zone, because that is how the column is
            // read back and compared with now() (storing the UTC clock time would send hours early).
            'send_at' => Carbon::parse($data['send_at'])->setTimezone(config('app.timezone')),
            'status' => ScheduledEmail::PENDING,
        ]);

        if (filled($data['draft_id'] ?? null)) {
            try {
                $this->webmail->deleteDraft(
                    $credentials['email'], $credentials['password'], $data['draft_id'], $credentials['auth_user'] ?? null,
                );
            } catch (Throwable) {
                // The schedule is saved; a leftover draft is harmless.
            }
        }

        return response()->json(['ok' => true, 'id' => $mail->id]);
    }

    private function ownScheduled(string $email, int $id): ScheduledEmail
    {
        return ScheduledEmail::where('mailbox_email', $email)->findOrFail($id);
    }

    /**
     * Scheduled rows shaped like IMAP list items so the inbox UI can show them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scheduledItems(string $email, ?string $search): array
    {
        return ScheduledEmail::where('mailbox_email', $email)
            ->whereIn('status', [ScheduledEmail::PENDING, ScheduledEmail::SENDING, ScheduledEmail::FAILED])
            ->when(filled($search), fn ($q) => $q->where(fn ($q) => $q
                ->where('subject', 'ilike', "%{$search}%")
                ->orWhere('to', 'ilike', "%{$search}%")))
            ->orderBy('send_at')
            ->limit(100)
            ->get()
            ->map(fn (ScheduledEmail $m) => [
                'uid' => $m->id,
                'folder' => WebmailService::SCHEDULED,
                'subject' => $m->subject,
                'from' => $m->mailbox_email,
                'to' => $m->to,
                'date' => null,
                'send_at' => $m->send_at->toIso8601String(),
                'timestamp' => $m->send_at->getTimestamp(),
                'status' => $m->status,
                'seen' => true,
                'thread_key' => 'scheduled:'.$m->id,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledDetail(ScheduledEmail $m): array
    {
        return [
            'uid' => $m->id,
            'folder' => WebmailService::SCHEDULED,
            'subject' => $m->subject,
            'from' => $m->mailbox_email,
            'to' => $m->to,
            'date' => null,
            'send_at' => $m->send_at->toIso8601String(),
            'status' => $m->status,
            'error' => $m->error,
            'body' => $m->body,
            'seen' => true,
            'attachments' => [],
        ];
    }

    /**
     * @return array{email:string,password:string,auth_user?:string}|null
     */
    private function credentials(Request $request): ?array
    {
        $encrypted = $request->session()->get('webmail_credentials');

        if (blank($encrypted)) {
            return null;
        }

        try {
            return decrypt($encrypted);
        } catch (Throwable) {
            $request->session()->forget('webmail_credentials');

            return null;
        }
    }
}
