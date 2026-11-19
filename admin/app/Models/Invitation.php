<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class Invitation extends Model
{
    public const TTL_DAYS = 7;

    protected $fillable = ['email', 'token_hash', 'invited_by', 'access', 'expires_at', 'accepted_at'];

    protected function casts(): array
    {
        return [
            'access' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * @param  array{domains?: array<int, int>, mailboxes?: array<int, int>}  $access
     * @return array{0: self, 1: string} The invitation and the plain token for the link.
     */
    public static function issue(string $email, array $access, ?User $by): array
    {
        $token = Str::random(64);

        $invitation = self::create([
            'email' => Str::lower($email),
            'token_hash' => self::hash($token),
            'invited_by' => $by?->id,
            'access' => [
                'domains' => array_values(array_map('intval', $access['domains'] ?? [])),
                'mailboxes' => array_values(array_map('intval', $access['mailboxes'] ?? [])),
            ],
            'expires_at' => now()->addDays(self::TTL_DAYS),
        ]);

        return [$invitation, $token];
    }

    /** New link and a fresh expiry, the old link stops working. */
    public function renew(): string
    {
        $token = Str::random(64);
        $this->update(['token_hash' => self::hash($token), 'expires_at' => now()->addDays(self::TTL_DAYS)]);

        return $token;
    }

    public static function findPending(string $token): ?self
    {
        return self::pending()->where('token_hash', self::hash($token))->first();
    }

    public static function pendingForEmail(string $email): ?self
    {
        return self::pending()->where('email', Str::lower($email))->latest('id')->first();
    }

    /**
     * Create the user. The invitation was delivered to this address, so the address counts as
     * verified. Ids of domains or mailboxes deleted since are dropped.
     */
    public function accept(string $name, ?string $password = null, array $extra = []): User
    {
        return DB::transaction(function () use ($name, $password, $extra) {
            $user = User::create([
                'name' => $name,
                'email' => $this->email,
                'password' => $password,
                ...$extra,
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->domains()->sync(Domain::whereIn('id', $this->access['domains'] ?? [])->pluck('id'));
            $user->mailboxes()->sync(Mailbox::whereIn('id', $this->access['mailboxes'] ?? [])->pluck('id'));

            $this->update(['accepted_at' => now()]);

            return $user;
        });
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
