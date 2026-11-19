<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'google_id', 'avatar'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var array<int, int>|null */
    private ?array $domainIdCache = null;

    /** @var array<int, int>|null */
    private ?array $mailboxIdCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Addresses listed in ADMIN_EMAIL are administrators: every domain and mailbox, and the user list. */
    public function isAdmin(): bool
    {
        return in_array(strtolower($this->email), config('auth.admin_emails'), true);
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret);
    }

    public function domains(): BelongsToMany
    {
        return $this->belongsToMany(Domain::class);
    }

    public function mailboxes(): BelongsToMany
    {
        return $this->belongsToMany(Mailbox::class);
    }

    /** @return array<int, int> Domains the user manages completely. */
    public function managedDomainIds(): array
    {
        return $this->domainIdCache ??= $this->domains()->pluck('domains.id')->all();
    }

    /** @return array<int, int> Mailboxes assigned to the user one by one. */
    public function assignedMailboxIds(): array
    {
        return $this->mailboxIdCache ??= $this->mailboxes()->pluck('mailboxes.id')->all();
    }

    public function managesAnyDomain(): bool
    {
        return $this->isAdmin() || $this->managedDomainIds() !== [];
    }

    public function canManageDomain(int $domainId): bool
    {
        return $this->isAdmin() || in_array($domainId, $this->managedDomainIds(), true);
    }

    public function canAccessMailbox(Mailbox $mailbox): bool
    {
        return $this->canManageDomain($mailbox->domain_id)
            || in_array($mailbox->id, $this->assignedMailboxIds(), true);
    }

    /** Quota, active flag and delete need the whole domain, a single assigned mailbox does not give that. */
    public function canManageMailbox(Mailbox $mailbox): bool
    {
        return $this->canManageDomain($mailbox->domain_id);
    }
}
