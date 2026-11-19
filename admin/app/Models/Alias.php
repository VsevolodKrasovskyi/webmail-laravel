<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alias extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'source',
        'destination',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Administrators see every alias, other users the aliases of the domains they manage. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->isAdmin() ? $query : $query->whereIn('aliases.domain_id', $user->managedDomainIds());
    }

    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }
}
