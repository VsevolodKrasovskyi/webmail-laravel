<?php

namespace App\Policies;

use App\Models\Domain;
use App\Models\User;

class DomainPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    /** Only administrators add domains, a domain manager works on the domains given to them. */
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Domain $domain): bool
    {
        return $user->canManageDomain($domain->id);
    }

    public function delete(User $user, Domain $domain): bool
    {
        return $user->canManageDomain($domain->id);
    }
}
