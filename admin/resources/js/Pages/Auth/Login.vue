<script setup>
import { useForm, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

defineProps({
    googleConfigured: Boolean,
});

const page = usePage();

const form = useForm({
    email: '',
    password: '',
});

function submit() {
    form.post('/login', {
        onError: () => form.reset('password'),
    });
}
</script>

<template>
    <div class="flex min-h-screen items-center justify-center bg-background px-4">
        <Card class="w-full max-w-sm">
            <CardHeader>
                <CardTitle class="text-lg">Sign in</CardTitle>
            </CardHeader>
            <CardContent>
                <p v-if="page.props.flash?.error" class="mb-4 rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive">
                    {{ page.props.flash.error }}
                </p>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="email">Email</Label>
                        <Input id="email" v-model="form.email" type="email" autofocus />
                        <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="password">Password</Label>
                        <Input id="password" v-model="form.password" type="password" />
                        <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
                    </div>
                    <Button type="submit" class="w-full" :disabled="form.processing">Sign in</Button>
                </form>

                <template v-if="googleConfigured">
                    <div class="my-4 flex items-center gap-3">
                        <Separator class="flex-1" />
                        <span class="text-xs text-muted-foreground">or</span>
                        <Separator class="flex-1" />
                    </div>
                    <a href="/auth/google/redirect" class="block">
                        <Button type="button" variant="outline" class="w-full">Sign in with Google</Button>
                    </a>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
