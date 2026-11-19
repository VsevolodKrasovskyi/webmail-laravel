<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import RegisterController from '@/actions/App/Http/Controllers/Auth/RegisterController';
import AuthCard from '@/components/AuthCard.vue';
import GoogleButton from '@/components/GoogleButton.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps({
    googleConfigured: Boolean,
});

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

function submit() {
    form.submit(RegisterController.store(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Create the administrator" />
    <AuthCard title="Create the administrator" subtitle="First run: this account gets full access. Other users join by invitation.">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" autocomplete="name" autofocus required />
                <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
            </div>
            <div class="space-y-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" required />
                <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <div class="space-y-1.5">
                <Label for="password">Password</Label>
                <PasswordInput id="password" v-model="form.password" />
                <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
            </div>
            <div class="space-y-1.5">
                <Label for="password_confirmation">Repeat the password</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <Button type="submit" class="w-full" :disabled="form.processing">Create the account</Button>
        </form>

        <template v-if="googleConfigured">
            <div class="my-4 flex items-center gap-3 text-xs text-muted-foreground">
                <span class="h-px flex-1 bg-border" />or<span class="h-px flex-1 bg-border" />
            </div>
            <GoogleButton label="Continue with Google" />
        </template>
    </AuthCard>
</template>
