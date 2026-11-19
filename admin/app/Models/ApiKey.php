<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A credential for an outside service, kept by administrators (several accounts per provider). */
class ApiKey extends Model
{
    public const CLOUDFLARE = 'cloudflare';

    protected $fillable = ['provider', 'name', 'token', 'token_hint', 'created_by', 'last_used_at'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'token' => 'encrypted',
            'last_used_at' => 'datetime',
        ];
    }

    public function scopeCloudflare(Builder $query): Builder
    {
        return $query->where('provider', self::CLOUDFLARE);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }
}
