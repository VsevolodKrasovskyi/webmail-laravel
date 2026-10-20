<?php

namespace App\Http\Controllers;

use App\Models\Mailbox;
use App\Services\WebmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class WebmailController extends Controller
{
    public function __construct(private readonly WebmailService $webmail) {}

    public function index(Request $request): Response
    {
        $credentials = $this->credentials($request);

        return Inertia::render('Webmail', [
            'connected' => (bool) $credentials,
            'email' => $credentials['email'] ?? null,
        ]);
    }

    public function compose(Request $request): Response|RedirectResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return redirect('/webmail');
        }

        $to = (string) $request->query('to', '');
        $subject = (string) $request->query('subject', '');
        $body = '';
        $inReplyTo = null;

        $quoteUid = $request->query('quote_uid');
        $quoteFolder = (string) $request->query('quote_folder', 'INBOX');

        if ($quoteUid) {
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
            'inReplyTo' => $inReplyTo,
        ]);
    }

    private function extractEmailAddress(string $address): string
    {
        return preg_match('/<([^>]+)>/', $address, $m) ? $m[1] : $address;
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        try {
            $this->webmail->testLogin($data['email'], $data['password']);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'password' => 'Could not connect: wrong credentials or the mailbox is unavailable.',
            ]);
        }

        $request->session()->put('webmail_credentials', encrypt($data));

        return back();
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

        try {
            return response()->json([
                'messages' => $this->webmail->listMessages(
                    $credentials['email'], $credentials['password'], $folder, 50, $credentials['auth_user'] ?? null, $search,
                ),
            ]);
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
        ]);
    }

    public function destroyMessage(Request $request, int $uid): JsonResponse
    {
        $credentials = $this->credentials($request);

        if (! $credentials) {
            return response()->json(['error' => 'not_connected'], 401);
        }

        $folder = $request->query('folder', 'INBOX');

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
            'in_reply_to' => ['nullable', 'string', 'max:255'],
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
            );

            return response()->json(['ok' => true]);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
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
