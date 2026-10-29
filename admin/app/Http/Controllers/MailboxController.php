<?php

namespace App\Http\Controllers;

use App\Models\Domain;
use App\Models\Mailbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MailboxController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'domain', 'sort', 'direction']);

        $query = Mailbox::with('domain:id,name');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('email', 'ilike', $term)->orWhere('name', 'ilike', $term));
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        if (filled($filters['domain'] ?? null) && $filters['domain'] !== 'all') {
            $query->whereHas('domain', fn ($q) => $q->where('name', $filters['domain']));
        }

        $sort = in_array($filters['sort'] ?? null, ['email', 'quota_mb', 'created_at'])
            ? $filters['sort']
            : 'email';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);

        return Inertia::render('Mailboxes/Index', [
            'mailboxes' => $query->paginate(15)->withQueryString(),
            'domains' => Domain::where('is_active', true)->orderBy('name')->pluck('name'),
            'webmailImpersonationEnabled' => filled(config('mail_server.dovecot_master_password')),
            'filters' => array_merge(['search' => '', 'status' => 'all', 'domain' => 'all', 'sort' => $sort, 'direction' => $direction], $filters),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // Addresses are stored lowercase, so "Info@Example.com" and "info@example.com" are one mailbox.
        $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);

        $data = $request->validate([
            'email' => [
                'required', 'email', 'max:255',
                // Plain local part only: letters, digits and . _ + - (no quotes, spaces or other tricks).
                'regex:/^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?@[a-z0-9.-]+$/',
                'unique:mailboxes,email',
            ],
            'password' => ['required', 'string', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
            'quota_mb' => ['required', 'integer', 'min:1'],
        ], [
            'email.regex' => 'Use only letters, digits and . _ + - in the name before the @.',
        ]);

        $domainName = Str::lower(Str::afterLast($data['email'], '@'));

        if (! Domain::where('name', $domainName)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'email' => "Domain {$domainName} was not found or is disabled. Add an active domain first.",
            ]);
        }

        Mailbox::create($data);

        return back()->with('success', 'Mailbox created.');
    }

    public function update(Request $request, Mailbox $mailbox): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['nullable', 'string', 'min:8'],
            'name' => ['nullable', 'string', 'max:255'],
            'quota_mb' => ['required', 'integer', 'min:1'],
            'is_active' => ['required', 'boolean'],
        ]);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $mailbox->update($data);

        return back()->with('success', 'Mailbox updated.');
    }

    public function destroy(Mailbox $mailbox): RedirectResponse
    {
        $mailbox->delete();

        return back()->with('success', 'Mailbox deleted.');
    }
}
