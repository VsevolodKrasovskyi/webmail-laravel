<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudflareService
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    public function isConfigured(): bool
    {
        return filled(config('services.cloudflare.api_token'));
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
     * @return array{0: string, 1: array<int, array>}  action, other records of the same type+name
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
        if (! $this->isConfigured()) {
            throw new RuntimeException('Cloudflare is not configured: set CLOUDFLARE_API_TOKEN.');
        }

        $zoneId = $this->findZoneId($domain);

        if (! $zoneId) {
            throw new RuntimeException("No Cloudflare zone found for {$domain}. Add it to your Cloudflare account first.");
        }

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
        return Http::withToken(config('services.cloudflare.api_token'))
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }
}
