<script setup>
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import ApiKeyController from '@/actions/App/Http/Controllers/ApiKeyController';
import { PlusIcon, Trash2Icon, PlugZapIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow,
} from '@/components/ui/table';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription, DialogFooter,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogCancel,
} from '@/components/ui/alert-dialog';

defineProps({
    keys: Array,
});

const when = (iso) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' }) : 'Never');

const createOpen = ref(false);
const form = useForm({ name: '', token: '' });

function submit() {
    form.submit(ApiKeyController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
        // The token is not kept in the form after a refusal.
        onError: () => form.reset('token'),
    });
}

const testing = ref(null);

function test(key) {
    testing.value = key.id;
    router.post(ApiKeyController.test(key.id), {}, {
        preserveScroll: true,
        onFinish: () => (testing.value = null),
    });
}

const deleteTarget = ref(null);

function confirmDelete() {
    router.delete(ApiKeyController.destroy(deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => (deleteTarget.value = null),
    });
}
</script>

<template>
    <AppLayout>
        <Head title="API keys" />
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold">API keys</h2>
                <p class="text-sm text-muted-foreground">Cloudflare accounts for "Sync DNS". Add one key per account, the panel finds the right one for each domain.</p>
            </div>
            <Button @click="createOpen = true">
                <PlusIcon class="size-4" />
                Add Cloudflare key
            </Button>
        </div>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Name</TableHead>
                        <TableHead class="hidden sm:table-cell">Token</TableHead>
                        <TableHead class="hidden md:table-cell">Domains</TableHead>
                        <TableHead class="hidden md:table-cell">Last used</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="key in keys" :key="key.id">
                        <TableCell class="whitespace-normal break-all font-medium">
                            {{ key.name }}
                            <Badge variant="secondary" class="ml-2">Cloudflare</Badge>
                        </TableCell>
                        <TableCell class="hidden font-mono text-muted-foreground sm:table-cell">••••{{ key.token_hint }}</TableCell>
                        <TableCell class="hidden text-muted-foreground md:table-cell">{{ key.domains_count }}</TableCell>
                        <TableCell class="hidden text-muted-foreground md:table-cell">{{ when(key.last_used_at) }}</TableCell>
                        <TableCell class="whitespace-nowrap text-right">
                            <Button variant="ghost" size="icon-sm" title="Check the token" :disabled="testing === key.id" @click="test(key)">
                                <PlugZapIcon class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon-sm" title="Delete" @click="deleteTarget = key">
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="keys.length === 0" :colspan="5">No keys yet. Without one "Sync DNS" is off and DNS is set by hand.</TableEmpty>
                </TableBody>
            </Table>
        </div>

        <Dialog v-model:open="createOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Add Cloudflare key</DialogTitle>
                    <DialogDescription>
                        A custom API token with Zone: Read and DNS: Edit, limited to the zones this stack serves. It is checked with Cloudflare and stored encrypted, it is not shown again.
                    </DialogDescription>
                </DialogHeader>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="space-y-1.5">
                        <Label for="key-name">Name</Label>
                        <Input id="key-name" v-model="form.name" placeholder="Company account" autofocus required />
                        <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="key-token">API token</Label>
                        <Input id="key-token" v-model="form.token" type="password" autocomplete="off" spellcheck="false" required />
                        <p v-if="form.errors.token" class="text-sm text-destructive">{{ form.errors.token }}</p>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="form.processing">{{ form.processing ? 'Checking...' : 'Check and save' }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete the key {{ deleteTarget.name }}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        "Sync DNS" stops working for the domains of this account until another key is added. Records already in Cloudflare stay.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
