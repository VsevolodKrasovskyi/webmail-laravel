<script setup>
import { Head } from '@inertiajs/vue3';
import MailboxController from '@/actions/App/Http/Controllers/MailboxController';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { PlusIcon, Trash2Icon, PencilIcon, InboxIcon } from '@lucide/vue';
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
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogCancel,
} from '@/components/ui/alert-dialog';

const props = defineProps({
    mailboxes: Object,
    domains: Array,
    webmailImpersonationEnabled: Boolean,
    filters: Object,
});

const query = ref({
    search: props.filters.search,
    status: props.filters.status,
    domain: props.filters.domain,
    sort: props.filters.sort,
    direction: props.filters.direction,
});

function reload() {
    router.get(MailboxController.index(), query.value, { preserveState: true, preserveScroll: true, replace: true });
}

let searchTimeout;
watch(() => query.value.search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(reload, 300);
});

watch(() => query.value.status, reload);
watch(() => query.value.domain, reload);

function sort(field) {
    if (query.value.sort === field) {
        query.value.direction = query.value.direction === 'asc' ? 'desc' : 'asc';
    } else {
        query.value.sort = field;
        query.value.direction = 'asc';
    }
    reload();
}

function openWebmail(mailbox) {
    router.post(WebmailController.loginAsMailbox(mailbox.id));
}

const createOpen = ref(false);
const form = useForm({
    email: '',
    password: '',
    name: '',
    quota_mb: 3072,
});

function submit() {
    form.submit(MailboxController.store(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('email', 'password', 'name');
            createOpen.value = false;
        },
    });
}

const editing = ref(null);
const editForm = useForm({
    password: '',
    name: '',
    quota_mb: 3072,
    is_active: true,
});

function startEdit(mailbox) {
    editForm.clearErrors();
    editForm.password = '';
    editForm.name = mailbox.name ?? '';
    editForm.quota_mb = mailbox.quota_mb;
    editForm.is_active = mailbox.is_active;
    editing.value = mailbox;
}

function submitEdit() {
    editForm.submit(MailboxController.update(editing.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editing.value = null;
        },
    });
}

const deleteTarget = ref(null);

function confirmDelete() {
    router.delete(MailboxController.destroy(deleteTarget.value.id), {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <AppLayout>
        <Head title="Mailboxes" />
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold">Mailboxes</h2>
            <Dialog v-model:open="createOpen">
                <Button @click="createOpen = true">
                    <PlusIcon class="size-4" />
                    Add mailbox
                </Button>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add mailbox</DialogTitle>
                    </DialogHeader>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="email">Email</Label>
                            <Input id="email" v-model="form.email" type="email" list="known-domains" placeholder="user@example.com" autofocus />
                            <p v-if="form.errors.email" class="text-sm text-destructive">{{ form.errors.email }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="password">Password</Label>
                            <Input id="password" v-model="form.password" type="text" />
                            <p v-if="form.errors.password" class="text-sm text-destructive">{{ form.errors.password }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="name">Owner name</Label>
                            <Input id="name" v-model="form.name" type="text" />
                        </div>
                        <div class="space-y-1.5">
                            <Label for="quota">Quota, MB</Label>
                            <Input id="quota" v-model.number="form.quota_mb" type="number" min="1" />
                        </div>
                        <DialogFooter>
                            <Button type="submit" :disabled="form.processing">Create</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
            <datalist id="known-domains">
                <option v-for="domain in domains" :key="domain" :value="`@${domain}`"></option>
            </datalist>
        </div>

        <div class="mb-4 grid grid-cols-2 gap-3 sm:flex sm:items-center">
            <Input v-model="query.search" placeholder="Search mailboxes..." class="col-span-2 sm:w-64" />
            <NativeSelect v-model="query.status" class="w-full sm:w-40">
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </NativeSelect>
            <NativeSelect v-model="query.domain" class="w-full sm:w-48">
                <option value="all">All domains</option>
                <option v-for="domain in domains" :key="domain" :value="domain">{{ domain }}</option>
            </NativeSelect>
        </div>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <SortableHeader field="email" :sort="query.sort" :direction="query.direction" @sort="sort">Email</SortableHeader>
                        <TableHead class="hidden md:table-cell">Owner</TableHead>
                        <SortableHeader class="hidden md:table-cell" field="quota_mb" :sort="query.sort" :direction="query.direction" @sort="sort">Quota, MB</SortableHeader>
                        <TableHead class="hidden sm:table-cell">Status</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="mailbox in mailboxes.data" :key="mailbox.id">
                        <TableCell class="whitespace-normal break-all font-medium">
                            {{ mailbox.email }}
                            <!-- Phones: the hidden columns move under the address -->
                            <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs font-normal text-muted-foreground md:hidden">
                                <span v-if="mailbox.name">{{ mailbox.name }}</span>
                                <span>{{ mailbox.quota_mb }} MB</span>
                                <Badge class="sm:hidden" :variant="mailbox.is_active ? 'default' : 'secondary'">
                                    {{ mailbox.is_active ? 'Active' : 'Disabled' }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="hidden text-muted-foreground md:table-cell">{{ mailbox.name || '—' }}</TableCell>
                        <TableCell class="hidden text-muted-foreground md:table-cell">{{ mailbox.quota_mb }}</TableCell>
                        <TableCell class="hidden sm:table-cell">
                            <Badge :variant="mailbox.is_active ? 'default' : 'secondary'">
                                {{ mailbox.is_active ? 'Active' : 'Disabled' }}
                            </Badge>
                        </TableCell>
                        <TableCell class="space-x-0.5 px-2 text-right sm:space-x-1 sm:px-4">
                            <Button
                                v-if="webmailImpersonationEnabled"
                                variant="ghost"
                                size="icon-sm"
                                title="Open webmail"
                                @click="openWebmail(mailbox)"
                            >
                                <InboxIcon class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon-sm" @click="startEdit(mailbox)">
                                <PencilIcon class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon-sm" @click="deleteTarget = mailbox">
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="mailboxes.data.length === 0" :colspan="5">No mailboxes found.</TableEmpty>
                </TableBody>
            </Table>
            <Pagination :meta="mailboxes" />
        </div>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete mailbox {{ deleteTarget.email }}?</AlertDialogTitle>
                    <AlertDialogDescription>This cannot be undone.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <Dialog :open="editing !== null" @update:open="(v) => !v && (editing = null)">
            <DialogContent v-if="editing">
                <DialogHeader>
                    <DialogTitle>{{ editing.email }}</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="submitEdit" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="edit-password">New password</Label>
                        <Input id="edit-password" v-model="editForm.password" type="text" placeholder="Leave empty to keep current" />
                        <p v-if="editForm.errors.password" class="text-sm text-destructive">{{ editForm.errors.password }}</p>
                    </div>
                    <div class="space-y-1.5">
                        <Label for="edit-name">Owner name</Label>
                        <Input id="edit-name" v-model="editForm.name" type="text" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="edit-quota">Quota, MB</Label>
                        <Input id="edit-quota" v-model.number="editForm.quota_mb" type="number" min="1" />
                    </div>
                    <div class="flex items-center gap-2">
                        <Switch v-model="editForm.is_active" />
                        <Label>Active</Label>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="editForm.processing">Save</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
