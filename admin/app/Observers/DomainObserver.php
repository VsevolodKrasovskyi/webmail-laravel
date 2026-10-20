<?php

namespace App\Observers;

use App\Models\Domain;
use Illuminate\Support\Facades\File;

class DomainObserver
{
    public function creating(Domain $domain): void
    {
        if (filled($domain->dkim_selector) && filled($domain->dkim_public_key)) {
            return;
        }

        $domain->dkim_selector = 'mail';

        [$privateKeyPem, $publicKeyBody] = $this->generateKeyPair();
        $domain->dkim_public_key = $publicKeyBody;

        $this->writePrivateKey($domain->name, $domain->dkim_selector, $privateKeyPem);
    }

    /**
     * @return array{0: string, 1: string} [privateKeyPem, publicKeyBodyWithoutHeaders]
     */
    private function generateKeyPair(): array
    {
        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        openssl_pkey_export($resource, $privateKeyPem);

        $details = openssl_pkey_get_details($resource);
        $publicKeyPem = $details['key'];

        $publicKeyBody = preg_replace('/-----(BEGIN|END) PUBLIC KEY-----|\s+/', '', $publicKeyPem);

        return [$privateKeyPem, $publicKeyBody];
    }

    private function writePrivateKey(string $domainName, string $selector, string $privateKeyPem): void
    {
        $basePath = config('mail_server.dkim_keys_path');

        File::ensureDirectoryExists($basePath);
        File::put("{$basePath}/{$domainName}.{$selector}.key", $privateKeyPem);
    }
}
