<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\Domain;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    /** The token of the Cloudflare account this instance talks to, see using(). */
    private ?string $token = null;

    /** A copy of the service that talks to the account of this token. */
    public function using(string $token): static
    {
        $copy = clone $this;
        $copy->token = $token;

        return $copy;
    }

    /** True when an administrator added at least one Cloudflare API key. */
    public function isConfigured(): bool
    {
        return ApiKey::cloudflare()->exists();
    }

    /**
     * Check a token and return how many zones it can see.
     *
     * @throws RuntimeException with Cloudflare's own message when the token is refused
     */
    public function verify(string $token): int
    {
        $response = $this->using($token)->client()->get('/zones', ['per_page' => 1]);

        if (! $response->successful()) {
            throw new RuntimeException($response->json('errors.0.message') ?: "Cloudflare answered HTTP {$response->status()}.");
        }

        return (int) $response->json('result_info.total_count', count($response->json('result', [])));
    }

    /**
     * Sync one domain with whichever connected Cloudflare account holds its zone. The account that
     * served the domain last time is tried first, then the others. Never creates zones.
     *
     * @return array<int, string> one human-readable line per record
     */
    public function syncDomain(Domain $domain, string $mailHostname): array
    {
        $keys = ApiKey::cloudflare()->orderBy('id')->get();

        if ($keys->isEmpty()) {
            throw new RuntimeException('No Cloudflare API key is connected. An administrator adds one on the API keys page.');
        }

        $keys = $keys->sortByDesc(fn (ApiKey $key) => $key->id === $domain->api_key_id)->values();
        $problems = [];

        foreach ($keys as $key) {
            $service = $this->using($key->token);

            try {
                $zoneId = $service->findZoneId($domain->name);
            } catch (RequestException $e) {
                $problems[] = "{$key->name}: ".($e->response->json('errors.0.message') ?: 'HTTP '.$e->response->status()).'.';

                continue;
            }

            if (! $zoneId) {
                continue;
            }

            $log = $service->syncZone($zoneId, $domain->name, $mailHostname, $domain->dkim_selector, $domain->dkim_public_key);

            $key->forceFill(['last_used_at' => now()])->save();
            if ($domain->api_key_id !== $key->id) {
                $domain->forceFill(['api_key_id' => $key->id])->save();
            }

            return ["Cloudflare account: {$key->name}", ...$log];
        }

        throw new RuntimeException(
            "No Cloudflare zone found for {$domain->name} in ".$keys->count().' connected account(s). Add the domain to Cloudflare first.'
            .($problems ? ' '.implode(' ', $problems) : '')
        );
    }

    /**
     * Find the Cloudflare zone id for a domain (the domain must already be
     * added to the Cloudflare account; this never creates zones).
     */
    public function findZoneId(string $domain): ?string
    {
        $response = $this->client()->get('/zones', ['name' => $domain]);
        $response->throw();

        return $response->json('result.0.id');
    }

    /**
     * @return array<int, array{id: string, type: string, name: string, content: string}>
     */
    public function listRecords(string $zoneId, string $type, string $name): array
    {
        $response = $this->client()->get("/zones/{$zoneId}/dns_records", [
            'type' => $type,
            'name' => $name,
        ]);
        $response->throw();

        return $response->json('result', []);
    }

    /**
     * Normalises content for comparison: Cloudflare may return TXT wrapped in
     * quotes, and hostnames with or without a trailing dot.
     */
    private function normalize(string $content): string
    {
        return rtrim(strtolower(trim($content, " \t\n\r\0\x0B\"")), '.');
    }

    /**
     * Create or update only the record this app owns, never a neighbour.
     *
     * Several records can share a type and name (many TXT at the apex, several
     * MX), so "first one found" is wrong. Records are matched by content:
     *  - `$ownedBy` says whether an existing record is the kind we manage
     *    (an SPF record, a DKIM record, an MX pointing at our host).
     *  - if one exists and is identical: left alone ('unchanged').
     *  - if one exists and differs and `$overwrite`: updated in place.
     *  - if one exists and differs and not `$overwrite`: left alone ('kept'),
     *    the caller is told so the admin can decide.
     *  - if none exists: created next to whatever else is there.
     *
     * @param  callable(array): bool  $ownedBy
     * @return array{0: string, 1: array<int, array>} action, other records of the same type+name
     */
    public function ensureRecord(string $zoneId, array $record, callable $ownedBy, bool $overwrite): array
    {
        $existing = $this->listRecords($zoneId, $record['type'], $record['name']);
        $owned = array_values(array_filter($existing, $ownedBy));
        $others = array_values(array_filter($existing, fn ($r) => ! $ownedBy($r)));

        if (empty($owned)) {
            $this->client()->post("/zones/{$zoneId}/dns_records", $record)->throw();

            return ['created', $others];
        }

        $current = $owned[0];
        $same = $this->normalize($current['content']) === $this->normalize($record['content'])
            && (int) ($current['priority'] ?? 0) === (int) ($record['priority'] ?? 0);

        if ($same) {
            return ['unchanged', $others];
        }

        if (! $overwrite) {
            return ['kept', $others];
        }

        $this->client()->put("/zones/{$zoneId}/dns_records/{$current['id']}", $record)->throw();

        return ['updated', $others];
    }

    /**
     * Create/update the MX, SPF, DKIM and DMARC records a mail domain needs,
     * in its Cloudflare zone, without touching records that belong to someone
     * else. Nothing is ever deleted.
     *
     * @return array<int, string> one human-readable line per record
     */
    public function syncMailRecords(string $domain, string $mailHostname, string $dkimSelector, string $dkimPublicKey): array
    {
        $zoneId = $this->findZoneId($domain);

        if (! $zoneId) {
            throw new RuntimeException("No Cloudflare zone found for {$domain}. Add it to your Cloudflare account first.");
        }

        return $this->syncZone($zoneId, $domain, $mailHostname, $dkimSelector, $dkimPublicKey);
    }

    /** @return array<int, string> one human-readable line per record */
    public function syncZone(string $zoneId, string $domain, string $mailHostname, string $dkimSelector, string $dkimPublicKey): array
    {
        $log = [];
        $host = rtrim(strtolower($mailHostname), '.');
        $startsWith = fn (string $prefix) => fn (array $r) => str_starts_with($this->normalize($r['content']), $prefix);

        // MX: ours is the record pointing at this server. Other providers' MX stay.
        [$action, $others] = $this->ensureRecord($zoneId, [
            'type' => 'MX', 'name' => $domain, 'content' => $mailHostname, 'priority' => 10, 'ttl' => 3600,
        ], fn (array $r) => rtrim(strtolower($r['content']), '.') === $host, true);
        $log[] = "MX {$domain} -> {$mailHostname} ({$action})";
        if ($others) {
            $log[] = 'WARNING: '.count($others).' other MX record(s) left untouched, remove them in Cloudflare if all mail should come here';
        }

        // SPF: only one is allowed per name. An existing different one is kept, it may list other senders.
        [$action, $others] = $this->ensureRecord($zoneId, [
            'type' => 'TXT', 'name' => $domain, 'content' => 'v=spf1 mx ~all', 'ttl' => 3600,
        ], $startsWith('v=spf1'), false);
        $log[] = "SPF TXT {$domain} ({$action})".($action === 'kept' ? ': an SPF record already exists, make sure it allows this server (mx)' : '');

        $dkimName = "{$dkimSelector}._domainkey.{$domain}";
        [$action] = $this->ensureRecord($zoneId, [
            'type' => 'TXT', 'name' => $dkimName, 'content' => "v=DKIM1; k=rsa; p={$dkimPublicKey}", 'ttl' => 3600,
        ], $startsWith('v=dkim1'), true);
        $log[] = "DKIM TXT {$dkimName} ({$action})";

        // DMARC: an existing policy is the owner's decision, never replaced.
        $dmarcName = "_dmarc.{$domain}";
        [$action] = $this->ensureRecord($zoneId, [
            'type' => 'TXT', 'name' => $dmarcName, 'content' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}", 'ttl' => 3600,
        ], $startsWith('v=dmarc1'), false);
        $log[] = "DMARC TXT {$dmarcName} ({$action})".($action === 'kept' ? ': an existing DMARC policy was left as is' : '');

        return $log;
    }

    private function client()
    {
        if (blank($this->token)) {
            throw new RuntimeException('No Cloudflare API key is set.');
        }

        return Http::withToken($this->token)
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }
}
