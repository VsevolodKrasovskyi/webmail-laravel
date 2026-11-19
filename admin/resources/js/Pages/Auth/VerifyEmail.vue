<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import EmailVerificationController from '@/actions/App/Http/Controllers/Auth/EmailVerificationController';
import AuthCard from '@/components/AuthCard.vue';
import { Button } from '@/components/ui/button';

const page = usePage();
const form = useForm({});

function resend() {
    form.submit(EmailVerificationController.send());
}

function logout() {
    router.post(AuthenticatedSessionController.destroy());
}
</script>

<template>
    <Head title="Verify your email" />
    <AuthCard title="Verify your email" :subtitle="`We sent a link to ${page.props.auth.user.email}. Open it to continue.`">
        <div class="space-y-3">
            <Button class="w-full" :disabled="form.processing" @click="resend">Send the link again</Button>
            <Button variant="ghost" class="w-full" @click="logout">Log out</Button>
        </div>
    </AuthCard>
</template>
