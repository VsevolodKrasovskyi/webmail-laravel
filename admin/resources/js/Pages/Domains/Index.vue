<script setup>
import { Head } from '@inertiajs/vue3';
import DomainController from '@/actions/App/Http/Controllers/DomainController';
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { PlusIcon, Trash2Icon, CloudIcon, CopyIcon, CheckIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AppLayout from '../../Layouts/AppLayout.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import Pagination from '@/components/Pagination.vue';
import SortableHeader from '@/components/SortableHeader.vue';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
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
    per_page: props.filters.per_page,
});

function reload() {
    router.get(DomainController.index(), query.value, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimeout;
watch(() => query.value.search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(reload, 300);
});

watch(() => query.value.status, reload);
// Changing the page size returns to the first page (no `page` is sent).
watch(() => query.value.per_page, reload);

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
    form.submit(DomainController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}

function toggleActive(domain) {
    router.put(DomainController.update(domain.id), { is_active: !domain.is_active }, { preserveScroll: true });
}

const deleteTarget = ref(null);

function confirmDelete() {
    router.delete(DomainController.destroy(deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}

const dnsDomain = ref(null);

// Copy to the clipboard, with a fallback for pages served over plain HTTP.
const copied = ref(null);
let copiedTimer;

async function copy(key, text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        copied.value = key;
        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => (copied.value = null), 1500);
        toast.success('Copied');
    } catch {
        toast.error('Could not copy, select the text and copy it by hand');
    }
}

const dkimName = (d) => `${d.dkim_selector}._domainkey.${d.name}`;
const dkimValue = (d) => `v=DKIM1; k=rsa; p=${d.dkim_public_key}`;
const syncing = ref(false);

function syncDns(domain) {
    syncing.value = true;
    router.post(DomainController.syncDns(domain.id), {}, {
        preserveScroll: true,
        onFinish: () => {
            syncing.value = false;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Domains" />
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

        <div class="mb-4 grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <Input v-model="query.search" placeholder="Search domains..." class="col-span-2 sm:w-64" />
            <Select v-model="query.status">
                <SelectTrigger class="w-full sm:w-40" aria-label="Status">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="all">All statuses</SelectItem>
                    <SelectItem value="active">Active</SelectItem>
                    <SelectItem value="disabled">Disabled</SelectItem>
                </SelectContent>
            </Select>
        </div>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <SortableHeader field="name" :sort="query.sort" :direction="query.direction" @sort="sort">Domain</SortableHeader>
                        <SortableHeader class="hidden sm:table-cell" field="mailboxes_count" :sort="query.sort" :direction="query.direction" @sort="sort">Mailboxes</SortableHeader>
                        <SortableHeader class="hidden sm:table-cell" field="aliases_count" :sort="query.sort" :direction="query.direction" @sort="sort">Aliases</SortableHeader>
                        <TableHead>Status</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="domain in domains.data" :key="domain.id">
                        <TableCell class="whitespace-normal break-all font-medium">
                            <button class="text-left underline decoration-dotted underline-offset-4" @click="dnsDomain = domain">
                                {{ domain.name }}
                            </button>
                            <p class="mt-1 text-xs font-normal text-muted-foreground sm:hidden">
                                {{ domain.mailboxes_count }} mailboxes · {{ domain.aliases_count }} aliases
                            </p>
                        </TableCell>
                        <TableCell class="hidden text-muted-foreground sm:table-cell">{{ domain.mailboxes_count }}</TableCell>
                        <TableCell class="hidden text-muted-foreground sm:table-cell">{{ domain.aliases_count }}</TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <Switch :model-value="domain.is_active" @update:model-value="toggleActive(domain)" />
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
            <Pagination :meta="domains" :sizes="[10, 20, 50, 100]" :per-page="query.per_page" @size="(n) => (query.per_page = n)" />
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
                <div class="space-y-3">
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <Label>TXT record name</Label>
                            <Button type="button" variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs" @click="copy('name', dkimName(dnsDomain))">
                                <component :is="copied === 'name' ? CheckIcon : CopyIcon" class="size-3.5" />
                                {{ copied === 'name' ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <code class="block break-all rounded-md bg-muted p-3 text-xs">{{ dkimName(dnsDomain) }}</code>
                    </div>
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between gap-2">
                            <Label>TXT record value</Label>
                            <Button type="button" variant="ghost" size="sm" class="h-7 gap-1.5 px-2 text-xs" @click="copy('value', dkimValue(dnsDomain))">
                                <component :is="copied === 'value' ? CheckIcon : CopyIcon" class="size-3.5" />
                                {{ copied === 'value' ? 'Copied' : 'Copy' }}
                            </Button>
                        </div>
                        <code class="block max-h-40 overflow-y-auto break-all rounded-md bg-muted p-3 text-xs">{{ dkimValue(dnsDomain) }}</code>
                    </div>
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
