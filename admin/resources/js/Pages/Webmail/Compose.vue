<script setup>
import { Head } from '@inertiajs/vue3';
import WebmailController from '@/actions/App/Http/Controllers/WebmailController';
import { ref, watch, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowLeftIcon, PaperclipIcon, XIcon, SendIcon, SaveIcon, CalendarClockIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { fetchJson } from '../../fetchJson';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import RecipientInput from '@/components/RecipientInput.vue';
import RichTextEditor from '@/components/RichTextEditor.vue';

const props = defineProps({
    to: { type: String, default: '' },
    subject: { type: String, default: '' },
    body: { type: String, default: '' },
    cc: { type: Array, default: () => [] },
    bcc: { type: Array, default: () => [] },
    inReplyTo: { type: String, default: null },
    draftId: { type: String, default: null },
    canSchedule: { type: Boolean, default: false },
});

const to = ref(props.to);
const ccList = ref([...props.cc]);
const bccList = ref([...props.bcc]);
const showCc = ref(props.cc.length > 0);
const showBcc = ref(props.bcc.length > 0);
const ccRef = ref(null);
const bccRef = ref(null);
const subject = ref(props.subject);
const body = ref(props.body);
const attachments = ref([]);
const sending = ref(false);
const draftId = ref(props.draftId);
const savingDraft = ref(false);
const draftSavedAt = ref(null);
const scheduling = ref(false);
const sendAt = ref('');

// `datetime-local` wants local time in this exact shape.
function localInputValue(date) {
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

const minSendAt = () => localInputValue(new Date(Date.now() + 60_000));

function openScheduler() {
    if (! sendAt.value) {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);
        tomorrow.setHours(9, 0, 0, 0);
        sendAt.value = localInputValue(tomorrow);
    }
    scheduling.value = ! scheduling.value;
}

let lastSaved = JSON.stringify([props.to, props.subject, props.body, props.cc, props.bcc]);
let autosaveTimer;

function draftSnapshot() {
    return JSON.stringify([to.value, subject.value, body.value, ccList.value, bccList.value]);
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

// To and Subject are required for every action: send, schedule and save draft.
function validate({ silent = false } = {}) {
    let message = null;
    let field = null;

    if (! EMAIL.test(to.value.trim())) {
        message = to.value.trim() ? 'Enter a valid recipient address' : 'Recipient (To) is required';
        field = 'compose-to';
    } else if (! subject.value.trim()) {
        message = 'Subject is required';
        field = 'compose-subject';
    }

    if (message && ! silent) {
        toast.error(message);
        document.getElementById(field)?.focus();
    }

    return ! message;
}

async function saveDraft({ silent = false } = {}) {
    if (savingDraft.value || sending.value) return;
    if (! validate({ silent })) return;
    const snapshot = draftSnapshot();
    if (silent && snapshot === lastSaved) return;
    savingDraft.value = true;
    try {
        const data = await fetchJson(WebmailController.saveDraft(), {
            body: {
                to: to.value,
                cc: ccList.value,
                bcc: bccList.value,
                subject: subject.value,
                body: body.value,
                in_reply_to: props.inReplyTo,
                draft_id: draftId.value,
            },
        });
        draftId.value = data.draft_id;
        lastSaved = snapshot;
        draftSavedAt.value = new Date();
        if (! silent) toast.success('Draft saved');
    } catch (e) {
        if (! silent) toast.error(e.message);
    } finally {
        savingDraft.value = false;
    }
}

// Autosave a few seconds after the last edit, but only once there is something to keep.
watch([to, subject, body, ccList, bccList], () => {
    clearTimeout(autosaveTimer);
    if (! validate({ silent: true })) return;
    autosaveTimer = setTimeout(() => saveDraft({ silent: true }), 5000);
}, { deep: true });

onBeforeUnmount(() => clearTimeout(autosaveTimer));

// Closing a recipient row also drops its addresses, so nothing hidden is ever sent.
function closeCc() {
    showCc.value = false;
    ccList.value = [];
}

function closeBcc() {
    showBcc.value = false;
    bccList.value = [];
}

function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

function addAttachments(files) {
    for (const file of files) {
        attachments.value.push({ file, name: file.name, size: file.size });
    }
}

function removeAttachment(index) {
    attachments.value.splice(index, 1);
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

async function buildPayload() {
    ccRef.value?.commit();
    bccRef.value?.commit();
    clearTimeout(autosaveTimer);

    const encodedAttachments = await Promise.all(attachments.value.map(async (a) => ({
        name: a.name,
        contentType: a.file.type || 'application/octet-stream',
        content: (await fileToDataUrl(a.file)).split(',')[1],
    })));

    return {
        to: to.value,
        cc: ccList.value,
        bcc: bccList.value,
        subject: subject.value,
        body: body.value,
        in_reply_to: props.inReplyTo,
        draft_id: draftId.value,
        attachments: encodedAttachments,
    };
}

async function schedule() {
    if (! sendAt.value || ! validate()) return;
    sending.value = true;
    try {
        const payload = await buildPayload();
        payload.send_at = new Date(sendAt.value).toISOString();
        await fetchJson(WebmailController.schedule(), { body: payload });
        toast.success('Message scheduled');
        window.location.href = WebmailController.index.url();
    } catch (e) {
        toast.error(e.message);
    } finally {
        sending.value = false;
    }
}

async function send() {
    if (! validate()) return;
    sending.value = true;
    try {
        await fetchJson(WebmailController.send(), { body: await buildPayload() });
        toast.success('Message sent');
        window.location.href = WebmailController.index.url();
    } catch (e) {
        toast.error(e.message);
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <Head title="New message" />
        <div class="mx-auto w-full max-w-3xl">
            <div class="mb-4 flex items-center gap-3">
                <Link :href="WebmailController.index.url()" class="rounded-full p-2 text-muted-foreground transition hover:bg-accent" aria-label="Back to webmail">
                    <ArrowLeftIcon class="size-4" />
                </Link>
                <h2 class="text-xl font-semibold tracking-tight">{{ inReplyTo ? 'Reply' : 'New message' }}</h2>
                <p class="ml-auto text-xs text-muted-foreground">
                    <template v-if="savingDraft">Saving...</template>
                    <template v-else-if="draftSavedAt">Draft saved {{ draftSavedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</template>
                </p>
            </div>

            <div class="overflow-hidden rounded-2xl border bg-card shadow-sm">
                <div class="flex items-center gap-3 border-b px-4 py-1.5">
                    <label for="compose-to" class="w-14 shrink-0 text-sm text-muted-foreground">To<span class="ml-0.5 text-destructive" aria-hidden="true">*</span></label>
                    <input
                        id="compose-to"
                        v-model="to"
                        type="email"
                        required
                        aria-required="true"
                        autocapitalize="off"
                        placeholder="name@example.com"
                        class="min-w-0 flex-1 bg-transparent py-2 text-sm outline-none placeholder:text-muted-foreground"
                    />
                    <div class="flex shrink-0 gap-1 text-xs">
                        <button
                            v-if="! showCc"
                            type="button"
                            class="rounded-full px-2.5 py-1 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                            @click="showCc = true"
                        >Cc</button>
                        <button
                            v-if="! showBcc"
                            type="button"
                            class="rounded-full px-2.5 py-1 text-muted-foreground transition hover:bg-accent hover:text-foreground"
                            @click="showBcc = true"
                        >Bcc</button>
                    </div>
                </div>

                <div v-if="showCc" class="flex items-start gap-3 border-b px-4 py-1.5">
                    <label for="compose-cc" class="w-14 shrink-0 py-2 text-sm text-muted-foreground">Cc</label>
                    <RecipientInput id="compose-cc" ref="ccRef" v-model="ccList" placeholder="Add Cc recipients" class="py-1" />
                    <button type="button" class="mt-1.5 shrink-0 rounded-full p-1 text-muted-foreground transition hover:bg-accent hover:text-foreground" title="Remove Cc" aria-label="Remove Cc" @click="closeCc">
                        <XIcon class="size-4" />
                    </button>
                </div>

                <div v-if="showBcc" class="flex items-start gap-3 border-b px-4 py-1.5">
                    <label for="compose-bcc" class="w-14 shrink-0 py-2 text-sm text-muted-foreground">Bcc</label>
                    <RecipientInput id="compose-bcc" ref="bccRef" v-model="bccList" placeholder="Hidden recipients" class="py-1" />
                    <button type="button" class="mt-1.5 shrink-0 rounded-full p-1 text-muted-foreground transition hover:bg-accent hover:text-foreground" title="Remove Bcc" aria-label="Remove Bcc" @click="closeBcc">
                        <XIcon class="size-4" />
                    </button>
                </div>

                <div class="flex items-center gap-3 border-b px-4 py-1.5">
                    <label for="compose-subject" class="w-14 shrink-0 text-sm text-muted-foreground">Subject<span class="ml-0.5 text-destructive" aria-hidden="true">*</span></label>
                    <input
                        id="compose-subject"
                        v-model="subject"
                        type="text"
                        required
                        class="min-w-0 flex-1 bg-transparent py-2 text-sm font-medium outline-none"
                    />
                </div>

                <div class="space-y-3 p-3 sm:p-4">
                    <RichTextEditor v-model="body" />

                    <div class="flex flex-wrap items-center gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs text-muted-foreground transition hover:bg-accent hover:text-foreground">
                            <PaperclipIcon class="size-3.5" />
                            Attach files
                            <input
                                type="file"
                                multiple
                                class="hidden"
                                @change="(e) => { addAttachments(Array.from(e.target.files ?? [])); e.target.value = ''; }"
                            />
                        </label>
                        <Badge v-for="(a, index) in attachments" :key="index" variant="secondary" class="max-w-full gap-1.5 py-1.5">
                            <span class="truncate">{{ a.name }}</span>
                            <span class="shrink-0 text-muted-foreground">({{ formatSize(a.size) }})</span>
                            <button type="button" class="shrink-0" @click="removeAttachment(index)">
                                <XIcon class="size-3" />
                            </button>
                        </Badge>
                    </div>

                    <div v-if="scheduling" class="flex flex-wrap items-end gap-3 rounded-xl border bg-muted/30 p-3">
                        <div class="w-full space-y-1.5 sm:w-auto">
                            <Label for="compose-send-at">Send at</Label>
                            <Input id="compose-send-at" v-model="sendAt" type="datetime-local" :min="minSendAt()" class="w-full sm:w-56" />
                        </div>
                        <Button :disabled="sending || ! sendAt" class="w-full gap-2 sm:w-auto" @click="schedule">
                            <CalendarClockIcon class="size-4" />
                            {{ sending ? 'Scheduling...' : 'Schedule send' }}
                        </Button>
                        <p class="basis-full text-xs text-muted-foreground">Sent from your mailbox by the server at that time (your local time zone).</p>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-2 border-t bg-muted/20 px-3 py-3 sm:px-4">
                    <Button :disabled="sending" class="gap-2 rounded-full px-5" @click="send">
                        <SendIcon class="size-4" />
                        {{ sending ? 'Sending...' : 'Send' }}
                    </Button>
                    <div class="flex items-center gap-1">
                        <Button v-if="canSchedule" variant="ghost" size="sm" :disabled="sending" class="gap-2" title="Send later" @click="validate() && openScheduler()">
                            <CalendarClockIcon class="size-4" />
                            <span class="hidden sm:inline">Send later</span>
                        </Button>
                        <Button variant="ghost" size="sm" :disabled="savingDraft || sending" class="gap-2" title="Save draft" @click="saveDraft()">
                            <SaveIcon class="size-4" />
                            <span class="hidden sm:inline">Save draft</span>
                        </Button>
                        <Link :href="WebmailController.index.url()">
                            <Button variant="ghost" size="sm" title="Close">
                                <XIcon class="size-4" />
                                <span class="hidden sm:inline">Close</span>
                            </Button>
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
