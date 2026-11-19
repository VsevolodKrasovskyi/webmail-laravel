<?php

namespace App\Policies;

use App\Models\Alias;
use App\Models\User;

class AliasPolicy
{
    public function before(User $user): ?bool
    {
        return $user->isAdmin() ? true : null;
    }

    public function update(User $user, Alias $alias): bool
    {
        return $user->canManageDomain($alias->domain_id);
    }

    public function delete(User $user, Alias $alias): bool
    {
        return $user->canManageDomain($alias->domain_id);
    }
}
