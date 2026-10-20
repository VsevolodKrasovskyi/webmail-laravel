<?php

namespace App\Observers;

use App\Models\Domain;
use App\Models\Mailbox;
use Illuminate\Support\Str;
use RuntimeException;

class MailboxObserver
{
    public function saving(Mailbox $mailbox): void
    {
        $domainName = Str::lower(Str::afterLast($mailbox->email, '@'));

        $domain = Domain::where('name', $domainName)->first();

        if (! $domain) {
            throw new RuntimeException("Domain {$domainName} was not found. Add the domain first.");
        }

        $mailbox->domain_id = $domain->id;
        $mailbox->email = Str::lower($mailbox->email);
    }
}
