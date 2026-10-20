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
    private const STANDARD_FOLDERS = ['INBOX', 'Sent', 'Junk', 'Trash'];

    public function testLogin(string $email, string $password, ?string $authUser = null): void
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $client->disconnect();
    }

    /**
     * @return array<int, array{uid:int, subject:string, from:string, date:?string, seen:bool, thread_key:string}>
     */
    public function listMessages(string $email, string $password, string $folderPath = 'INBOX', int $limit = 50, ?string $authUser = null, ?string $search = null): array
    {
        $client = $this->imapClient($email, $password, $authUser);
        $client->connect();
        $folder = $client->getFolder($folderPath);
        $query = $folder->messages()->all();

        if (filled($search)) {
            $query = $query->text($search);
        }

        $collection = $query->limit($limit)->get();
        $client->disconnect();

        return $collection->reverse()->map(fn ($message) => [
            'uid' => $message->uid,
            'subject' => (string) $message->subject ?: '(no subject)',
            'from' => (string) $message->from,
            'to' => (string) $message->to,
            'date' => $message->date?->toDate()?->format('d.m.Y H:i'),
            'seen' => $message->hasFlag('Seen'),
            'thread_key' => (string) ($message->references->first() ?: ($message->in_reply_to->first() ?: $message->message_id)),
        ])->values()->all();
    }

    /**
     * @return array<int, array{uid:int, folder:string, subject:string, from:string, date:?string, body:string, seen:bool}>
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
            ->map(fn ($m) => [
                'uid' => $m->uid,
                'folder' => $m->getFolder()?->name,
                'subject' => (string) $m->subject ?: '(no subject)',
                'from' => (string) $m->from,
                'to' => (string) $m->to,
                'date' => $m->date?->toDate()?->format('d.m.Y H:i'),
                'body' => $m->hasHTMLBody() ? $m->getHTMLBody() : nl2br(e($m->getTextBody())),
                'message_id' => (string) $m->message_id,
                'seen' => $m->hasFlag('Seen'),
            ])
            ->values()
            ->all();

        $client->disconnect();

        return $result;
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
            'subject' => (string) $message->subject,
            'from' => (string) $message->from,
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

        return array_map(function ($path) {
            $name = in_array($path, self::STANDARD_FOLDERS, true)
                ? $path
                : (string) EncodingAliases::convert($path, 'UTF7-IMAP', 'UTF-8');

            return [
                'name' => $name,
                'label' => $name === 'INBOX' ? 'Inbox' : $name,
                'standard' => in_array($path, self::STANDARD_FOLDERS, true),
            ];
        }, $ordered);
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
            ->text($body);

        if (! empty($cc)) {
            $mime->cc(...$cc);
        }

        if (filled($inReplyTo)) {
            $mime->getHeaders()
                ->addIdHeader('In-Reply-To', $inReplyTo)
                ->addIdHeader('References', $inReplyTo);
        }

        $transport->send($mime);

        $this->appendToSent($email, $password, $mime->toString(), $authUser);
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
