<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import PasswordResetController from '@/actions/App/Http/Controllers/Auth/PasswordResetController';
import AuthCard from '@/components/AuthCard.vue';
import GoogleButton from '@/components/GoogleButton.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps({
    googleConfigured: Boolean,
    adminConfigured: Boolean,
});

const form = useForm({ email: '', password: '', remember: false });

function submit() {
    form.submit(AuthenticatedSessionController.store(), {
        onFinish: () => form.reset('password'),
    });
}
</script>

<template>
    <Head title="Sign in" />
    <AuthCard subtitle="Sign in to continue">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" autofocus required />
                <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label for="password">Password</Label>
                    <Link :href="PasswordResetController.request.url()" class="text-xs text-muted-foreground underline underline-offset-4">
                        Forgot password?
                    </Link>
                </div>
                <Input id="password" v-model="form.password" type="password" autocomplete="current-password" required />
                <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
            </div>
            <label class="flex items-center gap-2 text-sm">
                <input v-model="form.remember" type="checkbox" class="size-4 rounded border accent-primary" />
                Remember me
            </label>
            <Button type="submit" class="w-full" :disabled="form.processing">Sign in</Button>
        </form>

        <template v-if="googleConfigured && adminConfigured">
            <div class="my-4 flex items-center gap-3 text-xs text-muted-foreground">
                <span class="h-px flex-1 bg-border" />or<span class="h-px flex-1 bg-border" />
            </div>
            <GoogleButton />
        </template>
    </AuthCard>
</template>
