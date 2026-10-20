<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import {
    GlobeIcon, MailIcon, ArrowRightLeftIcon, InboxIcon, PenSquareIcon, ChartColumnIcon,
    TriangleAlertIcon, ClockIcon, ArrowUpRightIcon, HardDriveIcon,
} from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';

const props = defineProps({
    stats: Object,
    quota_allocated_mb: Number,
    recent_mailboxes: Array,
    attention: Object,
});

const page = usePage();

const cards = [
    { key: 'domains', label: 'Domains', icon: GlobeIcon, href: '/domains', tone: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' },
    { key: 'mailboxes', label: 'Mailboxes', icon: MailIcon, href: '/mailboxes', tone: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    { key: 'aliases', label: 'Aliases', icon: ArrowRightLeftIcon, href: '/aliases', tone: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
];

const actions = [
    { href: '/webmail/compose', label: 'Compose message', icon: PenSquareIcon },
    { href: '/webmail', label: 'Open webmail', icon: InboxIcon },
    { href: '/mailboxes', label: 'Manage mailboxes', icon: MailIcon },
    { href: '/analytics', label: 'Analytics & reports', icon: ChartColumnIcon },
];

const alerts = computed(() => [
    { show: props.attention.scheduled_failed > 0, tone: 'text-destructive', text: `${props.attention.scheduled_failed} scheduled message(s) failed to send`, href: '/webmail' },
    { show: props.attention.domains_without_dkim > 0, tone: 'text-amber-600 dark:text-amber-400', text: `${props.attention.domains_without_dkim} domain(s) without a DKIM key`, href: '/domains' },
    { show: props.attention.inactive_mailboxes > 0, tone: 'text-muted-foreground', text: `${props.attention.inactive_mailboxes} inactive mailbox(es)`, href: '/mailboxes' },
    { show: props.attention.scheduled_pending > 0, tone: 'text-muted-foreground', text: `${props.attention.scheduled_pending} message(s) waiting to be sent`, href: '/webmail' },
].filter((a) => a.show));

function formatQuota(mb) {
    return mb >= 1024 ? `${(mb / 1024).toFixed(mb >= 10240 ? 0 : 1)} GB` : `${mb} MB`;
}
</script>

<template>
    <AppLayout>
        <div class="mx-auto max-w-6xl space-y-6">
            <div class="relative overflow-hidden rounded-2xl border bg-gradient-to-br from-indigo-500/15 via-sky-500/10 to-transparent p-6 sm:p-8">
                <div class="relative flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h2 class="text-2xl font-semibold tracking-tight">Welcome back</h2>
                        <p class="mt-1 text-sm text-muted-foreground">{{ page.props.auth.user?.email }} · mail server overview</p>
                    </div>
                    <div class="flex items-center gap-2 rounded-full border bg-background/70 px-4 py-2 text-sm backdrop-blur">
                        <HardDriveIcon class="size-4 text-muted-foreground" />
                        <span class="text-muted-foreground">Quota allocated</span>
                        <span class="font-semibold">{{ formatQuota(quota_allocated_mb) }}</span>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Link v-for="card in cards" :key="card.key" :href="card.href" class="group">
                    <Card class="h-full transition group-hover:border-foreground/20 group-hover:shadow-md">
                        <CardHeader class="flex-row items-center justify-between space-y-0">
                            <CardTitle class="text-sm font-medium text-muted-foreground">{{ card.label }}</CardTitle>
                            <div class="flex size-9 items-center justify-center rounded-xl" :class="card.tone">
                                <component :is="card.icon" class="size-4.5" />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p class="text-3xl font-semibold tracking-tight">{{ stats[card.key].total }}</p>
                            <div class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                                <span>{{ stats[card.key].active }} active</span>
                                <Badge v-if="stats[card.key].last_30_days" variant="secondary" class="rounded-full">
                                    +{{ stats[card.key].last_30_days }} in 30 days
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            </div>

            <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="flex-row items-center justify-between space-y-0">
                        <CardTitle class="text-base">Newest mailboxes</CardTitle>
                        <Link href="/mailboxes" class="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground">
                            All mailboxes <ArrowUpRightIcon class="size-3.5" />
                        </Link>
                    </CardHeader>
                    <CardContent class="divide-y">
                        <p v-if="! recent_mailboxes.length" class="py-6 text-center text-sm text-muted-foreground">No mailboxes yet.</p>
                        <div v-for="m in recent_mailboxes" :key="m.id" class="flex items-center gap-3 py-2.5">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-indigo-500/10 text-xs font-semibold uppercase text-indigo-600 dark:text-indigo-400">
                                {{ m.email.slice(0, 2) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ m.email }}</p>
                                <p v-if="m.name" class="truncate text-xs text-muted-foreground">{{ m.name }}</p>
                            </div>
                            <Badge v-if="! m.is_active" variant="secondary">inactive</Badge>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ m.created_at }}</span>
                        </div>
                    </CardContent>
                </Card>

                <div class="space-y-4">
                    <Card>
                        <CardHeader class="space-y-0">
                            <CardTitle class="text-base">Quick actions</CardTitle>
                        </CardHeader>
                        <CardContent class="grid gap-1">
                            <Link
                                v-for="a in actions"
                                :key="a.href"
                                :href="a.href"
                                class="flex items-center gap-3 rounded-lg px-2 py-2 text-sm transition hover:bg-accent"
                            >
                                <component :is="a.icon" class="size-4 text-muted-foreground" />
                                {{ a.label }}
                            </Link>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader class="space-y-0">
                            <CardTitle class="text-base">Needs attention</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p v-if="! alerts.length" class="text-sm text-muted-foreground">Nothing to flag.</p>
                            <ul v-else class="space-y-2">
                                <li v-for="a in alerts" :key="a.text">
                                    <Link :href="a.href" class="flex items-start gap-2 text-sm hover:underline" :class="a.tone">
                                        <component :is="a.tone === 'text-muted-foreground' ? ClockIcon : TriangleAlertIcon" class="mt-0.5 size-4 shrink-0" />
                                        {{ a.text }}
                                    </Link>
                                </li>
                            </ul>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
