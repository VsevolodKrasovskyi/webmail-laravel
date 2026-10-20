<?php

namespace App\Services;

use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email as MimeEmail;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\EncodingAliases;

class WebmailService
{
    /**
     * Folders every mailbox is expected to have, in display order. Only the
     * ones that actually exist are returned by listFolders() — the rest get
     * created lazily (Sent on send, Trash on delete) rather than up front.
     */
    private const STANDARD_FOLDERS = ['INBOX', 'Drafts', 'Sent', 'Junk', 'Trash'];

    /** Virtual folder: every message except Junk and Trash, like Gmail's "All Mail". */
    public const ALL_MAIL = '__ALL__';

    /** Virtual folder backed by the scheduled_emails table, not IMAP. */
    public const SCHEDULED = '__SCHEDULED__';

    /** Left out of "All mail". */
    private const EXCLUDED_FROM_ALL = ['Junk', 'Trash'];

    private const MAX_INLINE_IMAGE_BYTES = 3 * 1024 * 1024;

    private const MAX_INLINE_TOTAL_BYTES = 8 * 1024 * 1024;

    public function testLogin(string $email, string $password, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $client->disconnect();
    }

    /**
     * @return array<int, array{uid:int, folder:string, subject:string, from:string, to:string, date:?string, timestamp:int, seen:bool, thread_key:string}>
     */
    public function listMessages(string $email, string $password, string $folderPath = 'INBOX', int $limit = 50, ?string $authUser = null, ?string $search = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();

        $paths = $folderPath === self::ALL_MAIL
            ? array_values(array_filter(
                $client->getFolders()->map(fn ($f) => $f->path)->values()->all(),
                fn ($path) => ! in_array($path, self::EXCLUDED_FROM_ALL, true),
            ))
            : [$folderPath];

        $items = [];

        foreach ($paths as $path) {
            $folder = $client->getFolder($path);

            if (! $folder) {
                continue;
            }

            // Newest first, so the limit keeps the latest messages, not the oldest.
            $query = $folder->messages()->all()->setFetchOrderDesc();

            if (filled($search)) {
                $query = $query->text($search);
            }

            foreach ($query->limit($limit)->get() as $message) {
                $items[] = [
                    'uid' => $message->uid,
                    'folder' => $path,
                    'subject' => $this->decodeHeader((string) $message->subject) ?: '(no subject)',
                    'from' => $this->decodeHeader((string) $message->from),
                    'to' => $this->decodeHeader((string) $message->to),
                    'date' => $message->date?->toDate()?->format('d.m.Y H:i'),
                    'timestamp' => (int) $message->date?->toDate()?->getTimestamp(),
                    'seen' => $message->hasFlag('Seen'),
                    'thread_key' => (string) ($message->references->first() ?: ($message->in_reply_to->first() ?: $message->message_id)),
                ];
            }
        }

        $client->disconnect();

        usort($items, fn ($a, $b) => $b['timestamp'] <=> $a['timestamp']);

        return array_slice($items, 0, $limit);
    }

    /**
     * @return array<int, array{uid:int, folder:string, subject:string, from:string, to:string, date:?string, body:string, seen:bool, attachments:array}>
     */
    public function getThread(string $email, string $password, int $uid, string $folderPath = 'INBOX', ?string $authUser = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();

        $folder = $client->getFolder($folderPath);
        $message = $folder->query()->getMessageByUid($uid);
        $sentFolder = $client->getFolder('Sent') ?: $folder;

        $seed = null;
        $thread = $message->thread($sentFolder, $seed, $folder);
        $message->setFlag('Seen');

        $result = $thread
            ->sortBy(fn ($m) => $m->date?->toDate())
            ->map(function ($m) {
                [$body, $inlineIds] = $this->renderBody($m);

                return [
                    'uid' => $m->uid,
                    'folder' => $m->getFolder()?->path,
                    'subject' => $this->decodeHeader((string) $m->subject) ?: '(no subject)',
                    'from' => $this->decodeHeader((string) $m->from),
                    'to' => $this->decodeHeader((string) $m->to),
                    'date' => $m->date?->toDate()?->format('d.m.Y H:i'),
                    'body' => $body,
                    'message_id' => (string) $m->message_id,
                    'seen' => $m->hasFlag('Seen'),
                    // Images already shown inside the body are not listed again.
                    'attachments' => $m->getAttachments()
                        ->reject(fn ($a) => in_array($a->id, $inlineIds, true))
                        ->map(fn ($a) => [
                            'id' => $a->id,
                            'name' => $this->decodeHeader((string) ($a->name ?: $a->filename ?: 'attachment')),
                            'size' => $a->size,
                            'content_type' => $a->content_type,
                        ])->values()->all(),
                ];
            })
            ->values()
            ->all();

        $client->disconnect();

        return $result;
    }

    /**
     * Loads one message for editing as a draft.
     *
     * @return array{to:string, cc:array<int,string>, bcc:array<int,string>, subject:string, body:string, in_reply_to:?string, draft_id:string}
     */
    public function getDraft(string $email, string $password, int $uid, ?string $authUser = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $message = $client->getFolder('Drafts')->query()->getMessageByUid($uid);

        $addresses = fn ($attr) => collect($attr->all())->map(fn ($a) => $a->mail)->filter()->values()->all();

        $result = [
            'to' => implode(', ', $addresses($message->to)),
            'cc' => $addresses($message->cc),
            'bcc' => $addresses($message->bcc),
            'subject' => $this->decodeHeader((string) $message->subject),
            'body' => $message->hasHTMLBody() ? $message->getHTMLBody() : nl2br(e($message->getTextBody())),
            'in_reply_to' => ((string) $message->in_reply_to->first()) ?: null,
            'draft_id' => trim((string) $message->message_id, '<>'),
        ];

        $client->disconnect();

        return $result;
    }

    /**
     * Saves (or replaces) a draft in the Drafts folder. A draft is identified
     * by its Message-ID, which stays the same across saves, so the caller
     * never needs to track IMAP UIDs.
     *
     * @param  array<int, string>  $cc
     * @return string The draft's id, to pass back on the next save.
     */
    public function saveDraft(
        string $email,
        string $password,
        ?string $to,
        string $subject,
        string $body,
        array $cc = [],
        ?string $inReplyTo = null,
        ?string $draftId = null,
        ?string $authUser = null,
        array $bcc = [],
    ): string {
        $draftId = $draftId ?: bin2hex(random_bytes(12)).'@'.(substr(strrchr($email, '@'), 1) ?: 'localhost');

        $mime = (new MimeEmail())->from($email)->subject($subject)->html($body);

        if (filled($to)) {
            $mime->to($to);
        }

        if (! empty($cc)) {
            $mime->cc(...$cc);
        }

        if (! empty($bcc)) {
            $mime->bcc(...$bcc);
        }

        $mime->getHeaders()->addIdHeader('Message-ID', $draftId);

        if (filled($inReplyTo)) {
            $mime->getHeaders()->addIdHeader('In-Reply-To', trim($inReplyTo, '<>'));
        }

        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();

        $drafts = $client->getFolder('Drafts');

        if (! $drafts) {
            $client->createFolder('Drafts', false);
            $drafts = $client->getFolder('Drafts');
        }

        $this->expungeByMessageId($drafts, $draftId);
        $drafts->appendMessage($mime->toString(), ['\\Draft', '\\Seen']);
        $client->disconnect();

        return $draftId;
    }

    public function deleteDraft(string $email, string $password, string $draftId, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();

        if ($drafts = $client->getFolder('Drafts')) {
            $this->expungeByMessageId($drafts, $draftId);
        }

        $client->disconnect();
    }

    private function expungeByMessageId($folder, string $messageId): void
    {
        $folder->messages()->all()->whereMessageId($messageId)->get()
            ->each(fn ($old) => $old->delete(true));
    }

    /**
     * Returns the HTML for display with `cid:` image references (Gmail and
     * Outlook inline pictures) inlined as data URIs, plus the attachment ids
     * that were consumed that way.
     *
     * @return array{0:string, 1:array<int,string>}
     */
    private function renderBody($message): array
    {
        if (! $message->hasHTMLBody()) {
            return [nl2br(e($message->getTextBody())), []];
        }

        $html = $message->getHTMLBody();
        $consumed = [];

        if (stripos($html, 'cid:') === false) {
            return [$html, $consumed];
        }

        $budget = self::MAX_INLINE_TOTAL_BYTES;

        foreach ($message->getAttachments() as $attachment) {
            $id = (string) $attachment->id;
            $type = (string) $attachment->content_type;

            if ($id === '' || ! str_starts_with(strtolower($type), 'image/')) {
                continue;
            }

            $needles = array_unique(['cid:'.$id, 'cid:'.rawurlencode($id)]);

            if (! array_filter($needles, fn ($n) => stripos($html, $n) !== false)) {
                continue;
            }

            $consumed[] = $id;
            $content = (string) $attachment->content;
            $size = strlen($content);

            if ($size === 0 || $size > self::MAX_INLINE_IMAGE_BYTES || $size > $budget) {
                continue;
            }

            $budget -= $size;
            $html = str_ireplace($needles, 'data:'.$type.';base64,'.base64_encode($content), $html);
        }

        return [$html, $consumed];
    }

    /**
     * Subjects and names sometimes arrive still MIME-encoded
     * (`=?UTF-8?B?...?=`) when the library could not decode them.
     */
    public function decodeHeader(?string $value): string
    {
        $value = trim((string) $value);

        for ($i = 0; $i < 2 && str_contains($value, '=?'); $i++) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

            if ($decoded === false || $decoded === $value) {
                $decoded = @mb_decode_mimeheader($value);
            }

            if (! is_string($decoded) || $decoded === '' || $decoded === $value) {
                break;
            }

            $value = $decoded;
        }

        return $value;
    }

    /**
     * @return array{name:string, contentType:string, content:string}|null  $content is raw binary
     */
    public function getAttachment(string $email, string $password, int $uid, string $attachmentId, string $folderPath = 'INBOX', ?string $authUser = null): ?array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($folderPath);
        $message = $folder->query()->getMessageByUid($uid);
        $attachment = $message->getAttachments()->first(fn ($a) => $a->id === $attachmentId);
        $client->disconnect();

        if (! $attachment) {
            return null;
        }

        return [
            'name' => $this->decodeHeader((string) ($attachment->name ?: $attachment->filename ?: 'attachment')),
            'contentType' => $attachment->content_type ?: 'application/octet-stream',
            'content' => $attachment->content,
        ];
    }

    /**
     * @return array{uid:int, subject:string, from:string, date:?string, body:string, message_id:string}
     */
    public function getMessage(string $email, string $password, int $uid, string $folderPath = 'INBOX', ?string $authUser = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($folderPath);
        $message = $folder->query()->getMessageByUid($uid);

        $result = [
            'uid' => $uid,
            'subject' => $this->decodeHeader((string) $message->subject),
            'from' => $this->decodeHeader((string) $message->from),
            'date' => $message->date?->toDate()?->format('d.m.Y H:i'),
            'body' => $message->hasHTMLBody()
                ? $message->getHTMLBody()
                : nl2br(e($message->getTextBody())),
            'message_id' => (string) $message->message_id,
        ];

        $message->setFlag('Seen');
        $client->disconnect();

        return $result;
    }

    /**
     * Moves the message to Trash, unless it's already there (or in Junk),
     * in which case it's expunged for good — matching normal mail clients.
     */
    public function deleteMessage(string $email, string $password, int $uid, string $folderPath = 'INBOX', ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($folderPath);
        $message = $folder->query()->getMessageByUid($uid);

        if (in_array($folderPath, ['Trash', 'Junk'], true)) {
            $message->delete();
        } else {
            if (! $client->getFolder('Trash')) {
                $client->createFolder('Trash', false);
            }

            $message->move('Trash', true);
        }

        $client->disconnect();
    }

    /**
     * Folder "names" throughout this class and its callers are always plain
     * UTF-8 (e.g. what the user typed) — webklex/php-imap takes care of the
     * modified-UTF-7 wire encoding internally for every lookup. Only decode
     * for display when reading raw paths straight off the IMAP connection,
     * as done here.
     *
     * @return array<int, array{name:string, label:string, standard:bool}>
     */
    public function listFolders(string $email, string $password, ?string $authUser = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $existing = $client->getFolders()->map(fn ($folder) => $folder->path)->values()->all();

        foreach (self::STANDARD_FOLDERS as $name) {
            if ($name !== 'INBOX' && ! in_array($name, $existing, true)) {
                $client->createFolder($name, false);
                $existing[] = $name;
            }
        }

        $client->disconnect();

        $standard = array_values(array_intersect(self::STANDARD_FOLDERS, $existing));
        $extra = array_values(array_diff($existing, self::STANDARD_FOLDERS));
        $ordered = [...$standard, ...$extra];

        $folders = array_map(function ($path) {
            $name = in_array($path, self::STANDARD_FOLDERS, true)
                ? $path
                : (string) EncodingAliases::convert($path, 'UTF7-IMAP', 'UTF-8');

            return [
                'name' => $name,
                'label' => $name === 'INBOX' ? 'Inbox' : $name,
                'standard' => in_array($path, self::STANDARD_FOLDERS, true),
            ];
        }, $ordered);

        // Virtual folders: All mail goes first, Scheduled right after Drafts.
        $insertAfter = function (array $list, string $anchor, array $item) {
            $at = array_search($anchor, array_column($list, 'name'), true);
            array_splice($list, $at === false ? count($list) : $at + 1, 0, [$item]);

            return $list;
        };

        $folders = $insertAfter($folders, 'Drafts', ['name' => self::SCHEDULED, 'label' => 'Scheduled', 'standard' => true, 'virtual' => true]);

        return [['name' => self::ALL_MAIL, 'label' => 'All mail', 'standard' => true, 'virtual' => true], ...$folders];
    }

    public function createFolder(string $email, string $password, string $name, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $client->createFolder($name, false);
        $client->disconnect();
    }

    public function deleteFolder(string $email, string $password, string $path, ?string $authUser = null): void
    {
        if (in_array($path, self::STANDARD_FOLDERS, true)) {
            throw new \RuntimeException('Standard folders cannot be deleted.');
        }

        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $client->getFolder($path)?->delete(false);
        $client->disconnect();
    }

    public function emptyFolder(string $email, string $password, string $path, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($path);
        $folder->messages()->all()->get()->each(fn ($message) => $message->delete(false));
        $client->expunge();
        $client->disconnect();
    }

    public function moveMessage(string $email, string $password, int $uid, string $fromFolder, string $toFolder, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($fromFolder);
        $message = $folder->query()->getMessageByUid($uid);

        if (! $client->getFolder($toFolder)) {
            $client->createFolder($toFolder, false);
        }

        $message->move($toFolder, true);
        $client->disconnect();
    }

    public function markUnread(string $email, string $password, int $uid, string $folderPath = 'INBOX', ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($folderPath);
        $message = $folder->query()->getMessageByUid($uid);
        $message->unsetFlag('Seen');
        $client->disconnect();
    }

    /**
     * @param  array<int, string>  $cc
     * @param  array<int, array{name:string, contentType:string, content:string}>  $attachments  $content is base64-encoded
     */
    public function send(
        string $email,
        string $password,
        string $to,
        string $subject,
        string $body,
        array $cc = [],
        ?string $inReplyTo = null,
        ?string $authUser = null,
        array $attachments = [],
        ?string $draftId = null,
        array $bcc = [],
    ): void {
        $transport = new EsmtpTransport(config('mail_server.smtp_host'), 465, true);
        $transport->setUsername($authUser ?? $email);
        $transport->setPassword($password);

        $stream = $transport->getStream();
        $options = $stream->getStreamOptions();
        $options['ssl']['verify_peer'] = false;
        $options['ssl']['verify_peer_name'] = false;
        $options['ssl']['allow_self_signed'] = true;
        $stream->setStreamOptions($options);

        $mime = (new MimeEmail())
            ->from($email)
            ->to($to)
            ->subject($subject)
            ->html($body)
            ->text(trim(strip_tags(preg_replace('/<(br|\/p|\/div)\b[^>]*>/i', "\n", $body))));

        if (! empty($cc)) {
            $mime->cc(...$cc);
        }

        if (! empty($bcc)) {
            $mime->bcc(...$bcc);
        }

        if (filled($inReplyTo)) {
            $mime->getHeaders()
                ->addIdHeader('In-Reply-To', $inReplyTo)
                ->addIdHeader('References', $inReplyTo);
        }

        foreach ($attachments as $attachment) {
            $mime->attach(base64_decode($attachment['content']), $attachment['name'], $attachment['contentType']);
        }

        $transport->send($mime);

        $this->appendToSent($email, $password, $mime->toString(), $authUser);

        if (filled($draftId)) {
            $this->deleteDraft($email, $password, $draftId, $authUser);
        }
    }

    private function appendToSent(string $email, string $password, string $raw, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();

        $sent = $client->getFolder('Sent');

        if (! $sent) {
            $client->createFolder('Sent', false);
            $sent = $client->getFolder('Sent');
        }

        $sent?->appendMessage($raw, ['\\Seen']);
        $client->disconnect();
    }

    private function imapClient(string $email, string $password, ?string $authUser = null): ImapClient
    {
        $manager = new ClientManager();

        return $manager->make([
            'host' => config('mail_server.imap_host'),
            'port' => 993,
            'encryption' => 'ssl',
            'validate_cert' => false,
            'username' => $authUser ?? $email,
            'password' => $password,
            'protocol' => 'imap',
        ]);
    }
}
