<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ArrowLeftIcon, PaperclipIcon, XIcon, SendIcon } from '@lucide/vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { fetchJson } from '../../fetchJson';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import RichTextEditor from '@/Components/RichTextEditor.vue';

const props = defineProps({
    to: { type: String, default: '' },
    subject: { type: String, default: '' },
    body: { type: String, default: '' },
    inReplyTo: { type: String, default: null },
});

const to = ref(props.to);
const ccList = ref([]);
const ccInput = ref('');
const showCc = ref(false);
const subject = ref(props.subject);
const body = ref(props.body);
const attachments = ref([]);
const sending = ref(false);

function addCc() {
    const value = ccInput.value.trim().replace(/,$/, '');
    if (value && ! ccList.value.includes(value)) {
        ccList.value.push(value);
    }
    ccInput.value = '';
}

function removeCc(address) {
    ccList.value = ccList.value.filter((a) => a !== address);
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

async function send() {
    sending.value = true;
    try {
        addCc();

        const encodedAttachments = await Promise.all(attachments.value.map(async (a) => ({
            name: a.name,
            contentType: a.file.type || 'application/octet-stream',
            content: (await fileToDataUrl(a.file)).split(',')[1],
        })));

        await fetchJson('/webmail/send', {
            method: 'POST',
            body: {
                to: to.value,
                cc: ccList.value,
                subject: subject.value,
                body: body.value,
                in_reply_to: props.inReplyTo,
                attachments: encodedAttachments,
            },
        });
        toast.success('Message sent');
        window.location.href = '/webmail';
    } catch (e) {
        toast.error(e.message);
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <AppLayout>
        <div class="mb-6 flex items-center gap-3">
            <Link href="/webmail" class="rounded-full p-2 text-muted-foreground transition hover:bg-accent">
                <ArrowLeftIcon class="size-4" />
            </Link>
            <h2 class="text-xl font-semibold">{{ inReplyTo ? 'Reply' : 'New message' }}</h2>
        </div>

        <div class="mx-auto max-w-3xl space-y-4 rounded-2xl border bg-card p-6">
            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label for="compose-to">To</Label>
                    <button
                        v-if="! showCc"
                        type="button"
                        class="text-xs text-muted-foreground underline underline-offset-2"
                        @click="showCc = true"
                    >
                        Add Cc
                    </button>
                </div>
                <Input id="compose-to" v-model="to" type="email" required />
            </div>

            <div v-if="showCc" class="space-y-1.5">
                <Label for="compose-cc">Cc</Label>
                <div class="flex flex-wrap items-center gap-1.5 rounded-md border border-input px-2 py-1.5">
                    <Badge v-for="address in ccList" :key="address" variant="secondary" class="gap-1">
                        {{ address }}
                        <button type="button" @click="removeCc(address)">
                            <XIcon class="size-3" />
                        </button>
                    </Badge>
                    <input
                        id="compose-cc"
                        v-model="ccInput"
                        type="text"
                        placeholder="email, then Enter"
                        class="min-w-32 flex-1 bg-transparent text-sm outline-none"
                        @keydown.enter.prevent="addCc"
                        @keydown.,="addCc"
                        @blur="addCc"
                    />
                </div>
            </div>

            <div class="space-y-1.5">
                <Label for="compose-subject">Subject</Label>
                <Input id="compose-subject" v-model="subject" type="text" required />
            </div>

            <div class="space-y-1.5">
                <div class="flex items-center justify-between">
                    <Label>Message</Label>
                    <label class="inline-flex cursor-pointer items-center gap-1.5 text-xs text-muted-foreground underline underline-offset-2 hover:text-foreground">
                        <PaperclipIcon class="size-3.5" />
                        Attach files
                        <input
                            type="file"
                            multiple
                            class="hidden"
                            @change="(e) => { addAttachments(Array.from(e.target.files ?? [])); e.target.value = ''; }"
                        />
                    </label>
                </div>
                <RichTextEditor v-model="body" />
                <div v-if="attachments.length" class="flex flex-wrap gap-2 pt-1">
                    <Badge v-for="(a, index) in attachments" :key="index" variant="secondary" class="gap-1.5 py-1.5">
                        {{ a.name }}
                        <span class="text-muted-foreground">({{ formatSize(a.size) }})</span>
                        <button type="button" @click="removeAttachment(index)">
                            <XIcon class="size-3" />
                        </button>
                    </Badge>
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t pt-4">
                <Link href="/webmail">
                    <Button variant="ghost">Cancel</Button>
                </Link>
                <Button :disabled="sending" class="gap-2" @click="send">
                    <SendIcon class="size-4" />
                    {{ sending ? 'Sending...' : 'Send' }}
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
