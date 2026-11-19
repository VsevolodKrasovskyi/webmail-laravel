<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'email',
        'password',
        'name',
        'quota_mb',
        'is_active',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'quota_mb' => 'integer',
        ];
    }

    /** Administrators see every mailbox, other users those of their domains and the ones assigned to them. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereIn('mailboxes.domain_id', $user->managedDomainIds())
            ->orWhereIn('mailboxes.id', $user->assignedMailboxIds()));
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * Store passwords as {SHA512-CRYPT} so Dovecot can authenticate directly
     * against this column without any translation layer.
     */
    public function setPasswordAttribute(?string $value): void
    {
        if (blank($value)) {
            return;
        }

        $this->attributes['password'] = self::hashForDovecot($value);
    }

    public static function hashForDovecot(string $plain): string
    {
        $salt = substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16);
        $hash = crypt($plain, '$6$'.$salt.'$');

        return '{SHA512-CRYPT}'.$hash;
    }
}
