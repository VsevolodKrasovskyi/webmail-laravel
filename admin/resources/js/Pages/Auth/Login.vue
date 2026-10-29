<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import GoogleAuthController from '@/actions/App/Http/Controllers/Auth/GoogleAuthController';
import { MailIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';

defineProps({
    googleConfigured: Boolean,
    adminConfigured: Boolean,
});

const page = usePage();
</script>

<template>
    <Head title="Sign in" />
    <div class="flex min-h-screen items-center justify-center bg-background px-4">
        <Card class="mx-auto w-full max-w-sm">
            <div class="flex flex-col items-center px-6 pt-6 text-center">
                <div class="mb-3 flex size-12 items-center justify-center rounded-2xl bg-blue-600 text-white">
                    <MailIcon class="size-6" />
                </div>
                <h1 class="text-lg font-semibold leading-none">Mail Admin</h1>
                <p class="mb-6 mt-2 text-sm text-muted-foreground">Sign in with your Google account</p>
            </div>
            <CardContent>
                <p v-if="page.props.flash?.error" class="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ page.props.flash.error }}
                </p>

                <a v-if="googleConfigured && adminConfigured" :href="GoogleAuthController.redirect.url()" class="block">
                    <Button type="button" class="w-full">Sign in with Google</Button>
                </a>
            </CardContent>
        </Card>
    </div>
</template>
