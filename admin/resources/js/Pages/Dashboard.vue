<script setup>
import { Head } from '@inertiajs/vue3';
import MailboxStatsController from '@/actions/App/Http/Controllers/MailboxStatsController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import MailboxController from '@/actions/App/Http/Controllers/MailboxController';
import AliasController from '@/actions/App/Http/Controllers/AliasController';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    GlobeIcon, MailIcon, ArrowRightLeftIcon, BellIcon, RefreshCwIcon,
    TriangleAlertIcon, ClockIcon, InboxIcon, HardDriveIcon, CircleCheckIcon, MailOpenIcon,
} from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import BarChart from '@/components/BarChart.vue';
import MailboxAvatar from '@/components/MailboxAvatar.vue';
import { fetchJson } from '../fetchJson';
import PagerBar from '@/components/PagerBar.vue';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import MailboxTotalsController from '@/actions/App/Http/Controllers/MailboxTotalsController';
import { Card, CardHeader, CardTitle, CardDescription, CardAction, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Table, TableHeader, TableBody, TableRow, TableHead, TableCell,
} from '@/components/ui/table';

const props = defineProps({
    stats: Object,
    quota: Object,
    attention: Object,
    growth: Array,
    domains: Object,
    top_domains: Array,
    scheduled: Object,
    canReadMail: Boolean,
});

const page = usePage();

// ---- Live message counts (read from Dovecot after the page has rendered) ----
// The table is one page of mailboxes (the controller pages it, so only that page is read over IMAP),
// the totals (unread notification, Messages card) are a separate call over every mailbox.
const live = ref(null); // { mailboxes, meta } for the current page
const totals = ref(null);
const liveLoading = ref(false);
const liveError = ref(null);
const mailboxPage = ref(1);
const mailboxSize = ref(5);
let timer;

async function loadRows(refresh = false) {
    if (! props.canReadMail || liveLoading.value) return;
    liveLoading.value = true;
    try {
        const query = { page: mailboxPage.value, per_page: mailboxSize.value };
        if (refresh) query.refresh = 1;
        live.value = await fetchJson(MailboxStatsController({ query }));
        mailboxPage.value = live.value.meta.current_page;
        liveError.value = null;
    } catch (e) {
        liveError.value = e.message;
    } finally {
        liveLoading.value = false;
    }
}

async function loadTotals(refresh = false) {
    if (! props.canReadMail) return;
    try {
        totals.value = await fetchJson(MailboxTotalsController(refresh ? { query: { refresh: 1 } } : undefined));
    } catch (e) {
        liveError.value = liveError.value ?? e.message;
    }
}

// Rows first so the table shows quickly, then the totals.
async function loadLive(refresh = false) {
    await loadRows(refresh);
    await loadTotals(refresh);
}

function goMailboxPage(page) {
    mailboxPage.value = page;
    loadRows();
}

function setMailboxSize(size) {
    mailboxSize.value = size;
    mailboxPage.value = 1;
    loadRows();
}

// Domain report: paged in the database, reloaded as an Inertia partial visit.
function visitDomains(params) {
    router.get(DashboardController.url(), {
        domains_page: props.domains.current_page,
        domains_per_page: props.domains.per_page,
        ...params,
    }, { only: ['domains'], preserveState: true, preserveScroll: true, replace: true });
}

onMounted(() => {
    loadLive();
    // Keep the unread notification fresh while the tab is open.
    timer = setInterval(() => {
        if (document.visibilityState === 'visible') loadLive();
    }, 60_000);
});

onBeforeUnmount(() => clearInterval(timer));

const unreadTotal = computed(() => totals.value?.unread_total ?? 0);

// ---- Header ----
const user = computed(() => page.props.auth.user);
const avatarFailed = ref(false);
const firstName = computed(() => (user.value?.name || user.value?.email || '').split(/[\s@]/)[0] || 'admin');
const initials = computed(() => firstName.value.slice(0, 2));
const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 5 ? 'Good night' : hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
});
const today = new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });

function openWebmail(row) {
    router.post(WebmailController.loginAsMailbox(row.id));
}

// ---- Static numbers ----
const cards = [
    { key: 'domains', label: 'Domains', icon: GlobeIcon, href: DomainController.index.url(), tone: 'bg-blue-500/10 text-blue-600 dark:text-blue-400' },
    { key: 'mailboxes', label: 'Mailboxes', icon: MailIcon, href: MailboxController.index.url(), tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    { key: 'aliases', label: 'Aliases', icon: ArrowRightLeftIcon, href: AliasController.index.url(), tone: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
];

const alerts = computed(() => {
    const a = props.attention;
    return [
        { show: a.scheduled_failed > 0, tone: 'text-destructive', text: `${a.scheduled_failed} scheduled message(s) failed to send`, href: MailboxController.index.url() },
        { show: a.without_dkim.length > 0, tone: 'text-amber-600 dark:text-amber-400', text: `No DKIM key: ${a.without_dkim.join(', ')}`, href: DomainController.index.url() },
        { show: a.without_mailboxes.length > 0, tone: 'text-amber-600 dark:text-amber-400', text: `No mailboxes: ${a.without_mailboxes.join(', ')}`, href: MailboxController.index.url() },
        { show: a.inactive_domains.length > 0, tone: 'text-muted-foreground', text: `Inactive domains: ${a.inactive_domains.join(', ')}`, href: DomainController.index.url() },
        { show: a.inactive_mailboxes > 0, tone: 'text-muted-foreground', text: `${a.inactive_mailboxes} inactive mailbox(es)`, href: MailboxController.index.url() },
        { show: a.scheduled_pending > 0, tone: 'text-muted-foreground', text: `${a.scheduled_pending} message(s) waiting to be sent`, href: MailboxController.index.url() },
    ].filter((x) => x.show);
});

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const monthLabel = (ym) => MONTHS[Number(ym.slice(5, 7)) - 1];

function series(key, noun) {
    return props.growth.map((g) => ({
        label: monthLabel(g.month),
        value: g[key],
        tooltip: `${monthLabel(g.month)} ${g.month.slice(0, 4)}: ${g[key]} new ${noun}`,
    }));
}

const growthCharts = computed(() => [
    { key: 'mailboxes', title: 'New mailboxes', bar: 'bg-emerald-500', items: series('mailboxes', 'mailboxes') },
    { key: 'domains', title: 'New domains', bar: 'bg-blue-500', items: series('domains', 'domains') },
    { key: 'aliases', title: 'New aliases', bar: 'bg-amber-500', items: series('aliases', 'aliases') },
]);

const sentDaily = computed(() => props.scheduled.daily.map((d) => ({
    label: d.day.slice(8),
    value: d.sent,
    tooltip: `${d.day}: ${d.sent} sent, ${d.failed} failed`,
})));

const topDomains = computed(() => props.top_domains);
const maxMailboxes = computed(() => Math.max(1, ...topDomains.value.map((d) => d.mailboxes)));
const status = computed(() => props.scheduled.by_status ?? {});

function formatQuota(mb) {
    return mb >= 1024 ? `${(mb / 1024).toFixed(mb >= 10240 ? 0 : 1)} GB` : `${mb} MB`;
}
</script>

<template>
    <AppLayout>
        <Head title="Dashboard" />
        <div class="mx-auto max-w-6xl space-y-6">
            <!-- Header with the unread notification -->
            <div class="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-blue-600/25 via-sky-500/10 to-cyan-500/15 p-5 sm:p-8">
                <!-- Decoration: soft glows and a faint dot grid -->
                <div class="pointer-events-none absolute -right-20 -top-24 size-72 rounded-full bg-blue-500/25 blur-3xl" />
                <div class="pointer-events-none absolute -bottom-28 left-1/3 size-72 rounded-full bg-sky-500/15 blur-3xl" />
                <div
                    class="pointer-events-none absolute inset-0 opacity-[0.07] [background-image:radial-gradient(currentColor_1px,transparent_1px)] [background-size:18px_18px]"
                />

                <div class="relative flex flex-wrap items-center justify-between gap-6">
                    <div class="flex min-w-0 items-center gap-4">
                        <img
                            v-if="user?.avatar && ! avatarFailed"
                            :src="user.avatar"
                            alt=""
                            referrerpolicy="no-referrer"
                            class="size-14 shrink-0 rounded-2xl object-cover shadow-lg shadow-blue-500/30 ring-2 ring-background/60"
                            @error="avatarFailed = true"
                        />
                        <span v-else class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-500 to-sky-600 text-lg font-semibold uppercase text-white shadow-lg shadow-blue-500/30">
                            {{ initials }}
                        </span>
                        <div class="min-w-0">
                            <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">{{ today }}</p>
                            <h2 class="mt-0.5 break-words text-2xl font-semibold leading-tight tracking-tight sm:truncate sm:text-3xl">{{ greeting }}, {{ firstName }}</h2>
                            <p class="mt-1 break-all text-sm text-muted-foreground sm:truncate">{{ page.props.auth.user?.email }}</p>
                        </div>
                    </div>

                    <div class="grid w-full grid-cols-2 gap-3 sm:w-auto">
                        <Link
                            v-if="canReadMail"
                            :href="MailboxController.index.url()"
                            class="group flex items-center gap-3 rounded-xl border bg-background/60 px-4 py-3 backdrop-blur transition hover:bg-background/80"
                            :class="unreadTotal > 0 ? 'border-rose-500/40' : ''"
                            :title="liveError ?? 'Unread messages in all inboxes'"
                        >
                            <span
                                class="relative flex size-10 shrink-0 items-center justify-center rounded-lg"
                                :class="unreadTotal > 0 ? 'bg-rose-500/15 text-rose-500' : 'bg-muted text-muted-foreground'"
                            >
                                <BellIcon class="size-5" />
                                <span v-if="unreadTotal > 0" class="absolute -right-0.5 -top-0.5 size-2.5 rounded-full bg-rose-500 ring-2 ring-background" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xs text-muted-foreground">Unread</span>
                                <span v-if="totals" class="block text-xl font-semibold leading-tight tabular-nums">{{ unreadTotal }}</span>
                                <span v-else class="block text-sm text-muted-foreground">{{ liveLoading ? 'Checking...' : 'n/a' }}</span>
                            </span>
                        </Link>

                        <div class="flex items-center gap-3 rounded-xl border bg-background/60 px-4 py-3 backdrop-blur">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-blue-500/15 text-blue-500">
                                <HardDriveIcon class="size-5" />
                            </span>
                            <span class="min-w-0">
                                <span class="block text-xs text-muted-foreground">Quota allocated</span>
                                <span class="block text-xl font-semibold leading-tight tabular-nums">{{ formatQuota(quota.allocated_mb) }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Totals -->
            <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
                <Link v-for="card in cards" :key="card.key" :href="card.href" class="group">
                    <Card class="h-full gap-3 py-4 transition group-hover:border-foreground/20 group-hover:shadow-md sm:gap-6 sm:py-6">
                        <CardHeader class="flex-row items-center justify-between space-y-0 px-4 sm:px-6">
                            <CardTitle class="text-sm font-medium text-muted-foreground">{{ card.label }}</CardTitle>
                            <div class="flex size-8 items-center justify-center rounded-xl sm:size-9" :class="card.tone">
                                <component :is="card.icon" class="size-4 sm:size-4.5" />
                            </div>
                        </CardHeader>
                        <CardContent class="px-4 sm:px-6">
                            <p class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ stats[card.key].total }}</p>
                            <div class="mt-1 flex items-center gap-2 text-xs text-muted-foreground sm:mt-2 sm:text-sm">
                                <span>{{ stats[card.key].active }} active</span>
                                <Badge v-if="stats[card.key].last_30_days" variant="secondary" class="hidden rounded-full sm:inline-flex">
                                    +{{ stats[card.key].last_30_days }} in 30 days
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>
                </Link>

                <Card class="gap-3 py-4 sm:gap-6 sm:py-6">
                    <CardHeader class="flex-row items-center justify-between space-y-0 px-4 sm:px-6">
                        <CardTitle class="text-sm font-medium text-muted-foreground">Messages</CardTitle>
                        <div class="flex size-8 items-center justify-center rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 sm:size-9">
                            <MailOpenIcon class="size-4 sm:size-4.5" />
                        </div>
                    </CardHeader>
                    <CardContent class="px-4 sm:px-6">
                        <p v-if="! canReadMail" class="text-sm text-muted-foreground">Set DOVECOT_MASTER_PASSWORD to count messages.</p>
                        <template v-else-if="totals">
                            <p class="text-2xl font-semibold tracking-tight sm:text-3xl">{{ totals.messages_total }}</p>
                            <p class="mt-1 text-xs text-muted-foreground sm:mt-2 sm:text-sm">{{ totals.unread_total }} unread</p>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">{{ liveError ?? 'Loading...' }}</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Per-mailbox counts -->
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-base">Messages by mailbox</CardTitle>
                        <CardDescription>Inbox and unread from INBOX, total is every folder except Junk and Trash.</CardDescription>
                        <CardAction v-if="canReadMail">
                            <Button variant="ghost" size="sm" class="gap-2" :disabled="liveLoading" title="Refresh" @click="loadLive(true)">
                                <RefreshCwIcon class="size-4" :class="{ 'animate-spin': liveLoading }" />
                                <span class="hidden sm:inline">Refresh</span>
                            </Button>
                        </CardAction>
                    </CardHeader>
                    <CardContent>
                        <p v-if="! canReadMail" class="py-6 text-center text-sm text-muted-foreground">
                            Set <code>DOVECOT_MASTER_PASSWORD</code> in the server config to read message counts.
                        </p>
                        <p v-else-if="liveError && ! live" class="py-6 text-center text-sm text-destructive">{{ liveError }}</p>
                        <p v-else-if="! live" class="py-6 text-center text-sm text-muted-foreground">Reading mailboxes...</p>
                        <p v-else-if="! live.meta.total" class="py-6 text-center text-sm text-muted-foreground">No active mailboxes yet.</p>
                        <div v-else class="divide-y sm:hidden">
                            <div v-for="row in live.mailboxes" :key="row.id" class="flex items-center gap-3 py-3">
                                <MailboxAvatar :email="row.email" :name="row.name" size="sm" />
                                <div class="min-w-0 flex-1">
                                    <p class="break-all text-sm font-medium">{{ row.email }}</p>
                                    <p class="mt-0.5 text-xs text-muted-foreground">
                                        {{ row.error ?? `${row.inbox} in inbox · ${row.total} total` }}
                                    </p>
                                </div>
                                <span
                                    v-if="! row.error && row.unread > 0"
                                    class="inline-flex min-w-6 shrink-0 items-center justify-center rounded-full bg-rose-500 px-2 py-0.5 text-xs font-semibold tabular-nums text-white"
                                    title="Unread"
                                >{{ row.unread }}</span>
                                <Button variant="outline" size="icon" class="shrink-0" title="Open this mailbox in webmail" @click="openWebmail(row)">
                                    <InboxIcon class="size-4" />
                                </Button>
                            </div>
                        </div>
                        <Table v-if="live && live.mailboxes.length" class="hidden sm:table">
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Mailbox</TableHead>
                                    <TableHead class="text-right">Inbox</TableHead>
                                    <TableHead class="text-right">Unread</TableHead>
                                    <TableHead class="text-right">Total</TableHead>
                                    <TableHead class="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow v-for="row in live.mailboxes" :key="row.id">
                                    <TableCell>
                                        <div class="flex items-center gap-3">
                                            <MailboxAvatar :email="row.email" :name="row.name" size="sm" />
                                            <div class="min-w-0">
                                                <p class="font-medium">{{ row.email }}</p>
                                                <p v-if="row.name" class="text-xs text-muted-foreground">{{ row.name }}</p>
                                            </div>
                                        </div>
                                    </TableCell>
                                    <template v-if="row.error">
                                        <TableCell colspan="3" class="text-right text-xs text-muted-foreground">{{ row.error }}</TableCell>
                                    </template>
                                    <template v-else>
                                        <TableCell class="text-right tabular-nums">{{ row.inbox }}</TableCell>
                                        <TableCell class="text-right">
                                            <span
                                                v-if="row.unread > 0"
                                                class="inline-flex min-w-6 items-center justify-center rounded-full bg-rose-500 px-2 py-0.5 text-xs font-semibold tabular-nums text-white"
                                            >{{ row.unread }}</span>
                                            <span v-else class="text-muted-foreground">0</span>
                                        </TableCell>
                                        <TableCell class="text-right tabular-nums">{{ row.total }}</TableCell>
                                    </template>
                                    <TableCell class="text-right">
                                        <Button variant="outline" size="sm" class="gap-1.5" title="Open this mailbox in webmail" @click="openWebmail(row)">
                                            <InboxIcon class="size-4" />
                                            Open
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                        <PagerBar v-if="live" :meta="live.meta" @page="goMailboxPage" @size="setMailboxSize" />
                    </CardContent>
                </Card>

                <Card class="h-full">
                    <CardHeader class="space-y-0">
                        <CardTitle class="text-base">Needs attention</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p v-if="! alerts.length" class="flex items-center gap-2 text-sm text-muted-foreground">
                            <CircleCheckIcon class="size-4 text-emerald-600 dark:text-emerald-400" />
                            Nothing to flag.
                        </p>
                        <ul v-else class="space-y-2">
                            <li v-for="a in alerts" :key="a.text">
                                <Link :href="a.href" class="flex items-start gap-2 text-sm hover:underline" :class="a.tone">
                                    <component :is="a.tone === 'text-muted-foreground' ? ClockIcon : TriangleAlertIcon" class="mt-0.5 size-4 shrink-0" />
                                    <span class="min-w-0 break-words">{{ a.text }}</span>
                                </Link>
                            </li>
                        </ul>
                    </CardContent>
                </Card>
            </div>

            <!-- Growth -->
            <div>
                <h3 class="mb-3 text-sm font-medium text-muted-foreground">Growth, last 12 months</h3>
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Card v-for="c in growthCharts" :key="c.key">
                        <CardHeader class="space-y-0 pb-2">
                            <CardTitle class="text-sm font-medium">{{ c.title }}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <BarChart :items="c.items" :height="110" :bar-class="c.bar" :label-every="2" />
                        </CardContent>
                    </Card>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Mailboxes per domain</CardTitle>
                        <CardDescription>Top 10 by mailbox count</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-2.5">
                        <p v-if="! topDomains.length" class="text-sm text-muted-foreground">No domains yet.</p>
                        <div v-for="d in topDomains" :key="d.name" class="flex items-center gap-3 text-sm">
                            <span class="w-28 shrink-0 truncate sm:w-40" :title="d.name">{{ d.name }}</span>
                            <div class="h-2 flex-1 rounded-full bg-muted">
                                <div class="h-2 rounded-full bg-blue-500" :style="{ width: `${(d.mailboxes / maxMailboxes) * 100}%` }" />
                            </div>
                            <span class="w-8 shrink-0 text-right tabular-nums text-muted-foreground">{{ d.mailboxes }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Scheduled sending</CardTitle>
                        <CardDescription>Sent per day over the last 30 days</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex flex-wrap gap-2 text-sm">
                            <Badge variant="secondary">pending {{ status.pending ?? 0 }}</Badge>
                            <Badge variant="secondary">sent {{ status.sent ?? 0 }}</Badge>
                            <Badge :variant="(status.failed ?? 0) > 0 ? 'destructive' : 'secondary'">failed {{ status.failed ?? 0 }}</Badge>
                        </div>
                        <BarChart :items="sentDaily" :height="90" :label-every="5" bar-class="bg-sky-500" />
                    </CardContent>
                </Card>
            </div>

            <!-- Domain report -->
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Domain report</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="divide-y sm:hidden">
                        <p v-if="! domains.data.length" class="py-6 text-center text-sm text-muted-foreground">No domains yet.</p>
                        <div v-for="d in domains.data" :key="d.name" class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <p class="break-all text-sm font-medium">{{ d.name }}</p>
                                <Badge class="shrink-0" :variant="d.is_active ? 'secondary' : 'outline'">{{ d.is_active ? 'active' : 'inactive' }}</Badge>
                            </div>
                            <p class="mt-1 text-xs text-muted-foreground">
                                {{ d.mailboxes }} mailboxes · {{ d.aliases }} aliases · {{ formatQuota(d.quota_mb) }}
                                <span v-if="! d.has_dkim" class="text-amber-600 dark:text-amber-400"> · DKIM missing</span>
                            </p>
                        </div>
                    </div>
                    <Table class="hidden sm:table">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Domain</TableHead>
                                <TableHead class="text-right">Mailboxes</TableHead>
                                <TableHead class="text-right">Aliases</TableHead>
                                <TableHead class="text-right">Quota allocated</TableHead>
                                <TableHead>DKIM</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="d in domains.data" :key="d.name">
                                <TableCell class="font-medium">{{ d.name }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.mailboxes }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.aliases }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ formatQuota(d.quota_mb) }}</TableCell>
                                <TableCell>{{ d.has_dkim ? 'yes' : 'missing' }}</TableCell>
                                <TableCell>
                                    <Badge :variant="d.is_active ? 'secondary' : 'outline'">{{ d.is_active ? 'active' : 'inactive' }}</Badge>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="! domains.data.length">
                                <TableCell colspan="6" class="py-8 text-center text-muted-foreground">No domains yet.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                    <PagerBar :meta="domains" @page="(p) => visitDomains({ domains_page: p })" @size="(n) => visitDomains({ domains_per_page: n, domains_page: 1 })" />
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
