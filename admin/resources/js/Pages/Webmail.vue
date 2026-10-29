<script setup>
import { Head } from '@inertiajs/vue3';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import MailboxController from '@/actions/App/Http/Controllers/MailboxController';
import { ref, computed, watch, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import {
    PenSquareIcon, RefreshCwIcon, LogOutIcon, ReplyIcon, Trash2Icon, FolderPlusIcon, FolderIcon,
    InboxIcon, SendIcon, ShieldAlertIcon, XIcon, SearchIcon, ChevronRightIcon, MailIcon,
    EraserIcon, PaperclipIcon, ArrowLeftIcon, FileTextIcon, ClockIcon, LayersIcon, XCircleIcon,
} from '@lucide/vue';
import AppLayout from '../Layouts/AppLayout.vue';
import { fetchJson } from '../fetchJson';
import { sanitizeEmail } from '@/lib/sanitizeEmail';
import { avatarClass } from '@/lib/avatar';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import NativeSelect from '@/components/NativeSelect.vue';
import {
    Dialog, DialogContent, DialogHeader, DialogTitle, DialogFooter,
} from '@/components/ui/dialog';
import {
    AlertDialog, AlertDialogContent, AlertDialogHeader, AlertDialogTitle,
    AlertDialogDescription, AlertDialogFooter, AlertDialogCancel,
} from '@/components/ui/alert-dialog';

defineProps({
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
    return avatarClass(extractEmail(address) || address);
}

function logout() {
    router.post(WebmailController.logout());
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

// ---- Drag and drop: move a message to another folder ----
const dragItem = ref(null);
const dragOverFolder = ref(null);

// Queued (scheduled) messages are not IMAP messages, so they cannot be moved.
const canDrag = (item) => item.folder !== SCHEDULED;

// Virtual folders (All mail, Scheduled) are views, and moving into the folder it is already in does nothing.
function canDropOn(folder) {
    const item = dragItem.value;
    return !! item && ! folder.virtual && folder.name !== (item.folder ?? currentFolder.value);
}

function folderState(folder) {
    if (! dragItem.value) return null;
    if (! canDropOn(folder)) return 'blocked';
    return dragOverFolder.value === folder.name ? 'over' : 'target';
}

const isDragging = (item) => dragItem.value?.uid === item.uid && dragItem.value?.folder === item.folder;

// A small card that follows the pointer instead of the browser's faded copy of the row.
function dragGhost(item) {
    const ghost = document.createElement('div');
    ghost.style.cssText = 'position:fixed;top:-300px;left:-300px;display:flex;align-items:center;gap:10px;max-width:300px;'
        + 'padding:8px 14px 8px 8px;border-radius:14px;border:1px solid var(--border);background:var(--card);'
        + 'color:var(--card-foreground);font:500 13px/1.25 Inter,system-ui,sans-serif;box-shadow:0 12px 32px rgba(0,0,0,.45)';

    const badge = document.createElement('span');
    badge.className = avatarColor(counterpart(item));
    badge.style.cssText = 'display:flex;flex:none;align-items:center;justify-content:center;width:28px;height:28px;border-radius:9999px;color:#fff;font-size:11px;font-weight:600';
    badge.textContent = initials(counterpart(item));

    // textContent only: subjects and senders are untrusted.
    const text = document.createElement('span');
    text.style.cssText = 'display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap';
    text.textContent = item.subject;

    ghost.append(badge, text);
    document.body.appendChild(ghost);
    return ghost;
}

function onDragStart(event, item) {
    if (! canDrag(item)) {
        event.preventDefault();
        return;
    }
    dragItem.value = item;
    event.dataTransfer.effectAllowed = 'move';
    event.dataTransfer.setData('text/plain', item.subject ?? '');
    const ghost = dragGhost(item);
    event.dataTransfer.setDragImage(ghost, 18, 18);
    setTimeout(() => ghost.remove(), 0);
}

function onDragEnd() {
    dragItem.value = null;
    dragOverFolder.value = null;
}

function onFolderOver(event, folder) {
    if (! canDropOn(folder)) {
        event.dataTransfer.dropEffect = 'none';
        return;
    }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    dragOverFolder.value = folder.name;
}

function onFolderLeave(event, folder) {
    // Moving between the folder's own children also fires dragleave, ignore that.
    if (event.currentTarget.contains(event.relatedTarget)) return;
    if (dragOverFolder.value === folder.name) dragOverFolder.value = null;
}

async function dropOnFolder(targetPath) {
    const item = dragItem.value;
    const target = folders.value.find((f) => f.name === targetPath);
    onDragEnd();
    const uid = item?.uid;
    const from = item?.folder ?? currentFolder.value;
    if (! uid || ! target || target.virtual || targetPath === from || from === SCHEDULED) {
        return;
    }
    try {
        await fetchJson(WebmailController.moveMessage(uid), {
            body: { from, to: targetPath },
        });
        toast.success(`Moved to ${target.label}`);
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
    loadFolders();
    loadMessages();
});
</script>

<template>
    <AppLayout>
        <Head title="Webmail" />
<div>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 sm:mb-4">
                <div class="flex min-w-0 items-center gap-2">
                    <Link :href="MailboxController.index.url()" class="rounded-full p-2 text-muted-foreground transition hover:bg-accent" title="Back to mailboxes" aria-label="Back to mailboxes">
                        <ArrowLeftIcon class="size-4" />
                    </Link>
                    <p class="min-w-0 truncate text-sm text-muted-foreground">
                        Mailbox <span class="font-medium text-foreground">{{ email }}</span>
                    </p>
                </div>
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
                        class="group/folder flex shrink-0 items-center rounded-full outline-offset-0 transition duration-150"
                        :class="[
                            currentFolder === folder.name ? 'bg-primary/10' : 'hover:bg-accent',
                            folderState(folder) === 'target' ? 'bg-primary/5 outline-dashed outline-1 outline-primary/40' : '',
                            folderState(folder) === 'over' ? 'scale-[1.03] bg-primary/15 shadow-sm outline outline-2 outline-primary' : '',
                            folderState(folder) === 'blocked' ? 'opacity-40' : '',
                        ]"
                        @dragover="onFolderOver($event, folder)"
                        @dragleave="onFolderLeave($event, folder)"
                        @drop.prevent="dropOnFolder(folder.name)"
                    >
                        <button
                            @click="selectFolder(folder.name)"
                            :title="folder.name === '__ALL__' ? 'All mail: inbox, sent, drafts, your folders and scheduled messages (not Junk or Trash)' : folder.label"
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
                    <div class="min-h-0 flex-1 overflow-y-auto" :class="{ 'opacity-50': loadingMessages }">
                        <TransitionGroup
                            tag="div"
                            class="relative divide-y"
                            enter-active-class="transition duration-200"
                            enter-from-class="-translate-y-1 opacity-0"
                            leave-active-class="absolute inset-x-0 transition duration-200"
                            leave-to-class="translate-x-6 opacity-0"
                            move-class="transition duration-200"
                        >
                        <div
                            v-for="t in threads"
                            :key="t.key"
                            :draggable="canDrag(t.latest)"
                            @dragstart="onDragStart($event, t.latest)"
                            @dragend="onDragEnd"
                            @click="openThread(t.latest)"
                            class="group flex items-start gap-3 bg-card px-4 py-3 text-left transition hover:bg-accent"
                            :class="[
                                canDrag(t.latest) ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer',
                                isDragging(t.latest) ? 'opacity-40' : '',
                                thread?.some((m) => m.uid === t.latest.uid && m.folder === t.latest.folder) ? 'bg-accent' : '',
                            ]"
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
                        </TransitionGroup>
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
        <!-- While dragging a message: a hint, shown on screens where drag and drop is available -->
        <Transition
            enter-active-class="transition duration-200"
            enter-from-class="translate-y-2 opacity-0"
            leave-active-class="transition duration-150"
            leave-to-class="translate-y-2 opacity-0"
        >
            <div
                v-if="dragItem"
                class="pointer-events-none fixed bottom-6 left-1/2 z-50 hidden max-w-[28rem] -translate-x-1/2 items-center gap-2 rounded-full border bg-card px-4 py-2 text-sm shadow-lg lg:flex"
            >
                <FolderIcon class="size-4 shrink-0 text-primary" />
                <span class="shrink-0">Drop on a folder to move</span>
                <span class="truncate text-muted-foreground">{{ dragItem.subject }}</span>
            </div>
        </Transition>
    </AppLayout>
</template>
