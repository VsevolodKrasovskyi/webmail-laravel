<?php

namespace App\Policies;

use App\Models\Mailbox;
use App\Models\User;

class MailboxPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** Open in webmail, change name and password. */
    public function update(User $user, Mailbox $mailbox): bool
    {
        return $user->canAccessMailbox($mailbox);
    }

    /** Quota, active flag and delete: only for whoever manages the whole domain. */
    public function manage(User $user, Mailbox $mailbox): bool
    {
        return $user->canManageMailbox($mailbox);
    }

    public function delete(User $user, Mailbox $mailbox): bool
    {
        return $user->canManageMailbox($mailbox);
    }
}
