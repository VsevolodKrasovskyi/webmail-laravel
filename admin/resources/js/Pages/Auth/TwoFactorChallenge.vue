<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AuthenticatedSessionController from '@/actions/App/Http/Controllers/Auth/AuthenticatedSessionController';
import TwoFactorController from '@/actions/App/Http/Controllers/Auth/TwoFactorController';
import AuthCard from '@/components/AuthCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

function submit() {
    // Only the field in use is sent.
    form.transform((data) => (useRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .submit(TwoFactorController.verify(), { onError: () => form.reset('code', 'recovery_code') });
}

function toggle() {
    useRecovery.value = ! useRecovery.value;
    form.reset();
    form.clearErrors();
}

function logout() {
    router.post(AuthenticatedSessionController.destroy());
}
</script>

<template>
    <Head title="Two-factor code" />
    <AuthCard
        title="Two-factor code"
        :subtitle="useRecovery ? 'Enter one of your recovery codes' : 'Enter the 6-digit code from your authenticator app'"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div v-if="! useRecovery" class="space-y-1.5">
                <Label for="code">Code</Label>
                <Input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="text-center text-lg tracking-widest" autofocus required />
                <p v-if="form.errors.code" class="text-sm text-destructive">{{ form.errors.code }}</p>
            </div>
            <div v-else class="space-y-1.5">
                <Label for="recovery">Recovery code</Label>
                <Input id="recovery" v-model="form.recovery_code" autocomplete="off" placeholder="xxxxx-xxxxx" autofocus required />
                <p v-if="form.errors.recovery_code" class="text-sm text-destructive">{{ form.errors.recovery_code }}</p>
            </div>
            <Button type="submit" class="w-full" :disabled="form.processing">Continue</Button>
            <div class="flex items-center justify-between text-sm">
                <button type="button" class="text-muted-foreground underline underline-offset-4" @click="toggle">
                    {{ useRecovery ? 'Use the app code' : 'Use a recovery code' }}
                </button>
                <button type="button" class="text-muted-foreground underline underline-offset-4" @click="logout">Log out</button>
            </div>
        </form>
    </AuthCard>
</template>
