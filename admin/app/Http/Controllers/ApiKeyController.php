<?php

namespace App\Http\Controllers;

use App\Models\ApiKey;
use App\Services\CloudflareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** API keys of outside services (Cloudflare accounts). Administrators only. A saved token is never sent back to the browser. */
class ApiKeyController extends Controller
{
    public function __construct(private readonly CloudflareService $cloudflare) {}

    public function index(): Response
    {
        return Inertia::render('ApiKeys/Index', [
            'keys' => ApiKey::cloudflare()->withCount('domains')->orderBy('name')->get()->map(fn (ApiKey $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'provider' => $k->provider,
                'token_hint' => $k->token_hint,
                'domains_count' => $k->domains_count,
                'last_used_at' => $k->last_used_at?->toIso8601String(),
                'created_at' => $k->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** The token is checked with Cloudflare before it is saved. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'token' => ['required', 'string', 'min:20', 'max:500'],
        ]);

        $token = trim($data['token']);

        try {
            $zones = $this->cloudflare->verify($token);
        } catch (Throwable $e) {
            return back()->withErrors(['token' => 'Cloudflare refused this token: '.$e->getMessage()])->withInput();
        }

        ApiKey::create([
            'provider' => ApiKey::CLOUDFLARE,
            'name' => $data['name'],
            'token' => $token,
            'token_hint' => substr($token, -4),
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Key saved, it sees {$zones} zone(s).");
    }

    public function test(ApiKey $apiKey): RedirectResponse
    {
        try {
            $zones = $this->cloudflare->verify($apiKey->token);
        } catch (Throwable $e) {
            return back()->with('error', "{$apiKey->name}: Cloudflare refused the token. {$e->getMessage()}");
        }

        return back()->with('success', "{$apiKey->name}: the token works, it sees {$zones} zone(s).");
    }

    public function destroy(ApiKey $apiKey): RedirectResponse
    {
        $apiKey->delete();

        return back()->with('success', 'Key deleted. Revoke the token in Cloudflare too if it is no longer needed.');
    }
}
