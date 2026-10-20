<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => [
                'domains' => $this->stat(Domain::class),
                'mailboxes' => $this->stat(Mailbox::class),
                'aliases' => $this->stat(Alias::class),
            ],
            'quota_allocated_mb' => (int) Mailbox::sum('quota_mb'),
            'recent_mailboxes' => Mailbox::with('domain:id,name')
                ->latest()
                ->limit(5)
                ->get(['id', 'domain_id', 'email', 'name', 'is_active', 'created_at'])
                ->map(fn ($m) => [
                    'id' => $m->id,
                    'email' => $m->email,
                    'name' => $m->name,
                    'is_active' => $m->is_active,
                    'created_at' => $m->created_at?->diffForHumans(),
                ]),
            'attention' => [
                'domains_without_dkim' => Domain::whereNull('dkim_public_key')->orWhere('dkim_public_key', '')->count(),
                'inactive_mailboxes' => Mailbox::where('is_active', false)->count(),
                'scheduled_pending' => ScheduledEmail::where('status', ScheduledEmail::PENDING)->count(),
                'scheduled_failed' => ScheduledEmail::where('status', ScheduledEmail::FAILED)->count(),
            ],
        ]);
    }

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @return array{total:int, active:int, last_30_days:int}
     */
    private function stat(string $model): array
    {
        return [
            'total' => $model::count(),
            'active' => $model::where('is_active', true)->count(),
            'last_30_days' => $model::where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }
}
