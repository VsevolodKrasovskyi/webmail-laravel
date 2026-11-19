<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use App\Services\AnalyticsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, AnalyticsService $analytics): Response
    {
        $user = $request->user();
        $domains = $analytics->domainRows($user);

        return Inertia::render('Dashboard', [
            'stats' => [
                'domains' => $this->stat(Domain::visibleTo($user)),
                'mailboxes' => $this->stat(Mailbox::visibleTo($user)),
                'aliases' => $this->stat(Alias::visibleTo($user)),
            ],
            'quota' => [
                'allocated_mb' => (int) Mailbox::visibleTo($user)->sum('quota_mb'),
                'average_mb' => (int) round((float) Mailbox::visibleTo($user)->avg('quota_mb')),
            ],
            'attention' => [
                'scheduled_failed' => ScheduledEmail::visibleTo($user)->where('status', ScheduledEmail::FAILED)->count(),
                'scheduled_pending' => ScheduledEmail::visibleTo($user)->where('status', ScheduledEmail::PENDING)->count(),
                'inactive_mailboxes' => Mailbox::visibleTo($user)->where('is_active', false)->count(),
                'without_dkim' => $domains->where('has_dkim', false)->pluck('name')->values(),
                'without_mailboxes' => $domains->where('mailboxes', 0)->pluck('name')->values(),
                'inactive_domains' => $domains->where('is_active', false)->pluck('name')->values(),
            ],
            'growth' => $analytics->growth($user),
            // The domain report is paged in the database (`domains_page`), always 5 rows.
            'domains' => $analytics->domainsPage($user),
            'top_domains' => $domains->sortByDesc('mailboxes')->take(10)->map(fn ($d) => ['name' => $d['name'], 'mailboxes' => $d['mailboxes']])->values(),
            'scheduled' => [
                'by_status' => ScheduledEmail::visibleTo($user)
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'daily' => $analytics->scheduledDaily($user),
            ],
            // Live message counts need the Dovecot master password.
            'canReadMail' => filled(config('mail_server.dovecot_master_password')),
        ]);
    }

    /**
     * @param  Builder<Model>  $query
     * @return array{total:int, active:int, last_30_days:int}
     */
    private function stat(Builder $query): array
    {
        return [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'last_30_days' => (clone $query)->where('created_at', '>=', now()->subDays(30))->count(),
        ];
    }
}
