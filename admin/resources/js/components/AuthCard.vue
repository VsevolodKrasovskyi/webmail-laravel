<script setup>
import { usePage } from '@inertiajs/vue3';
import { MailIcon } from '@lucide/vue';
import { Card, CardContent } from '@/components/ui/card';

defineProps({
    title: { type: String, default: 'Mail Admin' },
    subtitle: { type: String, default: null },
});

const page = usePage();
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-background px-4 py-8">
        <Card class="mx-auto w-full max-w-sm">
            <div class="flex flex-col items-center px-6 pt-6 text-center">
                <div class="mb-3 flex size-12 items-center justify-center rounded-2xl bg-blue-600 text-white">
                    <MailIcon class="size-6" />
                </div>
                <h1 class="text-lg font-semibold leading-none">{{ title }}</h1>
                <p v-if="subtitle" class="mb-6 mt-2 text-sm text-muted-foreground">{{ subtitle }}</p>
                <div v-else class="mb-6" />
            </div>
            <CardContent>
                <p v-if="page.props.flash?.error" class="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ page.props.flash.error }}
                </p>
                <p v-if="page.props.flash?.status" class="mb-4 rounded-md bg-muted px-3 py-2 text-sm">
                    {{ page.props.flash.status }}
                </p>
                <slot />
            </CardContent>
        </Card>
    </div>
</template>
