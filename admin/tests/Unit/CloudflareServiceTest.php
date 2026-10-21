<?php

namespace Tests\Unit;

use App\Services\CloudflareService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CloudflareServiceTest extends TestCase
{
    private function sync(array $recordsByKey): array
    {
        config(['services.cloudflare.api_token' => 'token']);

        Http::fake(function (Request $request) use ($recordsByKey) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/zones')) {
                return Http::response(['result' => [['id' => 'zone1']]]);
            }

            if ($request->method() === 'GET') {
                $key = $request['type'].' '.$request['name'];

                return Http::response(['result' => $recordsByKey[$key] ?? []]);
            }

            return Http::response(['result' => ['id' => 'x']]);
        });

        return (new CloudflareService())->syncMailRecords('example.com', 'mail.host.test', 'mail', 'PUBKEY');
    }

    private function writes(): array
    {
        return Http::recorded()
            ->filter(fn ($pair) => in_array($pair[0]->method(), ['POST', 'PUT', 'DELETE'], true))
            ->map(fn ($pair) => [$pair[0]->method(), parse_url($pair[0]->url(), PHP_URL_PATH), $pair[0]['type'] ?? null, $pair[0]['content'] ?? null])
            ->values()
            ->all();
    }

    public function test_creates_everything_on_an_empty_zone(): void
    {
        $this->sync([]);

        $this->assertCount(4, $this->writes());
        $this->assertSame('POST', $this->writes()[0][0]);
    }

    public function test_foreign_txt_and_mx_are_never_overwritten_or_deleted(): void
    {
        $log = $this->sync([
            'MX example.com' => [['id' => 'mx1', 'content' => 'aspmx.l.google.com', 'priority' => 1]],
            'TXT example.com' => [
                ['id' => 't1', 'content' => 'google-site-verification=abc'],
                ['id' => 't2', 'content' => '"v=spf1 include:_spf.google.com ~all"'],
            ],
        ]);

        $writes = $this->writes();

        $this->assertNotContains('DELETE', array_column($writes, 0));
        $this->assertNotContains('PUT', array_column($writes, 0), 'nothing may be overwritten here');
        $this->assertStringContainsString('other MX record(s) left untouched', implode("\n", $log));
        $this->assertStringContainsString('SPF TXT example.com (kept)', implode("\n", $log));
        // Only MX (new, next to Google's), DKIM and DMARC are created.
        $this->assertCount(3, $writes);
    }

    public function test_own_records_are_updated_in_place_or_left_when_identical(): void
    {
        $log = $this->sync([
            'MX example.com' => [['id' => 'mx1', 'content' => 'mail.host.test.', 'priority' => 10]],
            'TXT mail._domainkey.example.com' => [['id' => 'k1', 'content' => 'v=DKIM1; k=rsa; p=OLDKEY']],
        ]);

        $writes = $this->writes();

        $this->assertStringContainsString('(unchanged)', $log[0]);
        $this->assertContains('/client/v4/zones/zone1/dns_records/k1', array_column($writes, 1));
        $this->assertSame(['PUT'], array_values(array_unique(array_filter(array_column($writes, 0), fn ($m) => $m === 'PUT'))));
    }
}
