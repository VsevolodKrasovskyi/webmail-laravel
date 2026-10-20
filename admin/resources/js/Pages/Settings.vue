<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { UserIcon, KeyRoundIcon } from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const page = usePage();

const profileForm = useForm({
    name: page.props.auth.user?.name ?? '',
    email: page.props.auth.user?.email ?? '',
});

function saveProfile() {
    profileForm.put('/settings/profile', { preserveScroll: true });
}

const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

function savePassword() {
    passwordForm.put('/settings/password', {
        preserveScroll: true,
        onSuccess: () => passwordForm.reset(),
    });
}
</script>

<template>
    <AppLayout>
        <div class="mb-8">
            <h2 class="text-2xl font-semibold tracking-tight">Settings</h2>
            <p class="mt-1 text-sm text-muted-foreground">Manage your admin account.</p>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <Card>
                <CardHeader class="flex-row items-center gap-3 space-y-0">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <UserIcon class="size-4.5" />
                    </div>
                    <div>
                        <CardTitle class="text-base">Profile</CardTitle>
                        <p class="text-sm text-muted-foreground">Your name and the email you sign in with.</p>
                    </div>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="saveProfile" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="profileForm.name" type="text" />
                            <p v-if="profileForm.errors.name" class="text-sm text-destructive">{{ profileForm.errors.name }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="profile-email">Email</Label>
                            <Input id="profile-email" v-model="profileForm.email" type="email" />
                            <p v-if="profileForm.errors.email" class="text-sm text-destructive">{{ profileForm.errors.email }}</p>
                        </div>
                        <Button type="submit" :disabled="profileForm.processing">Save</Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader class="flex-row items-center gap-3 space-y-0">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <KeyRoundIcon class="size-4.5" />
                    </div>
                    <div>
                        <CardTitle class="text-base">Password</CardTitle>
                        <p class="text-sm text-muted-foreground">Change the password used to sign in.</p>
                    </div>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="savePassword" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="current-password">Current password</Label>
                            <Input id="current-password" v-model="passwordForm.current_password" type="password" />
                            <p v-if="passwordForm.errors.current_password" class="text-sm text-destructive">{{ passwordForm.errors.current_password }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="new-password">New password</Label>
                            <Input id="new-password" v-model="passwordForm.password" type="password" />
                            <p v-if="passwordForm.errors.password" class="text-sm text-destructive">{{ passwordForm.errors.password }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="new-password-confirm">Confirm new password</Label>
                            <Input id="new-password-confirm" v-model="passwordForm.password_confirmation" type="password" />
                        </div>
                        <Button type="submit" :disabled="passwordForm.processing">Update password</Button>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
