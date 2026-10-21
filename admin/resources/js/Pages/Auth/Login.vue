<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { MailIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

defineProps({
    googleConfigured: Boolean,
    adminConfigured: Boolean,
});

const page = usePage();
</script>

<template>
    <Head title="Sign in" />
    <div class="flex min-h-screen items-center justify-center bg-background px-4">
        <Card class="w-full max-w-sm">
            <CardHeader class="items-center text-center">
                <div class="mb-2 flex size-12 items-center justify-center rounded-2xl bg-indigo-600 text-white">
                    <MailIcon class="size-6" />
                </div>
                <CardTitle class="text-lg">Mail Admin</CardTitle>
                <p class="text-sm text-muted-foreground">Sign in with your Google account</p>
            </CardHeader>
            <CardContent>
                <p v-if="page.props.flash?.error" class="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ page.props.flash.error }}
                </p>

                <a v-if="googleConfigured && adminConfigured" href="/auth/google/redirect" class="block">
                    <Button type="button" class="w-full">Sign in with Google</Button>
                </a>
                <p v-else class="rounded-md bg-muted px-3 py-2 text-sm text-muted-foreground">
                    Sign-in is not set up. Set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and ADMIN_EMAIL in the server configuration (see the README).
                </p>
            </CardContent>
        </Card>
    </div>
</template>
