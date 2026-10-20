<script setup>
import { Link } from '@inertiajs/vue3';
import { GlobeIcon, MailIcon, ArrowRightLeftIcon, PlusIcon, InboxIcon } from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { Card, CardHeader, CardTitle, CardContent, CardFooter } from '@/components/ui/card';
import { Button } from '@/components/ui/button';

defineProps({
    stats: Object,
});

const cards = [
    { key: 'domains', label: 'Domains', icon: GlobeIcon, href: '/domains' },
    { key: 'mailboxes', label: 'Mailboxes', icon: MailIcon, href: '/mailboxes' },
    { key: 'aliases', label: 'Aliases', icon: ArrowRightLeftIcon, href: '/aliases' },
];
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold tracking-tight">Dashboard</h2>
            <p class="mt-1 text-sm text-muted-foreground">An overview of this mail server's domains, mailboxes and aliases.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Card v-for="card in cards" :key="card.key">
                <CardHeader class="flex-row items-center justify-between space-y-0">
                    <CardTitle class="text-sm font-medium text-muted-foreground">{{ card.label }}</CardTitle>
                    <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <component :is="card.icon" class="size-4.5" />
                    </div>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-semibold tracking-tight">{{ stats[card.key].total }}</p>
                    <p class="mt-1 text-sm text-muted-foreground">{{ stats[card.key].active }} active</p>
                </CardContent>
                <CardFooter>
                    <Link :href="card.href" class="w-full">
                        <Button variant="outline" size="sm" class="w-full gap-2">
                            <PlusIcon class="size-4" />
                            Manage {{ card.label.toLowerCase() }}
                        </Button>
                    </Link>
                </CardFooter>
            </Card>
        </div>

        <Card class="mt-6">
            <CardHeader class="flex-row items-center gap-3 space-y-0">
                <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <InboxIcon class="size-4.5" />
                </div>
                <div>
                    <CardTitle class="text-base">Webmail</CardTitle>
                    <p class="text-sm text-muted-foreground">Read and send mail for any mailbox, right from this panel.</p>
                </div>
            </CardHeader>
            <CardFooter>
                <Link href="/webmail">
                    <Button size="sm">Open webmail</Button>
                </Link>
            </CardFooter>
        </Card>
    </AppLayout>
</template>
