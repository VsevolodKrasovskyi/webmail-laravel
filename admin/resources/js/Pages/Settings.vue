<script setup>
import { Head } from '@inertiajs/vue3';
import { useForm, usePage } from '@inertiajs/vue3';
import { UserIcon } from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';

const page = usePage();

const profileForm = useForm({
    name: page.props.auth.user?.name ?? '',
});

const email = page.props.auth.user?.email ?? '';

function saveProfile() {
    profileForm.put('/settings/profile', { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head title="Settings" />
        <div class="mb-8">
            <h2 class="text-2xl font-semibold tracking-tight">Settings</h2>
            <p class="mt-1 text-sm text-muted-foreground">Manage your admin account.</p>
        </div>

        <div class="grid max-w-xl gap-6">
            <Card>
                <CardHeader class="flex-row items-center gap-3 space-y-0">
                    <div class="flex size-9 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <UserIcon class="size-4.5" />
                    </div>
                    <div>
                        <CardTitle class="text-base">Profile</CardTitle>
                        <p class="text-sm text-muted-foreground">Your display name.</p>
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
                            <Input id="profile-email" :model-value="email" type="email" disabled />
                            <p class="text-xs text-muted-foreground">Your Google account. Admins are set with ADMIN_EMAIL in the server config.</p>
                        </div>
                        <Button type="submit" :disabled="profileForm.processing">Save</Button>
                    </form>
                </CardContent>
            </Card>

        </div>
    </AppLayout>
</template>
