<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import PasswordResetController from '@/actions/App/Http/Controllers/Auth/PasswordResetController';
import AuthCard from '@/components/AuthCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const form = useForm({ email: '' });

function submit() {
    form.submit(PasswordResetController.email());
}
</script>

<template>
    <Head title="Forgot password" />
    <AuthCard title="Reset password" subtitle="We send a link to set a new password">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" autofocus required />
                <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <Button type="submit" class="w-full" :disabled="form.processing">Send the link</Button>
            <p class="text-center text-sm">
                <Link :href="AuthenticatedSessionController.create.url()" class="text-muted-foreground underline underline-offset-4">Back to sign in</Link>
            </p>
        </form>
    </AuthCard>
</template>
