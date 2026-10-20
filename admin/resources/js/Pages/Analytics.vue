<script setup>
import { computed } from 'vue';
import { DownloadIcon, CircleCheckIcon, TriangleAlertIcon } from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import BarChart from '@/components/BarChart.vue';
import { Card, CardHeader, CardTitle, CardDescription, CardContent } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Table, TableHeader, TableBody, TableRow, TableHead, TableCell,
} from '@/components/ui/table';

const props = defineProps({
    summary: Object,
    growth: Array,
    domains: Array,
    health: Object,
    scheduled: Object,
});

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

function monthLabel(ym) {
    return MONTHS[Number(ym.slice(5, 7)) - 1];
}

function series(key, noun) {
    return props.growth.map((g) => ({
        label: monthLabel(g.month),
        value: g[key],
        tooltip: `${monthLabel(g.month)} ${g.month.slice(0, 4)}: ${g[key]} new ${noun}`,
    }));
}

const growthCharts = computed(() => [
    { key: 'mailboxes', title: 'New mailboxes', bar: 'bg-emerald-500', items: series('mailboxes', 'mailboxes') },
    { key: 'domains', title: 'New domains', bar: 'bg-indigo-500', items: series('domains', 'domains') },
    { key: 'aliases', title: 'New aliases', bar: 'bg-sky-500', items: series('aliases', 'aliases') },
]);

const sentDaily = computed(() => props.scheduled.daily.map((d) => ({
    label: d.day.slice(8),
    value: d.sent,
    tooltip: `${d.day}: ${d.sent} sent, ${d.failed} failed`,
})));

const topDomains = computed(() => [...props.domains].sort((a, b) => b.mailboxes - a.mailboxes).slice(0, 10));
const maxMailboxes = computed(() => Math.max(1, ...topDomains.value.map((d) => d.mailboxes)));

const status = computed(() => props.scheduled.by_status ?? {});
const scheduledTotal = computed(() => Object.values(status.value).reduce((a, b) => a + Number(b), 0));

const totalGrowth = computed(() => props.growth.reduce((a, g) => a + g.mailboxes + g.domains + g.aliases, 0));

function formatQuota(mb) {
    return mb >= 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb} MB`;
}

const kpis = computed(() => [
    { label: 'Domains', value: props.summary.domains },
    { label: 'Mailboxes', value: props.summary.mailboxes, sub: `${props.summary.inactive_mailboxes} inactive` },
    { label: 'Aliases', value: props.summary.aliases },
    { label: 'Quota allocated', value: formatQuota(props.summary.quota_allocated_mb), sub: `avg ${formatQuota(props.summary.avg_quota_mb)} per mailbox` },
]);

const exports = [
    { type: 'domains', label: 'Domains' },
    { type: 'mailboxes', label: 'Mailboxes' },
    { type: 'scheduled', label: 'Scheduled mail' },
];
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-semibold tracking-tight">Analytics & reports</h2>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Built from the admin database: domains, mailboxes, aliases and scheduled mail.
                        Message traffic and real disk usage are not tracked here.
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a v-for="e in exports" :key="e.type" :href="`/analytics/export/${e.type}`">
                        <Button variant="outline" size="sm" class="gap-2">
                            <DownloadIcon class="size-4" />
                            {{ e.label }} CSV
                        </Button>
                    </a>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Card v-for="k in kpis" :key="k.label">
                    <CardContent class="pt-6">
                        <p class="text-sm text-muted-foreground">{{ k.label }}</p>
                        <p class="mt-1 text-2xl font-semibold tracking-tight">{{ k.value }}</p>
                        <p v-if="k.sub" class="mt-0.5 text-xs text-muted-foreground">{{ k.sub }}</p>
                    </CardContent>
                </Card>
            </div>

            <div>
                <h3 class="mb-3 text-sm font-medium text-muted-foreground">Growth, last 12 months</h3>
                <p v-if="totalGrowth === 0" class="mb-3 text-sm text-muted-foreground">Nothing was created in this period.</p>
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
                                <div class="h-2 rounded-full bg-indigo-500" :style="{ width: `${(d.mailboxes / maxMailboxes) * 100}%` }" />
                            </div>
                            <span class="w-8 shrink-0 text-right tabular-nums text-muted-foreground">{{ d.mailboxes }}</span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Scheduled sending</CardTitle>
                        <CardDescription>{{ scheduledTotal }} message(s) scheduled to date, sent per day over the last 30 days</CardDescription>
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

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Setup health</CardTitle>
                </CardHeader>
                <CardContent class="space-y-2 text-sm">
                    <div v-for="row in [
                        { label: 'Domains without a DKIM key', list: health.without_dkim },
                        { label: 'Domains without mailboxes', list: health.without_mailboxes },
                        { label: 'Inactive domains', list: health.inactive_domains },
                    ]" :key="row.label" class="flex items-start gap-2">
                        <component :is="row.list.length ? TriangleAlertIcon : CircleCheckIcon" class="mt-0.5 size-4 shrink-0" :class="row.list.length ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400'" />
                        <span>
                            {{ row.label }}:
                            <span class="text-muted-foreground">{{ row.list.length ? row.list.join(', ') : 'none' }}</span>
                        </span>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Domain report</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
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
                            <TableRow v-for="d in domains" :key="d.name">
                                <TableCell class="font-medium">{{ d.name }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.mailboxes }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ d.aliases }}</TableCell>
                                <TableCell class="text-right tabular-nums">{{ formatQuota(d.quota_mb) }}</TableCell>
                                <TableCell>{{ d.has_dkim ? 'yes' : 'missing' }}</TableCell>
                                <TableCell>
                                    <Badge :variant="d.is_active ? 'secondary' : 'outline'">{{ d.is_active ? 'active' : 'inactive' }}</Badge>
                                </TableCell>
                            </TableRow>
                            <TableRow v-if="! domains.length">
                                <TableCell colspan="6" class="py-8 text-center text-muted-foreground">No domains yet.</TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
