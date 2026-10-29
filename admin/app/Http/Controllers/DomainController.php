<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Support\PageSize;
use App\Services\CloudflareService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class DomainController extends Controller
{
    public function __construct(private readonly CloudflareService $cloudflare) {}

    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $query = Domain::withCount(['mailboxes', 'aliases']);

        if (filled($filters['search'] ?? null)) {
            $query->where('name', 'ilike', '%'.$filters['search'].'%');
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $sort = in_array($filters['sort'] ?? null, ['name', 'mailboxes_count', 'aliases_count', 'created_at'])
            ? $filters['sort']
            : 'name';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);

        // Rows per page: 10, 20, 50 or 100 (default 10).
        $perPage = PageSize::list($request->query('per_page'));

        return Inertia::render('Domains/Index', [
            'domains' => $query->paginate($perPage)->withQueryString(),
            'filters' => array_merge(['search' => '', 'status' => 'all', 'sort' => $sort, 'direction' => $direction], $filters, ['per_page' => $perPage]),
            'cloudflareEnabled' => $this->cloudflare->isConfigured(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:domains,name'],
        ]);

        Domain::create($data);

        return back()->with('success', 'Domain added.');
    }

    public function update(Request $request, Domain $domain): RedirectResponse
    {
        $data = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $domain->update($data);

        return back()->with('success', 'Domain updated.');
    }

    public function destroy(Domain $domain): RedirectResponse
    {
        $domain->delete();

        return back()->with('success', 'Domain deleted.');
    }

    public function syncDns(Domain $domain): RedirectResponse
    {
        try {
            $log = $this->cloudflare->syncMailRecords(
                $domain->name,
                config('mail_server.app_domain'),
                $domain->dkim_selector,
                $domain->dkim_public_key,
            );
        } catch (Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'DNS synced via Cloudflare: '.implode('; ', $log));
    }
}
