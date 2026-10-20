<?php

namespace App\Observers;

use App\Models\Alias;
use App\Models\Domain;
use Illuminate\Support\Str;
use RuntimeException;

class AliasObserver
{
    public function saving(Alias $alias): void
    {
        $domainName = Str::lower(Str::afterLast($alias->source, '@'));

        $domain = Domain::where('name', $domainName)->first();

        if (! $domain) {
            throw new RuntimeException("Domain {$domainName} was not found. Add the domain first.");
        }

        $alias->domain_id = $domain->id;
        $alias->source = Str::lower($alias->source);
        $alias->destination = Str::lower($alias->destination);
    }
}
