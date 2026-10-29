<script setup>
import { watch } from 'vue';
import DashboardController from '@/actions/App/Http/Controllers/DashboardController';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import MailboxController from '@/actions/App/Http/Controllers/MailboxController';
import AliasController from '@/actions/App/Http/Controllers/AliasController';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import { Link, usePage, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    LayoutDashboardIcon,
    GlobeIcon,
    MailIcon,
    ArrowRightLeftIcon,
    LogOutIcon,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuLabel,
    DropdownMenuSeparator, DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import Sonner from '@/components/ui/sonner/Sonner.vue';

const page = usePage();

const nav = [
    { href: DashboardController.url(), label: 'Dashboard', icon: LayoutDashboardIcon },
    { href: DomainController.index.url(), label: 'Domains', icon: GlobeIcon },
    // Webmail is opened from the Mailboxes list, so it keeps this item highlighted.
    { href: MailboxController.index.url(), also: [WebmailController.index.url()], label: 'Mailboxes', icon: MailIcon },
    { href: AliasController.index.url(), label: 'Aliases', icon: ArrowRightLeftIcon },
];

watch(
    () => page.props.flash?.success,
    (message) => {
        if (message) {
            toast.success(message);
        }
    },
);

watch(
    () => page.props.flash?.error,
    (message) => {
        if (message) {
            toast.error(message);
        }
    },
);

function isActive(item) {
    return [item.href, ...(item.also ?? [])].some((href) => (
        page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`)
    ));
}

function logout() {
    router.post(AuthenticatedSessionController.destroy());
}

function initials(email) {
    if (! email) {
        return '?';
    }
    return email.slice(0, 2).toUpperCase();
}
</script>

<template>
    <div class="min-h-screen bg-background text-foreground">
        <!-- Plain (uncolored) toasts in the bottom right. On phones they sit above the bottom tab bar. -->
        <Sonner position="bottom-right" :mobile-offset="{ bottom: '5.5rem' }" />

        <!-- Top bar: brand and account on phones, brand, navigation and account from md up -->
        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/60">
            <div class="flex h-14 items-center gap-3 px-4 sm:px-6 md:gap-6">
                <Link :href="DashboardController.url()" class="flex shrink-0 items-center gap-2.5">
                    <span class="flex size-8 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <MailIcon class="size-4.5" />
                    </span>
                    <span class="text-base font-semibold">Mail Admin</span>
                </Link>

                <nav class="hidden min-w-0 flex-1 items-center gap-1 md:flex">
                    <Link v-for="item in nav" :key="item.href" :href="item.href" class="shrink-0">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="gap-2"
                            :class="{ 'bg-accent text-accent-foreground': isActive(item) }"
                        >
                            <component :is="item.icon" class="size-4" />
                            {{ item.label }}
                        </Button>
                    </Link>
                </nav>
                <div class="flex-1 md:hidden" />

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button class="rounded-full outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2" aria-label="Account">
                            <Avatar class="size-9">
                                <AvatarImage v-if="page.props.auth.user?.avatar" :src="page.props.auth.user.avatar" referrer-policy="no-referrer" alt="" />
                                <AvatarFallback class="bg-primary text-xs text-primary-foreground">
                                    {{ initials(page.props.auth.user?.email) }}
                                </AvatarFallback>
                            </Avatar>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-64">
                        <DropdownMenuLabel class="font-normal">
                            <span class="block truncate text-sm font-medium text-foreground">{{ page.props.auth.user?.name }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ page.props.auth.user?.email }}</span>
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem class="gap-2 py-2.5" @click="logout">
                            <LogOutIcon class="size-4" />
                            Log out
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <main class="px-3 py-4 pb-24 sm:px-6 sm:py-8 md:pb-8">
            <slot />
        </main>

        <!-- Phones: main navigation as a bottom tab bar, within thumb reach -->
        <nav
            class="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur supports-backdrop-filter:bg-background/80 md:hidden"
            aria-label="Main"
        >
            <div class="grid" :style="{ gridTemplateColumns: `repeat(${nav.length}, minmax(0, 1fr))` }">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="flex flex-col items-center gap-1 px-1 pb-2 pt-2.5 text-[11px] font-medium transition-colors"
                    :class="isActive(item) ? 'text-primary' : 'text-muted-foreground'"
                    :aria-current="isActive(item) ? 'page' : undefined"
                >
                    <span
                        class="flex h-7 w-12 items-center justify-center rounded-full transition-colors"
                        :class="isActive(item) ? 'bg-accent' : ''"
                    >
                        <component :is="item.icon" class="size-5" />
                    </span>
                    {{ item.label }}
                </Link>
            </div>
        </nav>
    </div>
</template>
