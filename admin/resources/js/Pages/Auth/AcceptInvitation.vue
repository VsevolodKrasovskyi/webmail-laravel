<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AcceptInvitationController from '@/actions/App/Http/Controllers/Auth/AcceptInvitationController';
import AuthCard from '@/components/AuthCard.vue';
import GoogleButton from '@/components/GoogleButton.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    token: String,
    email: String,
    googleConfigured: Boolean,
});

const form = useForm({ name: '', password: '', password_confirmation: '' });

function submit() {
    form.submit(AcceptInvitationController.store(props.token), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <Head title="Accept the invitation" />
    <AuthCard title="Accept the invitation" :subtitle="`Create the account for ${email}`">
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="name">Name</Label>
                <Input id="name" v-model="form.name" autocomplete="name" autofocus required />
                <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
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
            <p class="mt-2 text-center text-xs text-muted-foreground">Use the Google account with {{ email }}.</p>
        </template>
    </AuthCard>
</template>
