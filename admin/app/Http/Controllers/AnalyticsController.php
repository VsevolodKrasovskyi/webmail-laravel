<?php

namespace App\Http\Controllers;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics and reports built only from what the admin database knows:
 * domains, mailboxes, aliases and scheduled mail. Real traffic volume and
 * disk usage live in Postfix/Dovecot and are not tracked here.
 */
class AnalyticsController extends Controller
{
    private const MONTHS = 12;

    private const SCHEDULED_DAYS = 30;

    public function index(): Response
    {
        $domains = $this->domainRows();

        return Inertia::render('Analytics', [
            'summary' => [
                'domains' => Domain::count(),
                'mailboxes' => Mailbox::count(),
                'aliases' => Alias::count(),
                'inactive_mailboxes' => Mailbox::where('is_active', false)->count(),
                'quota_allocated_mb' => (int) Mailbox::sum('quota_mb'),
                'avg_quota_mb' => (int) round((float) Mailbox::avg('quota_mb')),
            ],
            'growth' => $this->growth(),
            'domains' => $domains->values(),
            'health' => [
                'without_dkim' => $domains->where('has_dkim', false)->pluck('name')->values(),
                'without_mailboxes' => $domains->where('mailboxes', 0)->pluck('name')->values(),
                'inactive_domains' => $domains->where('is_active', false)->pluck('name')->values(),
            ],
            'scheduled' => [
                'by_status' => ScheduledEmail::query()
                    ->selectRaw('status, count(*) as total')
                    ->groupBy('status')
                    ->pluck('total', 'status'),
                'daily' => $this->scheduledDaily(),
            ],
        ]);
    }

    public function export(string $type): StreamedResponse
    {
        abort_unless(in_array($type, ['domains', 'mailboxes', 'scheduled'], true), 404);

        [$header, $rows] = match ($type) {
            'domains' => [
                ['Domain', 'Active', 'Mailboxes', 'Aliases', 'Quota allocated (MB)', 'DKIM'],
                $this->domainRows()->map(fn ($d) => [
                    $d['name'], $d['is_active'] ? 'yes' : 'no', $d['mailboxes'], $d['aliases'], $d['quota_mb'], $d['has_dkim'] ? 'yes' : 'no',
                ]),
            ],
            'mailboxes' => [
                ['Email', 'Name', 'Domain', 'Active', 'Quota (MB)', 'Created'],
                Mailbox::with('domain:id,name')->orderBy('email')->get()->map(fn ($m) => [
                    $m->email, $m->name, $m->domain?->name, $m->is_active ? 'yes' : 'no', $m->quota_mb, $m->created_at?->toDateTimeString(),
                ]),
            ],
            'scheduled' => [
                ['Mailbox', 'To', 'Subject', 'Send at', 'Status', 'Attempts', 'Sent at', 'Error'],
                ScheduledEmail::orderByDesc('send_at')->get()->map(fn ($m) => [
                    $m->mailbox_email, $m->to, $m->subject, $m->send_at?->toDateTimeString(), $m->status, $m->attempts, $m->sent_at?->toDateTimeString(), $m->error,
                ]),
            ],
        };

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM so Excel reads UTF-8
            fputcsv($out, $header);
            foreach ($rows as $row) {
                // Neutralise spreadsheet formula injection from user-controlled text.
                fputcsv($out, array_map(
                    fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v,
                    $row instanceof Collection ? $row->all() : $row,
                ));
            }
            fclose($out);
        }, "{$type}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function domainRows(): Collection
    {
        return Domain::query()
            ->withCount(['mailboxes', 'aliases'])
            ->withSum('mailboxes as quota_mb', 'quota_mb')
            ->orderBy('name')
            ->get()
            ->map(fn (Domain $d) => [
                'name' => $d->name,
                'is_active' => $d->is_active,
                'mailboxes' => $d->mailboxes_count,
                'aliases' => $d->aliases_count,
                'quota_mb' => (int) $d->quota_mb,
                'has_dkim' => filled($d->dkim_public_key),
            ]);
    }

    /**
     * New domains/mailboxes/aliases per month for the last 12 months.
     *
     * @return array<int, array{month:string, domains:int, mailboxes:int, aliases:int}>
     */
    private function growth(): array
    {
        $start = now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $buckets = [];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $buckets[$start->copy()->addMonths($i)->format('Y-m')] = ['domains' => 0, 'mailboxes' => 0, 'aliases' => 0];
        }

        foreach (['domains' => Domain::class, 'mailboxes' => Mailbox::class, 'aliases' => Alias::class] as $key => $model) {
            $model::where('created_at', '>=', $start)->pluck('created_at')->each(function ($createdAt) use (&$buckets, $key) {
                $month = Carbon::parse($createdAt)->format('Y-m');
                if (isset($buckets[$month])) {
                    $buckets[$month][$key]++;
                }
            });
        }

        return collect($buckets)->map(fn ($counts, $month) => ['month' => $month, ...$counts])->values()->all();
    }

    /**
     * Scheduled messages sent per day, last 30 days (zero-filled).
     *
     * @return array<int, array{day:string, sent:int, failed:int}>
     */
    private function scheduledDaily(): array
    {
        $start = now()->startOfDay()->subDays(self::SCHEDULED_DAYS - 1);
        $days = [];

        for ($i = 0; $i < self::SCHEDULED_DAYS; $i++) {
            $days[$start->copy()->addDays($i)->format('Y-m-d')] = ['sent' => 0, 'failed' => 0];
        }

        ScheduledEmail::where('send_at', '>=', $start)
            ->whereIn('status', [ScheduledEmail::SENT, ScheduledEmail::FAILED])
            ->get(['send_at', 'status'])
            ->each(function ($m) use (&$days) {
                $day = $m->send_at->format('Y-m-d');
                if (isset($days[$day])) {
                    $days[$day][$m->status]++;
                }
            });

        return collect($days)->map(fn ($c, $day) => ['day' => $day, ...$c])->values()->all();
    }
}
