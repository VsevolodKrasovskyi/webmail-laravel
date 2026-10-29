<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use App\Services\AnalyticsService;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(AnalyticsService $analytics): Response
    {
        $domains = $analytics->domainRows();

        return Inertia::render('Dashboard', [
            'stats' => [
                'domains' => $this->stat(Domain::class),
                'mailboxes' => $this->stat(Mailbox::class),
                'aliases' => $this->stat(Alias::class),
            ],
            'quota' => [
                'allocated_mb' => (int) Mailbox::sum('quota_mb'),
                'average_mb' => (int) round((float) Mailbox::avg('quota_mb')),
            ],
            'attention' => [
                'scheduled_failed' => ScheduledEmail::where('status', ScheduledEmail::FAILED)->count(),
                'scheduled_pending' => ScheduledEmail::where('status', ScheduledEmail::PENDING)->count(),
                'inactive_mailboxes' => Mailbox::where('is_active', false)->count(),
                'without_dkim' => $domains->where('has_dkim', false)->pluck('name')->values(),
                'without_mailboxes' => $domains->where('mailboxes', 0)->pluck('name')->values(),
                'inactive_domains' => $domains->where('is_active', false)->pluck('name')->values(),
            ],
            'growth' => $analytics->growth(),
            // The domain report is paged in the database (`domains_page`), always 5 rows.
            'domains' => $analytics->domainsPage(),
            'top_domains' => $domains->sortByDesc('mailboxes')->take(10)->map(fn ($d) => ['name' => $d['name'], 'mailboxes' => $d['mailboxes']])->values(),
            'scheduled' => [
                'by_status' => ScheduledEmail::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'daily' => $analytics->scheduledDaily(),
            ],
            // Live message counts need the Dovecot master password.
            'canReadMail' => filled(config('mail_server.dovecot_master_password')),
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
