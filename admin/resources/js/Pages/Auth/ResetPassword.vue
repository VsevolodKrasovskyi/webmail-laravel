<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PasswordResetController from '@/actions/App/Http/Controllers/Auth/PasswordResetController';
import AuthCard from '@/components/AuthCard.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    token: String,
    email: String,
});

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });

function submit() {
    form.submit(PasswordResetController.update(), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="New password" />
    <AuthCard title="New password" subtitle="At least 10 characters">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="email">Email</Label>
                <Input id="email" v-model="form.email" type="email" autocomplete="username" required />
                <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
            </div>
            <div class="space-y-1.5">
                <Label for="password">New password</Label>
                <PasswordInput id="password" v-model="form.password" />
                <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
            </div>
            <div class="space-y-1.5">
                <Label for="password_confirmation">Repeat the password</Label>
                <Input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </div>
            <Button type="submit" class="w-full" :disabled="form.processing">Set the password</Button>
        </form>
    </AuthCard>
</template>
