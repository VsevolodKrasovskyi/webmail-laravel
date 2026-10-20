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
     * Create the record if none of the given type+name exists yet, or update
     * the first match in place. Returns 'created' or 'updated'.
     */
    public function upsertRecord(string $zoneId, array $record): string
    {
        $existing = $this->listRecords($zoneId, $record['type'], $record['name']);

        if (empty($existing)) {
            $response = $this->client()->post("/zones/{$zoneId}/dns_records", $record);
            $response->throw();

            return 'created';
        }

        $response = $this->client()->put("/zones/{$zoneId}/dns_records/{$existing[0]['id']}", $record);
        $response->throw();

        return 'updated';
    }

    /**
     * Create/update the MX, SPF, DKIM and DMARC records a mail domain needs,
     * in its Cloudflare zone.
     *
     * @return array<int, string> one human-readable line per record synced
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

        $action = $this->upsertRecord($zoneId, [
            'type' => 'MX',
            'name' => $domain,
            'content' => $mailHostname,
            'priority' => 10,
            'ttl' => 3600,
        ]);
        $log[] = "MX {$domain} -> {$mailHostname} ({$action})";

        $action = $this->upsertRecord($zoneId, [
            'type' => 'TXT',
            'name' => $domain,
            'content' => 'v=spf1 mx ~all',
            'ttl' => 3600,
        ]);
        $log[] = "SPF TXT {$domain} ({$action})";

        $dkimName = "{$dkimSelector}._domainkey.{$domain}";
        $action = $this->upsertRecord($zoneId, [
            'type' => 'TXT',
            'name' => $dkimName,
            'content' => "v=DKIM1; k=rsa; p={$dkimPublicKey}",
            'ttl' => 3600,
        ]);
        $log[] = "DKIM TXT {$dkimName} ({$action})";

        $dmarcName = "_dmarc.{$domain}";
        $action = $this->upsertRecord($zoneId, [
            'type' => 'TXT',
            'name' => $dmarcName,
            'content' => "v=DMARC1; p=quarantine; rua=mailto:postmaster@{$domain}",
            'ttl' => 3600,
        ]);
        $log[] = "DMARC TXT {$dmarcName} ({$action})";

        return $log;
    }

    private function client()
    {
        return Http::withToken(config('services.cloudflare.api_token'))
            ->baseUrl(self::BASE_URL)
            ->acceptJson();
    }
}
