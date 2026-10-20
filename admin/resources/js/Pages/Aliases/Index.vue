<script setup>
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { PlusIcon, Trash2Icon } from '@lucide/vue';
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
    aliases: Object,
    filters: Object,
});

const query = ref({
    search: props.filters.search,
    status: props.filters.status,
    sort: props.filters.sort,
    direction: props.filters.direction,
});

function reload() {
    router.get('/aliases', query.value, { preserveState: true, preserveScroll: true, replace: true });
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
const form = useForm({
    source: '',
    destination: '',
});

function submit() {
    form.post('/aliases', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            createOpen.value = false;
        },
    });
}

function toggleActive(alias) {
    router.put(
        `/aliases/${alias.id}`,
        { destination: alias.destination, is_active: !alias.is_active },
        { preserveScroll: true },
    );
}

const deleteTarget = ref(null);

function confirmDelete() {
    router.post(`/aliases/${deleteTarget.value.id}`, { _method: 'delete' }, {
        preserveScroll: true,
        onFinish: () => {
            deleteTarget.value = null;
        },
    });
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-xl font-semibold">Aliases</h2>
            <Dialog v-model:open="createOpen">
                <Button @click="createOpen = true">
                    <PlusIcon class="size-4" />
                    Add alias
                </Button>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Add alias</DialogTitle>
                    </DialogHeader>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-1.5">
                            <Label for="source">Source</Label>
                            <Input id="source" v-model="form.source" placeholder="info@example.com or @example.com" autofocus />
                            <p v-if="form.errors.source" class="text-sm text-destructive">{{ form.errors.source }}</p>
                        </div>
                        <div class="space-y-1.5">
                            <Label for="destination">Destination</Label>
                            <Input id="destination" v-model="form.destination" type="email" placeholder="user@example.com" />
                            <p v-if="form.errors.destination" class="text-sm text-destructive">{{ form.errors.destination }}</p>
                        </div>
                        <DialogFooter>
                            <Button type="submit" :disabled="form.processing">Add</Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>

        <div class="mb-4 flex items-center gap-3">
            <Input v-model="query.search" placeholder="Search aliases..." class="w-full sm:max-w-xs" />
            <select
                v-model="query.status"
                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
            >
                <option value="all">All statuses</option>
                <option value="active">Active</option>
                <option value="disabled">Disabled</option>
            </select>
        </div>

        <div class="rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow>
                        <SortableHeader field="source" :sort="query.sort" :direction="query.direction" @sort="sort">Source</SortableHeader>
                        <SortableHeader field="destination" :sort="query.sort" :direction="query.direction" @sort="sort">Destination</SortableHeader>
                        <TableHead>Status</TableHead>
                        <TableHead></TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="alias in aliases.data" :key="alias.id">
                        <TableCell class="font-medium">{{ alias.source }}</TableCell>
                        <TableCell class="text-muted-foreground">{{ alias.destination }}</TableCell>
                        <TableCell>
                            <div class="flex items-center gap-2">
                                <Switch :model-value="alias.is_active" @update:model-value="toggleActive(alias)" />
                                <Badge :variant="alias.is_active ? 'default' : 'secondary'">
                                    {{ alias.is_active ? 'Active' : 'Disabled' }}
                                </Badge>
                            </div>
                        </TableCell>
                        <TableCell class="text-right">
                            <Button variant="ghost" size="icon-sm" @click="deleteTarget = alias">
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </TableCell>
                    </TableRow>
                    <TableEmpty v-if="aliases.data.length === 0" :colspan="4">No aliases found.</TableEmpty>
                </TableBody>
            </Table>
            <Pagination :meta="aliases" />
        </div>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete alias {{ deleteTarget.source }}?</AlertDialogTitle>
                    <AlertDialogDescription>This cannot be undone.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" @click="confirmDelete">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
