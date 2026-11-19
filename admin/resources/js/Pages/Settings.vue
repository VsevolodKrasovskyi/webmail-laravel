<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import SettingsController from '@/actions/App/Http/Controllers/SettingsController';
import TwoFactorController from '@/actions/App/Http/Controllers/Auth/TwoFactorController';
import { ShieldCheckIcon, ShieldIcon } from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const props = defineProps({
    profile: Object,
    hasPassword: Boolean,
    twoFactor: Boolean,
});

const profileForm = useForm({ name: props.profile.name });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });

function saveProfile() {
    profileForm.submit(SettingsController.updateProfile(), { preserveScroll: true });
}

function savePassword() {
    passwordForm.submit(SettingsController.updatePassword(), {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
        onError: () => passwordForm.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Settings" />
        <h2 class="mb-6 text-xl font-semibold">Settings</h2>

        <div class="mx-auto max-w-2xl space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Profile</CardTitle>
                    <CardDescription>{{ profile.email }}</CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="saveProfile">
                        <div class="space-y-1.5">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="profileForm.name" autocomplete="name" required />
                            <p v-if="profileForm.errors.name" class="text-sm text-destructive">{{ profileForm.errors.name }}</p>
                        </div>
                        <Button type="submit" :disabled="profileForm.processing || ! profileForm.isDirty">Save</Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Password</CardTitle>
                    <CardDescription>
                        {{ hasPassword ? 'Other devices are signed out when you change it.' : 'You sign in with Google. Set a password to sign in with the e-mail as well.' }}
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <form class="space-y-4" @submit.prevent="savePassword">
                        <div v-if="hasPassword" class="space-y-1.5">
                            <Label for="current">Current password</Label>
                            <Input id="current" v-model="passwordForm.current_password" type="password" autocomplete="current-password" required />
                            <p v-if="passwordForm.errors.current_password" class="text-sm text-destructive">{{ passwordForm.errors.current_password }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="new">New password</Label>
                            <Input id="new" v-model="passwordForm.password" type="password" autocomplete="new-password" required />
                            <p v-if="passwordForm.errors.password" class="text-sm text-destructive">{{ passwordForm.errors.password }}</p>
                            <p v-else class="text-xs text-muted-foreground">At least 10 characters.</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="confirm">Repeat the new password</Label>
                            <Input id="confirm" v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" required />
                        </div>
                        <Button type="submit" :disabled="passwordForm.processing">{{ hasPassword ? 'Change the password' : 'Set the password' }}</Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        Two-factor authentication
                        <Badge :variant="twoFactor ? 'secondary' : 'outline'">{{ twoFactor ? 'On' : 'Off' }}</Badge>
                    </CardTitle>
                    <CardDescription>
                        An authenticator app adds a 6-digit code to every sign-in, with a password and with Google.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Button as-child :variant="twoFactor ? 'outline' : 'default'">
                        <Link :href="TwoFactorController.setup.url()">
                            <component :is="twoFactor ? ShieldCheckIcon : ShieldIcon" class="size-4" />
                            {{ twoFactor ? 'Manage' : 'Turn on' }}
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
