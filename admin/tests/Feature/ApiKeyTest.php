<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Domain;
use App\Models\User;
use App\Services\CloudflareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ApiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_A = 'tokenAAAAAAAAAAAAAAAAAAAAAAAA1111';

    private const TOKEN_B = 'tokenBBBBBBBBBBBBBBBBBBBBBBBB2222';

    private function key(string $name, string $token): ApiKey
    {
        return ApiKey::create(['provider' => 'cloudflare', 'name' => $name, 'token' => $token, 'token_hint' => substr($token, -4)]);
    }

    /** Cloudflare with two accounts: zones by token, every other call succeeds. */
    private function fakeCloudflare(array $zonesByToken): void
    {
        Http::fake(function (Request $request) use ($zonesByToken) {
            $token = $request->header('Authorization')[0] ?? '';
            $token = str_replace('Bearer ', '', $token);
            $path = parse_url($request->url(), PHP_URL_PATH);

            if (str_ends_with($path, '/zones')) {
                if (! isset($zonesByToken[$token])) {
                    return Http::response(['success' => false, 'errors' => [['message' => 'Invalid API Token']]], 403);
                }
                $zone = $zonesByToken[$token][$request['name'] ?? ''] ?? null;

                return Http::response(['success' => true, 'result' => $zone ? [['id' => $zone]] : [], 'result_info' => ['total_count' => count($zonesByToken[$token])]]);
            }

            return Http::response(['result' => $request->method() === 'GET' ? [] : ['id' => 'x']]);
        });
    }

    public function test_an_admin_adds_a_key_after_cloudflare_accepts_it(): void
    {
        $this->fakeCloudflare([self::TOKEN_A => ['example.com' => 'z1']]);

        $this->actingAsAdmin()->post('/api-keys', ['name' => 'Company', 'token' => self::TOKEN_A])->assertSessionHas('success');

        $key = ApiKey::firstWhere('name', 'Company');
        $this->assertSame(self::TOKEN_A, $key->token);
        $this->assertSame('1111', $key->token_hint);
        // Stored encrypted, not as plain text.
        $this->assertNotSame(self::TOKEN_A, DB::table('api_keys')->where('id', $key->id)->value('token'));
    }

    public function test_a_token_cloudflare_refuses_is_not_saved(): void
    {
        $this->fakeCloudflare([]);

        $this->actingAsAdmin()->post('/api-keys', ['name' => 'Bad', 'token' => self::TOKEN_B])->assertSessionHasErrors('token');

        $this->assertSame(0, ApiKey::count());
    }

    public function test_the_token_never_reaches_the_browser(): void
    {
        $this->key('Company', self::TOKEN_A);

        $response = $this->actingAsAdmin()->get('/api-keys');

        $response->assertInertia(fn ($p) => $p->has('keys', 1)->where('keys.0.token_hint', '1111')->missing('keys.0.token'));
        $this->assertStringNotContainsString(self::TOKEN_A, $response->getContent());
    }

    public function test_only_admins_manage_keys(): void
    {
        $user = User::factory()->withTwoFactor()->create();
        $key = $this->key('Company', self::TOKEN_A);

        $this->actingAsFullyAuthenticated($user)->get('/api-keys')->assertForbidden();
        $this->post('/api-keys', ['name' => 'x', 'token' => self::TOKEN_B])->assertForbidden();
        $this->post("/api-keys/{$key->id}/test")->assertForbidden();
        $this->delete("/api-keys/{$key->id}")->assertForbidden();
        $this->assertNotNull($key->fresh());
    }

    public function test_a_key_can_be_checked_and_deleted(): void
    {
        $this->fakeCloudflare([self::TOKEN_A => ['example.com' => 'z1']]);
        $key = $this->key('Company', self::TOKEN_A);
        $this->actingAsAdmin();

        $this->post("/api-keys/{$key->id}/test")->assertSessionHas('success');
        $this->delete("/api-keys/{$key->id}")->assertSessionHas('success');

        $this->assertNull(ApiKey::find($key->id));
    }

    public function test_a_revoked_token_is_reported_by_the_check(): void
    {
        $this->fakeCloudflare([]);
        $key = $this->key('Old', self::TOKEN_A);

        $this->actingAsAdmin()->post("/api-keys/{$key->id}/test")->assertSessionHas('error');
    }

    public function test_the_sync_finds_the_domain_in_the_right_account_and_remembers_it(): void
    {
        $this->fakeCloudflare([
            self::TOKEN_A => ['one.com' => 'zoneA'],
            self::TOKEN_B => ['two.com' => 'zoneB'],
        ]);
        $a = $this->key('Account A', self::TOKEN_A);
        $b = $this->key('Account B', self::TOKEN_B);
        $domain = Domain::create(['name' => 'two.com']);

        $log = app(CloudflareService::class)->syncDomain($domain, 'mail.host.test');

        $this->assertSame('Cloudflare account: Account B', $log[0]);
        $this->assertSame($b->id, $domain->fresh()->api_key_id);
        $this->assertNotNull($b->fresh()->last_used_at);
        $this->assertNull($a->fresh()->last_used_at);
        // Records were written in the zone of account B, with its token.
        $writes = Http::recorded()->filter(fn ($p) => $p[0]->method() === 'POST');
        $this->assertTrue($writes->every(fn ($p) => str_contains($p[0]->url(), '/zones/zoneB/')));
        $this->assertTrue($writes->every(fn ($p) => $p[0]->hasHeader('Authorization', 'Bearer '.self::TOKEN_B)));
    }

    public function test_a_domain_in_no_account_is_reported(): void
    {
        $this->fakeCloudflare([self::TOKEN_A => ['one.com' => 'zoneA']]);
        $this->key('Account A', self::TOKEN_A);
        $domain = Domain::create(['name' => 'missing.com']);

        $this->expectExceptionMessage('No Cloudflare zone found for missing.com in 1 connected account(s)');

        app(CloudflareService::class)->syncDomain($domain, 'mail.host.test');
    }

    public function test_a_broken_account_does_not_block_the_others(): void
    {
        $this->fakeCloudflare([self::TOKEN_B => ['two.com' => 'zoneB']]);
        $this->key('Revoked', self::TOKEN_A);
        $this->key('Good', self::TOKEN_B);
        $domain = Domain::create(['name' => 'two.com']);

        $log = app(CloudflareService::class)->syncDomain($domain, 'mail.host.test');

        $this->assertSame('Cloudflare account: Good', $log[0]);
    }

    public function test_sync_needs_a_key_and_the_domain_page_says_so(): void
    {
        $domain = Domain::create(['name' => 'two.com']);
        $this->actingAsAdmin();

        $this->get('/domains')->assertInertia(fn ($p) => $p->where('cloudflareEnabled', false));
        $this->post("/domains/{$domain->id}/dns")->assertSessionHas('error');

        $this->key('Company', self::TOKEN_A);
        $this->get('/domains')->assertInertia(fn ($p) => $p->where('cloudflareEnabled', true));
    }

    public function test_deleting_a_key_keeps_its_domains(): void
    {
        $key = $this->key('Company', self::TOKEN_A);
        $domain = Domain::create(['name' => 'two.com']);
        $domain->forceFill(['api_key_id' => $key->id])->save();

        $key->delete();

        $this->assertNull($domain->fresh()->api_key_id);
    }
}
