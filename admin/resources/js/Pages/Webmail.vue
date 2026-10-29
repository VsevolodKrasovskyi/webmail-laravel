<script setup>
import { Head } from '@inertiajs/vue3';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import { ref, computed, watch, onMounted } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    PenSquareIcon, RefreshCwIcon, LogOutIcon, ReplyIcon, Trash2Icon, FolderPlusIcon, FolderIcon,
    InboxIcon, SendIcon, ShieldAlertIcon, XIcon, SearchIcon, ChevronRightIcon, MailIcon,
    EraserIcon, PaperclipIcon, ArrowLeftIcon, FileTextIcon, ClockIcon, LayersIcon, XCircleIcon,
} from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { fetchJson } from '../fetchJson';
import { sanitizeEmail } from '@/lib/sanitizeEmail';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import NativeSelect from '@/components/NativeSelect.vue';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogCancel,
} from '@/components/ui/alert-dialog';

const props = defineProps({
    connected: Boolean,
    email: String,
});

const FOLDER_ICONS = {
    INBOX: InboxIcon,
    Sent: SendIcon,
    Junk: ShieldAlertIcon,
    Trash: Trash2Icon,
    Drafts: FileTextIcon,
    __SCHEDULED__: ClockIcon,
    __ALL__: LayersIcon,
};

const SCHEDULED = '__SCHEDULED__';
// Folders holding mail we wrote: show who it went to, not ourselves.
const OUTGOING = ['Sent', 'Drafts', SCHEDULED];

function folderIcon(path) {
    return FOLDER_ICONS[path] ?? FolderIcon;
}

const AVATAR_COLORS = [
    'bg-rose-500', 'bg-amber-500', 'bg-emerald-500', 'bg-sky-500', 'bg-blue-500', 'bg-pink-500', 'bg-teal-500',
];

function extractEmail(address) {
    const match = address?.match(/<([^>]+)>/);
    return match ? match[1] : (address ?? '');
}

function senderName(address) {
    const name = address?.match(/^"?([^"<]+?)"?\s*</)?.[1]?.trim();
    return name || extractEmail(address) || '(unknown)';
}

function counterpart(item) {
    return OUTGOING.includes(item.folder) ? item.to : item.from;
}

function counterpartName(item) {
    const address = counterpart(item);
    if (! address && OUTGOING.includes(item.folder)) return '(no recipient)';
    return senderName(address);
}

function itemDate(item) {
    return item.send_at
        ? new Date(item.send_at).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })
        : item.date;
}

function initials(address) {
    const source = senderName(address);
    const parts = source.split(/\s+/).filter(Boolean);
    return parts.length > 1
        ? (parts[0][0] + parts[1][0]).toUpperCase()
        : source.slice(0, 2).toUpperCase();
}

function avatarColor(address) {
    const source = extractEmail(address) || address || '';
    let hash = 0;
    for (let i = 0; i < source.length; i++) {
        hash = (hash * 31 + source.charCodeAt(i)) >>> 0;
    }
    return AVATAR_COLORS[hash % AVATAR_COLORS.length];
}

const loginForm = useForm({ email: '', password: '' });

function login() {
    loginForm.submit(WebmailController.login(), {
        preserveScroll: true,
        onSuccess: () => loginForm.reset('password'),
    });
}

function logout() {
    loginForm.submit(WebmailController.logout());
}

const DEFAULT_FOLDERS = [{ name: 'INBOX', label: 'Inbox', standard: true }];
const folders = ref(DEFAULT_FOLDERS);
const sidebarCollapsed = ref(false);
const currentFolder = ref('INBOX');

async function loadFolders() {
    try {
        const data = await fetchJson(WebmailController.folders());
        folders.value = data.folders.length ? data.folders : DEFAULT_FOLDERS;
    } catch (e) {
        toast.error(e.message);
    }
}

function selectFolder(path) {
    currentFolder.value = path;
    thread.value = null;
    loadMessages();
}

const creatingFolder = ref(false);
const newFolderName = ref('');
const savingFolder = ref(false);

async function createFolder() {
    savingFolder.value = true;
    try {
        await fetchJson(WebmailController.storeFolder(), { body: { name: newFolderName.value } });
        toast.success('Folder created');
        creatingFolder.value = false;
        newFolderName.value = '';
        loadFolders();
    } catch (e) {
        toast.error(e.message);
    } finally {
        savingFolder.value = false;
    }
}

const deleteFolderTarget = ref(null);
const deletingFolder = ref(false);

async function confirmDeleteFolder() {
    const target = deleteFolderTarget.value;
    if (! target) return;
    deletingFolder.value = true;
    try {
        await fetchJson(WebmailController.destroyFolder(), { body: { path: target.name } });
        toast.success('Folder deleted');
        if (currentFolder.value === target.name) {
            selectFolder('INBOX');
        }
        deleteFolderTarget.value = null;
        loadFolders();
    } catch (e) {
        toast.error(e.message);
    } finally {
        deletingFolder.value = false;
    }
}

const emptyingFolder = ref(false);
const confirmingEmpty = ref(false);

async function emptyCurrentFolder() {
    emptyingFolder.value = true;
    try {
        await fetchJson(WebmailController.emptyFolder(), { body: { path: currentFolder.value } });
        toast.success('Folder emptied');
        confirmingEmpty.value = false;
        thread.value = null;
        loadMessages();
    } catch (e) {
        toast.error(e.message);
    } finally {
        emptyingFolder.value = false;
    }
}

const dragItem = ref(null);

function dragStart(item) {
    dragItem.value = item;
}

async function dropOnFolder(targetPath) {
    const item = dragItem.value;
    dragItem.value = null;
    const uid = item?.uid;
    const from = item?.folder ?? currentFolder.value;
    // Virtual folders (All mail, Scheduled) are views, not places to move into or out of.
    if (! uid || targetPath === from || from === SCHEDULED || folders.value.find((f) => f.name === targetPath)?.virtual) {
        return;
    }
    try {
        await fetchJson(WebmailController.moveMessage(uid), {
            body: { from, to: targetPath },
        });
        toast.success('Message moved');
        if (thread.value?.some((m) => m.uid === uid)) {
            thread.value = null;
        }
        loadMessages();
    } catch (e) {
        toast.error(e.message);
    }
}

const messages = ref([]);
const loadingMessages = ref(false);
const search = ref('');
const sortOrder = ref('newest');

let searchTimeout;
watch(search, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(loadMessages, 300);
});

async function loadMessages() {
    loadingMessages.value = true;
    try {
        const query = { folder: currentFolder.value };
        if (search.value) {
            query.search = search.value;
        }
        const data = await fetchJson(WebmailController.messages({ query }));
        messages.value = data.messages;
    } catch (e) {
        toast.error(e.message);
    } finally {
        loadingMessages.value = false;
    }
}

// Group the flat, newest-first message list into threads by References/In-Reply-To.
const threads = computed(() => {
    const byKey = new Map();
    for (const item of messages.value) {
        const key = item.thread_key || `uid:${item.uid}`;
        if (! byKey.has(key)) {
            byKey.set(key, { key, latest: item, count: 1, unread: ! item.seen });
        } else {
            const t = byKey.get(key);
            t.count += 1;
            t.unread = t.unread || ! item.seen;
        }
    }
    const list = Array.from(byKey.values());
    return sortOrder.value === 'oldest' ? list.reverse() : list;
});

const thread = ref(null);
const loadingThread = ref(false);

async function openThread(item) {
    const uid = item.uid;
    const folder = item.folder ?? currentFolder.value;

    if (folder === 'Drafts') {
        router.visit(WebmailController.compose({ query: { draft_uid: uid } }));
        return;
    }

    loadingThread.value = true;
    try {
        const data = await fetchJson(WebmailController.show(uid, { query: { folder } }));
        thread.value = data.messages;
        for (const m of messages.value) {
            if (m.uid === uid && m.folder === folder) {
                m.seen = true;
            }
        }
    } catch (e) {
        toast.error(e.message);
    } finally {
        loadingThread.value = false;
    }
}

async function markUnread(uid, folder) {
    try {
        await fetchJson(WebmailController.markUnread(uid, { query: { folder: folder ?? currentFolder.value } }));
        for (const item of messages.value) {
            if (item.uid === uid && item.folder === (folder ?? currentFolder.value)) {
                item.seen = false;
            }
        }
        thread.value = null;
    } catch (e) {
        toast.error(e.message);
    }
}

function formatSize(bytes) {
    if (! bytes) return '0 B';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

const deleteTarget = ref(null);
const deleting = ref(false);

async function confirmDeleteMessage() {
    const target = deleteTarget.value;
    if (! target) return;
    deleting.value = true;
    try {
        const folder = target.folder ?? currentFolder.value;
        await fetchJson(WebmailController.destroyMessage(target.uid, { query: { folder } }));
        toast.success(folder === SCHEDULED ? 'Scheduled message cancelled'
            : folder === 'Trash' || folder === 'Junk' ? 'Message deleted' : 'Moved to Trash');
        if (thread.value?.some((m) => m.uid === target.uid && m.folder === folder)) {
            thread.value = null;
        }
        deleteTarget.value = null;
        loadMessages();
    } catch (e) {
        toast.error(e.message);
    } finally {
        deleting.value = false;
    }
}

function replyHref() {
    const last = thread.value?.at(-1);
    if (! last) {
        return WebmailController.compose.url();
    }
    return WebmailController.compose.url({
        query: { quote_uid: last.uid, quote_folder: last.folder ?? currentFolder.value },
    });
}

onMounted(() => {
    if (props.connected) {
        loadFolders();
        loadMessages();
    }
});
</script>

<template>
    <AppLayout>
        <Head title="Webmail" />
        <h2 class="mb-6 text-xl font-semibold">Webmail</h2>

        <Card v-if="! connected" class="mx-auto max-w-md">
            <CardHeader>
                <CardTitle class="text-base">Sign in to a mailbox</CardTitle>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="login" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="wm-email">Email</Label>
                        <Input id="wm-email" v-model="loginForm.email" type="email" placeholder="user@example.com" />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="wm-password">Password</Label>
                        <Input id="wm-password" v-model="loginForm.password" type="password" />
                        <p v-if="loginForm.errors.password" class="text-sm text-destructive">{{ loginForm.errors.password }}</p>
                    </div>
                    <Button type="submit" class="w-full" :disabled="loginForm.processing">
                        {{ loginForm.processing ? 'Connecting...' : 'Sign in' }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <div v-else>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 sm:mb-4">
                <p class="min-w-0 truncate text-sm text-muted-foreground">
                    Signed in as <span class="font-medium text-foreground">{{ email }}</span>
                </p>
                <div class="flex gap-1 sm:gap-2">
                    <Button variant="ghost" size="sm" @click="loadMessages">
                        <RefreshCwIcon class="size-4" />
                        <span class="hidden sm:inline">Refresh</span>
                    </Button>
                    <Link :href="WebmailController.compose.url()">
                        <Button size="sm" class="gap-2 rounded-full px-4">
                            <PenSquareIcon class="size-4" />
                            Compose
                        </Button>
                    </Link>
                    <Button variant="ghost" size="sm" @click="logout">
                        <LogOutIcon class="size-4" />
                        <span class="hidden sm:inline">Sign out</span>
                    </Button>
                </div>
            </div>

            <div class="flex h-[calc(100dvh-15rem)] min-h-[24rem] flex-col overflow-hidden rounded-2xl border bg-card lg:h-[75vh] lg:min-h-[32rem] lg:flex-row">
                <nav
                    class="flex shrink-0 flex-row gap-1 overflow-x-auto border-b p-2 transition-[width] lg:flex-col lg:gap-0 lg:space-y-0.5 lg:overflow-y-auto lg:overflow-x-hidden lg:border-b-0 lg:border-r lg:p-3"
                    :class="sidebarCollapsed ? 'lg:w-16 lg:p-2' : 'lg:w-56'"
                >
                    <button
                        @click="sidebarCollapsed = ! sidebarCollapsed"
                        class="mb-1 hidden items-center justify-center rounded-full p-2 text-muted-foreground transition hover:bg-accent lg:flex"
                        :class="sidebarCollapsed ? 'self-center' : 'self-end'"
                        :title="sidebarCollapsed ? 'Expand' : 'Collapse'"
                    >
                        <ChevronRightIcon class="size-4 transition-transform" :class="{ 'rotate-180': ! sidebarCollapsed }" />
                    </button>
                    <div
                        v-for="folder in folders"
                        :key="folder.name"
                        class="group/folder flex shrink-0 items-center rounded-full transition"
                        :class="currentFolder === folder.name ? 'bg-primary/10' : 'hover:bg-accent'"
                        @dragover.prevent
                        @drop="dropOnFolder(folder.name)"
                    >
                        <button
                            @click="selectFolder(folder.name)"
                            :title="folder.label"
                            class="flex min-w-0 flex-1 items-center gap-2.5 px-3 py-2 text-left text-sm"
                            :class="[
                                currentFolder === folder.name ? 'font-medium text-primary' : 'text-foreground/80',
                                sidebarCollapsed ? 'lg:justify-center lg:px-0' : '',
                            ]"
                        >
                            <component :is="folderIcon(folder.name)" class="size-4 shrink-0" />
                            <span class="truncate" :class="{ 'lg:hidden': sidebarCollapsed }">{{ folder.label }}</span>
                        </button>
                        <button
                            v-if="! folder.standard && ! sidebarCollapsed"
                            @click="deleteFolderTarget = folder"
                            class="mr-1.5 shrink-0 rounded-full p-1 text-muted-foreground transition hover:bg-background lg:opacity-0 lg:group-hover/folder:opacity-100"
                            title="Delete folder"
                        >
                            <XIcon class="size-3.5" />
                        </button>
                    </div>
                    <button
                        @click="creatingFolder = true"
                        title="New folder"
                        class="flex shrink-0 items-center gap-2.5 whitespace-nowrap rounded-full px-3 py-2 text-left text-sm text-muted-foreground transition hover:bg-accent lg:mt-1 lg:w-full"
                        :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                    >
                        <FolderPlusIcon class="size-4 shrink-0" />
                        <span :class="{ 'lg:hidden': sidebarCollapsed }">New folder</span>
                    </button>
                </nav>

                <div
                    class="min-h-0 flex-1 flex-col lg:w-96 lg:flex-none lg:border-r"
                    :class="thread || loadingThread ? 'hidden lg:flex' : 'flex'"
                >
                    <div class="flex items-center gap-2 border-b p-3">
                        <div class="relative flex-1">
                            <SearchIcon class="pointer-events-none absolute left-3 top-1/2 size-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="search" placeholder="Search mail" class="h-9 rounded-full bg-muted/50 pl-9 text-sm border-transparent" />
                        </div>
                        <NativeSelect v-model="sortOrder" class="h-9 w-24 rounded-full text-xs">
                            <option value="newest">Newest</option>
                            <option value="oldest">Oldest</option>
                        </NativeSelect>
                        <Button
                            v-if="currentFolder === 'Trash' || currentFolder === 'Junk'"
                            variant="ghost"
                            size="icon-sm"
                            title="Empty folder"
                            @click="confirmingEmpty = true"
                        >
                            <EraserIcon class="size-4 text-destructive" />
                        </Button>
                    </div>
                    <div class="min-h-0 flex-1 divide-y overflow-y-auto" :class="{ 'opacity-50': loadingMessages }">
                        <div
                            v-for="t in threads"
                            :key="t.key"
                            draggable="true"
                            @dragstart="dragStart(t.latest)"
                            @click="openThread(t.latest)"
                            class="group flex cursor-pointer items-start gap-3 px-4 py-3 text-left transition hover:bg-accent"
                            :class="{ 'bg-accent': thread?.some((m) => m.uid === t.latest.uid && m.folder === t.latest.folder) }"
                        >
                            <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full text-xs font-semibold text-white" :class="avatarColor(counterpart(t.latest))">
                                {{ initials(counterpart(t.latest)) }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                    <p class="truncate text-sm" :class="t.unread ? 'font-semibold' : 'text-muted-foreground'">
                                        {{ counterpartName(t.latest) }}
                                        <span v-if="counterpart(t.latest)" class="font-normal text-muted-foreground">&lt;{{ extractEmail(counterpart(t.latest)) }}&gt;</span>
                                    </p>
                                    <span class="shrink-0 text-xs text-muted-foreground">{{ itemDate(t.latest) }}</span>
                                </div>
                                <p class="flex items-center gap-1.5 truncate text-sm" :class="t.unread ? 'font-medium text-foreground' : 'text-muted-foreground'">
                                    <span v-if="t.unread" class="size-1.5 shrink-0 rounded-full bg-primary" />
                                    {{ t.latest.subject }}
                                    <Badge v-if="t.count > 1" variant="secondary" class="shrink-0 rounded-full px-1.5 text-[10px]">{{ t.count }}</Badge>
                                    <Badge v-if="t.latest.status" :variant="t.latest.status === 'failed' ? 'destructive' : 'secondary'" class="shrink-0 rounded-full px-1.5 text-[10px] capitalize">
                                        {{ t.latest.status === 'pending' ? 'scheduled' : t.latest.status }}
                                    </Badge>
                                </p>
                                <p v-if="! OUTGOING.includes(t.latest.folder)" class="truncate text-xs text-muted-foreground">To: {{ extractEmail(t.latest.to) || '—' }}</p>
                            </div>
                            <Button
                                variant="ghost"
                                size="icon-sm"
                                draggable="false"
                                class="shrink-0 lg:opacity-0 lg:group-hover:opacity-100"
                                @click.stop="deleteTarget = { uid: t.latest.uid, subject: t.latest.subject, folder: t.latest.folder ?? currentFolder }"
                            >
                                <Trash2Icon class="size-4 text-destructive" />
                            </Button>
                        </div>
                        <div v-if="! loadingMessages && threads.length === 0" class="flex flex-col items-center gap-2 px-4 py-16 text-center text-sm text-muted-foreground">
                            <MailIcon class="size-8 text-muted-foreground/40" />
                            No messages in this folder.
                        </div>
                    </div>
                </div>

                <div class="min-h-0 flex-1 flex-col" :class="thread || loadingThread ? 'flex' : 'hidden lg:flex'">
                    <p v-if="loadingThread" class="p-6 text-sm text-muted-foreground">Loading...</p>
                    <template v-else-if="thread">
                        <div class="flex shrink-0 items-start justify-between gap-2 border-b p-3 sm:gap-4 sm:p-4">
                            <Button variant="ghost" size="icon-sm" class="shrink-0 lg:hidden" aria-label="Back to list" @click="thread = null">
                                <ArrowLeftIcon class="size-4" />
                            </Button>
                            <h3 class="min-w-0 break-words text-lg font-semibold leading-snug">{{ thread.at(-1).subject }}</h3>
                            <Button
                                v-if="thread.at(-1).folder === SCHEDULED"
                                variant="outline"
                                size="sm"
                                class="shrink-0 gap-2 rounded-full"
                                @click="deleteTarget = { uid: thread.at(-1).uid, subject: thread.at(-1).subject, folder: SCHEDULED }"
                            >
                                <XCircleIcon class="size-4" />
                                Cancel sending
                            </Button>
                            <Link v-else :href="replyHref()">
                                <Button variant="outline" size="sm" class="shrink-0 gap-2 rounded-full">
                                    <ReplyIcon class="size-4" />
                                    Reply
                                </Button>
                            </Link>
                        </div>

                        <div class="min-h-0 flex-1 space-y-3 overflow-y-auto p-2 sm:p-4">
                            <div v-for="m in thread" :key="m.uid" class="overflow-hidden rounded-xl border">
                                <div class="flex items-start justify-between gap-2 bg-muted/30 px-3 py-2.5">
                                    <div class="flex min-w-0 flex-1 items-center gap-3 text-left">
                                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold text-white" :class="avatarColor(m.from)">
                                            {{ initials(m.from) }}
                                        </span>
                                        <span class="min-w-0 flex-1 truncate text-sm">
                                            <span class="font-medium">{{ senderName(m.from) }}</span>
                                            <span class="ml-1 text-xs text-muted-foreground">&lt;{{ extractEmail(m.from) }}&gt;</span>
                                            <span class="ml-2 text-xs text-muted-foreground">{{ itemDate(m) }}</span>
                                            <span class="block text-xs text-muted-foreground">To: {{ extractEmail(m.to) || '—' }}</span>
                                            <span v-if="m.status" class="block text-xs" :class="m.status === 'failed' ? 'text-destructive' : 'text-muted-foreground'">
                                                {{ m.status === 'failed' ? `Failed: ${m.error ?? 'unknown error'}` : 'Waiting to be sent' }}
                                            </span>
                                        </span>
                                    </div>
                                    <div class="flex shrink-0 gap-1">
                                        <Button variant="ghost" size="icon-sm" title="Mark as unread" @click="markUnread(m.uid, m.folder)">
                                            <MailIcon class="size-4" />
                                        </Button>
                                    </div>
                                </div>
                                <div class="min-h-[24rem] overflow-x-auto break-words bg-white p-4 text-sm text-neutral-900 [contain:paint] [&_img]:h-auto [&_img]:max-w-full" v-html="sanitizeEmail(m.body)"></div>
                                <div v-if="m.attachments?.length" class="flex flex-wrap gap-2 border-t bg-muted/30 p-3">
                                    <a
                                        v-for="a in m.attachments"
                                        :key="a.id"
                                        :href="WebmailController.downloadAttachment.url({ uid: m.uid, attachmentId: encodeURIComponent(a.id) }, { query: { folder: m.folder } })"
                                        target="_blank"
                                        class="flex items-center gap-1.5 rounded-full border bg-background px-3 py-1.5 text-xs transition hover:bg-accent"
                                    >
                                        <PaperclipIcon class="size-3.5 text-muted-foreground" />
                                        {{ a.name }}
                                        <span class="text-muted-foreground">({{ formatSize(a.size) }})</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </template>
                    <div v-else class="flex h-full flex-col items-center justify-center gap-2 p-16 text-center text-sm text-muted-foreground">
                        <MailIcon class="size-8 text-muted-foreground/40" />
                        Select a conversation to read it.
                    </div>
                </div>
            </div>
        </div>

        <Dialog v-model:open="creatingFolder">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New folder</DialogTitle>
                </DialogHeader>
                <form @submit.prevent="createFolder" class="space-y-4">
                    <div class="space-y-1.5">
                        <Label for="folder-name">Name</Label>
                        <Input id="folder-name" v-model="newFolderName" type="text" placeholder="Archive" autofocus required />
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="savingFolder">{{ savingFolder ? 'Creating...' : 'Create' }}</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <AlertDialog :open="deleteTarget !== null" @update:open="(v) => !v && (deleteTarget = null)">
            <AlertDialogContent v-if="deleteTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        {{ deleteTarget.folder === SCHEDULED ? 'Cancel this scheduled message?'
                            : deleteTarget.folder === 'Trash' || deleteTarget.folder === 'Junk' ? 'Permanently delete this message?' : 'Move this message to Trash?' }}
                    </AlertDialogTitle>
                    <AlertDialogDescription>"{{ deleteTarget.subject }}"</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" :disabled="deleting" @click="confirmDeleteMessage">
                        {{ deleteTarget.folder === SCHEDULED ? 'Cancel sending' : 'Delete' }}
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog :open="deleteFolderTarget !== null" @update:open="(v) => !v && (deleteFolderTarget = null)">
            <AlertDialogContent v-if="deleteFolderTarget">
                <AlertDialogHeader>
                    <AlertDialogTitle>Delete folder {{ deleteFolderTarget.label }}?</AlertDialogTitle>
                    <AlertDialogDescription>All messages inside it will be permanently deleted. This cannot be undone.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" :disabled="deletingFolder" @click="confirmDeleteFolder">Delete</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>

        <AlertDialog v-model:open="confirmingEmpty">
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Empty this folder?</AlertDialogTitle>
                    <AlertDialogDescription>All messages in it will be permanently deleted. This cannot be undone.</AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                    <Button variant="destructive" :disabled="emptyingFolder" @click="emptyCurrentFolder">Empty</Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    </AppLayout>
</template>
