<script setup>
import { watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    LayoutDashboardIcon,
    GlobeIcon,
    MailIcon,
    ArrowRightLeftIcon,
    InboxIcon,
    LogOutIcon,
    SettingsIcon,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu, DropdownMenuTrigger, DropdownMenuContent, DropdownMenuLabel,
    DropdownMenuSeparator, DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import Sonner from '@/components/ui/sonner/Sonner.vue';

const page = usePage();

const nav = [
    { href: '/dashboard', label: 'Dashboard', icon: LayoutDashboardIcon },
    { href: '/domains', label: 'Domains', icon: GlobeIcon },
    { href: '/mailboxes', label: 'Mailboxes', icon: MailIcon },
    { href: '/aliases', label: 'Aliases', icon: ArrowRightLeftIcon },
    { href: '/webmail', label: 'Webmail', icon: InboxIcon },
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

function logout() {
    router.post('/logout');
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
        <Sonner position="top-right" rich-colors />

        <header class="sticky top-0 z-40 border-b bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/60">
            <div class="flex h-14 items-center gap-6 px-6">
                <span class="text-base font-semibold">Mail Admin</span>

                <nav class="flex flex-1 items-center gap-1">
                    <Link v-for="item in nav" :key="item.href" :href="item.href">
                        <Button
                            variant="ghost"
                            size="sm"
                            class="gap-2"
                            :class="{ 'bg-accent text-accent-foreground': page.url.startsWith(item.href) }"
                        >
                            <component :is="item.icon" class="size-4" />
                            {{ item.label }}
                        </Button>
                    </Link>
                </nav>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button class="rounded-full outline-none ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2">
                            <Avatar class="size-8">
                                <AvatarFallback class="bg-primary text-xs text-primary-foreground">
                                    {{ initials(page.props.auth.user?.email) }}
                                </AvatarFallback>
                            </Avatar>
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-56">
                        <DropdownMenuLabel class="truncate font-normal text-muted-foreground">
                            {{ page.props.auth.user?.email }}
                        </DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <Link href="/settings">
                            <DropdownMenuItem class="gap-2">
                                <SettingsIcon class="size-4" />
                                Settings
                            </DropdownMenuItem>
                        </Link>
                        <DropdownMenuItem class="gap-2" @click="logout">
                            <LogOutIcon class="size-4" />
                            Log out
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>

        <main class="px-6 py-8">
            <slot />
        </main>
    </div>
</template>
