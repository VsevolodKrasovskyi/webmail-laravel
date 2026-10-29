<?php

namespace App\Services;

use App\Models\Alias;
use App\Models\Domain;
use App\Models\Mailbox;
use App\Models\ScheduledEmail;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Numbers for the dashboard, built only from what the admin database knows:
 * domains, mailboxes, aliases and scheduled mail. Message counts come from
 * Dovecot and are read separately (see WebmailService::mailboxStats).
 */
class AnalyticsService
{
    private const MONTHS = 12;

    private const SCHEDULED_DAYS = 30;

    /**
     * @return Collection<int, array<string, mixed>>
     */
    /** Page sizes the dashboard tables offer. */
    public const PAGE_SIZES = [5, 10, 20, 50];

    public static function pageSize(mixed $value): int
    {
        return in_array((int) $value, self::PAGE_SIZES, true) ? (int) $value : self::PAGE_SIZES[0];
    }

    private function domainQuery()
    {
        return Domain::query()
            ->withCount(['mailboxes', 'aliases'])
            ->withSum('mailboxes as quota_mb', 'quota_mb')
            ->orderBy('name');
    }

    /**
     * @return array{name:string, is_active:bool, mailboxes:int, aliases:int, quota_mb:int, has_dkim:bool}
     */
    private function domainRow(Domain $d): array
    {
        return [
            'name' => $d->name,
            'is_active' => $d->is_active,
            'mailboxes' => $d->mailboxes_count,
            'aliases' => $d->aliases_count,
            'quota_mb' => (int) $d->quota_mb,
            'has_dkim' => filled($d->dkim_public_key),
        ];
    }

    /**
     * Every domain, for the health checks and the top list.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function domainRows(): Collection
    {
        return $this->domainQuery()->get()->map(fn (Domain $d) => $this->domainRow($d));
    }

    /**
     * One page of the domain report, paged in the database (query parameter `domains_page`).
     */
    public function domainsPage(int $perPage): LengthAwarePaginator
    {
        return $this->domainQuery()
            ->paginate($perPage, ['*'], 'domains_page')
            ->through(fn (Domain $d) => $this->domainRow($d));
    }

    /**
     * New domains/mailboxes/aliases per month for the last 12 months.
     *
     * @return array<int, array{month:string, domains:int, mailboxes:int, aliases:int}>
     */
    public function growth(): array
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
    public function scheduledDaily(): array
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
