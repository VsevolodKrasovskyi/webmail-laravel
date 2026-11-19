<script setup>
import { onMounted, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { toast } from 'vue-sonner';
import { CopyIcon } from '@lucide/vue';
import TwoFactorController from '@/actions/App/Http/Controllers/Auth/TwoFactorController';
import AuthCard from '@/components/AuthCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps({
    secret: { type: String, default: null },
    otpauthUri: { type: String, default: null },
    recoveryCodes: { type: Array, default: null },
    enabled: { type: Boolean, default: false },
});

const qr = ref(null);
const form = useForm({ code: '' });

onMounted(async () => {
    if (props.otpauthUri) {
        qr.value = await QRCode.toDataURL(props.otpauthUri, { margin: 1, width: 192 });
    }
});

function submit() {
    form.submit(TwoFactorController.confirm(), { onError: () => form.reset('code') });
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(props.recoveryCodes.join('\n'));
        toast.success('Recovery codes copied');
    } catch {
        toast.error('Could not copy, select the codes and copy them by hand');
    }
}

const useRecovery = ref(false);
const disableForm = useForm({ code: '', recovery_code: '' });

function disable() {
    disableForm.transform((data) => (useRecovery.value ? { recovery_code: data.recovery_code } : { code: data.code }))
        .submit(TwoFactorController.disable(), { onError: () => disableForm.reset() });
}

const back = () => router.visit('/settings');
</script>

<template>
    <Head title="Two-factor authentication" />

    <AuthCard v-if="recoveryCodes" title="Save your recovery codes" subtitle="Each code works once if you lose your phone. They are not shown again.">
        <div class="grid grid-cols-2 gap-2 rounded-md bg-muted p-3 font-mono text-sm">
            <span v-for="code in recoveryCodes" :key="code">{{ code }}</span>
        </div>
        <div class="mt-4 space-y-2">
            <Button variant="outline" class="w-full gap-2" @click="copyCodes">
                <CopyIcon class="size-4" />
                Copy the codes
            </Button>
            <Button class="w-full" @click="router.visit('/settings')">I saved them, continue</Button>
        </div>
    </AuthCard>

    <AuthCard v-else-if="enabled" title="Two-factor authentication is on" subtitle="A code from your app is asked at every sign-in">
        <form class="space-y-4" @submit.prevent="disable">
            <p class="text-sm text-muted-foreground">To turn it off, enter a current code or a recovery code.</p>
            <div v-if="! useRecovery" class="space-y-1.5">
                <Label for="off-code">Code</Label>
                <Input id="off-code" v-model="disableForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="text-center text-lg tracking-widest" required />
                <p v-if="disableForm.errors.code" class="text-sm text-destructive">{{ disableForm.errors.code }}</p>
            </div>
            <div v-else class="space-y-1.5">
                <Label for="off-recovery">Recovery code</Label>
                <Input id="off-recovery" v-model="disableForm.recovery_code" autocomplete="off" placeholder="xxxxx-xxxxx" required />
                <p v-if="disableForm.errors.recovery_code" class="text-sm text-destructive">{{ disableForm.errors.recovery_code }}</p>
            </div>
            <Button type="submit" variant="destructive" class="w-full" :disabled="disableForm.processing">Turn off</Button>
            <div class="flex items-center justify-between text-sm">
                <button type="button" class="text-muted-foreground underline underline-offset-4" @click="useRecovery = ! useRecovery; disableForm.reset(); disableForm.clearErrors()">
                    {{ useRecovery ? 'Use the app code' : 'Use a recovery code' }}
                </button>
                <button type="button" class="text-muted-foreground underline underline-offset-4" @click="back">Back</button>
            </div>
        </form>
    </AuthCard>

    <AuthCard v-else title="Set up two-factor authentication" subtitle="Optional, adds a code to every sign-in">
        <ol class="mb-4 list-decimal space-y-1 pl-5 text-sm text-muted-foreground">
            <li>Install an authenticator app (Google Authenticator, 1Password, Authy).</li>
            <li>Scan the QR code, or type the key by hand.</li>
            <li>Enter the 6-digit code it shows.</li>
        </ol>
        <div class="mb-4 flex justify-center">
            <img v-if="qr" :src="qr" alt="QR code for the authenticator app" class="size-48 rounded-md border bg-white" />
            <div v-else class="size-48 animate-pulse rounded-md bg-muted" />
        </div>
        <p class="mb-1 text-xs text-muted-foreground">Key for manual entry</p>
        <code class="mb-4 block break-all rounded-md bg-muted p-2 text-center text-xs tracking-wider">{{ secret }}</code>
        <form class="space-y-4" @submit.prevent="submit">
            <div class="space-y-1.5">
                <Label for="code">Code</Label>
                <Input id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" class="text-center text-lg tracking-widest" autofocus required />
                <p v-if="form.errors.code" class="text-sm text-destructive">{{ form.errors.code }}</p>
            </div>
            <Button type="submit" class="w-full" :disabled="form.processing">Turn on</Button>
            <button type="button" class="block w-full text-center text-sm text-muted-foreground underline underline-offset-4" @click="back">Cancel</button>
        </form>
    </AuthCard>
</template>
