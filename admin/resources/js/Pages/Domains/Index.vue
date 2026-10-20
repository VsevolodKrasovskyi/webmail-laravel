<script setup>
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { PlusIcon, Trash2Icon, CloudIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import Pagination from '@/components/Pagination.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import NativeSelect from '@/components/NativeSelect.vue';
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

const props = defineProps({
    domains: Object,
    cloudflareEnabled: Boolean,
    filters: Object,
});

const query = ref({
    search: props.filters.search,
    status: props.filters.status,
    sort: props.filters.sort,
    direction: props.filters.direction,
});

function reload() {
    router.get('/domains', query.value, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimeout;
watch(() => query.value.search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(reload, 300);
});

watch(() => query.value.status, reload);

function sort(field) {
    if (query.value.sort === field) {
        query.value.direction = query.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        query.value.sort = field;
        query.value.direction = 'asc';
    }
    reload();
}

const createOpen = ref(false);
const form = useForm({ name: '' });

function submit() {
    form.post('/domains', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}

function toggleActive(domain) {
    router.put(`/domains/${domain.id}`, { is_active: !domain.is_active }, { preserveScroll: true });
}

const deleteTarget = ref(null);

function confirmDelete() {
    router.post(`/domains/${deleteTarget.value.id}`, { _method: 'delete' }, {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}

const dnsDomain = ref(null);
const syncing = ref(false);

function syncDns(domain) {
    syncing.value = true;
    router.post(`/domains/${domain.id}/dns`, {}, {
        preserveScroll: true,
        onFinish: () => {
            syncing.value = false;
        },
    });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold">Domains</h2>
            <Dialog v-model:open="createOpen">
                <Button @click="createOpen = true">
                    <PlusIcon class="size-4" />
                    Add domain
                </Button>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add domain</DialogTitle>
                        <DialogDescription>A DKIM keypair is generated automatically.</DialogDescription>
                    </DialogHeader>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="name">Domain</Label>
                            <Input id="name" v-model="form.name" placeholder="example.com" autofocus />
                            <p v-if="form.errors.name" class="text-sm text-destructive">{{ form.errors.name }}</p>
                        </div>
                        <DialogFooter>
                            <Button type="submit" :disabled="form.processing">Add</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>

        <div class="mb-4 flex items-center gap-3">
            <Input v-model="query.search" placeholder="Search domains..." class="w-full sm:max-w-xs" />
            <NativeSelect v-model="query.status" class="w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </NativeSelect>
        </div>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <SortableHeader field="name" :sort="query.sort" :direction="query.direction" @sort="sort">Domain</SortableHeader>
                        <SortableHeader field="mailboxes_count" :sort="query.sort" :direction="query.direction" @sort="sort">Mailboxes</SortableHeader>
                        <SortableHeader field="aliases_count" :sort="query.sort" :direction="query.direction" @sort="sort">Aliases</SortableHeader>
                        <TableHead>Status</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="domain in domains.data" :key="domain.id">
                        <TableCell class="font-medium">
                            <button class="underline decoration-dotted underline-offset-4" @click="dnsDomain = domain">
                                {{ domain.name }}
                            </button>
                        </TableCell>
                        <TableCell class="text-muted-foreground">{{ domain.mailboxes_count }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ domain.aliases_count }}</TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <Switch :model-value="domain.is_active" @update:model-value="toggleActive(domain)" />
                                <Badge :variant="domain.is_active ? 'default' : 'secondary'">
                                    {{ domain.is_active ? 'Active' : 'Disabled' }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="icon-sm" @click="deleteTarget = domain">
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="domains.data.length === 0" :colspan="5">No domains found.</TableEmpty>
                </TableBody>
            </Table>
            <Pagination :meta="domains" />
        </div>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete domain {{ deleteTarget.name }}?</AlertDialogTitle>
                    <AlertDialogDescription>
                        This also deletes all its mailboxes and aliases. This cannot be undone.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <Dialog :open="dnsDomain !== null" @update:open="(v) => !v && (dnsDomain = null)">
            <DialogContent v-if="dnsDomain">
                <DialogHeader>
                    <DialogTitle>{{ dnsDomain.name }} — DNS</DialogTitle>
                    <DialogDescription>DKIM selector: {{ dnsDomain.dkim_selector }}</DialogDescription>
                </DialogHeader>
                <div class="space-y-1.5">
                    <Label>TXT record for {{ dnsDomain.dkim_selector }}._domainkey.{{ dnsDomain.name }}</Label>
                    <code class="block break-all rounded-md bg-muted p-3 text-xs">
                        v=DKIM1; k=rsa; p={{ dnsDomain.dkim_public_key }}
                    </code>
                </div>
                <DialogFooter class="sm:justify-between sm:items-center">
                    <p v-if="! cloudflareEnabled" class="text-xs text-muted-foreground">
                        Set CLOUDFLARE_API_TOKEN to sync these records automatically.
                    </p>
                    <span v-else />
                    <Button :disabled="! cloudflareEnabled || syncing" @click="syncDns(dnsDomain)">
                        <CloudIcon class="size-4" />
                        {{ syncing ? 'Syncing...' : 'Sync DNS via Cloudflare' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
