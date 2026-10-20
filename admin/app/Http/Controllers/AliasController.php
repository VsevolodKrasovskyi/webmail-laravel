<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AliasController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->only(['search', 'status', 'sort', 'direction']);

        $query = Alias::with('domain:id,name');

        if (filled($filters['search'] ?? null)) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('source', 'ilike', $term)->orWhere('destination', 'ilike', $term));
        }

        if (($filters['status'] ?? 'all') !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }

        $sort = in_array($filters['sort'] ?? null, ['source', 'destination', 'created_at'])
            ? $filters['sort']
            : 'source';
        $direction = ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($sort, $direction);

        return Inertia::render('Aliases/Index', [
            'aliases' => $query->paginate(15)->withQueryString(),
            'filters' => array_merge(['search' => '', 'status' => 'all', 'sort' => $sort, 'direction' => $direction], $filters),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:255'],
            'destination' => ['required', 'email', 'max:255'],
        ]);

        $domainName = Str::lower(Str::afterLast($data['source'], '@'));

        if (! Domain::where('name', $domainName)->exists()) {
            throw ValidationException::withMessages([
                'source' => "Domain {$domainName} was not found. Add the domain first.",
            ]);
        }

        if (Alias::where('source', Str::lower($data['source']))->where('destination', Str::lower($data['destination']))->exists()) {
            throw ValidationException::withMessages([
                'source' => 'This alias already exists.',
            ]);
        }

        Alias::create($data);

        return back()->with('success', 'Alias created.');
    }

    public function update(Request $request, Alias $alias): RedirectResponse
    {
        $data = $request->validate([
            'destination' => ['required', 'email', 'max:255'],
            'is_active' => ['required', 'boolean'],
        ]);

        $alias->update($data);

        return back()->with('success', 'Alias updated.');
    }

    public function destroy(Alias $alias): RedirectResponse
    {
        $alias->delete();

        return back()->with('success', 'Alias deleted.');
    }
}
