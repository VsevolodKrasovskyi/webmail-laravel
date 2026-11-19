<script setup>
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

// Access of a user: whole domains (full management of the domain, its mailboxes and aliases)
// and single mailboxes (open in webmail, change name and password).
const domainIds = defineModel('domainIds', { type: Array, default: () => [] });
const mailboxIds = defineModel('mailboxIds', { type: Array, default: () => [] });

const props = defineProps({
    domains: { type: Array, default: () => [] },
    mailboxes: { type: Array, default: () => [] },
    error: { type: String, default: null },
});

const search = ref('');
const visibleMailboxes = computed(() => {
    const term = search.value.trim().toLowerCase();

    return term ? props.mailboxes.filter((m) => m.email.toLowerCase().includes(term)) : props.mailboxes;
});

function toggle(model, id) {
    model.value = model.value.includes(id) ? model.value.filter((x) => x !== id) : [...model.value, id];
}
</script>

<template>
    <div class="space-y-4">
        <div class="space-y-1.5">
            <Label>Domains <span class="font-normal text-muted-foreground">(full management)</span></Label>
            <div class="max-h-36 space-y-0.5 overflow-y-auto rounded-md border p-1">
                <label v-for="domain in domains" :key="domain.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent">
                    <input type="checkbox" class="size-4 accent-primary" :checked="domainIds.includes(domain.id)" @change="toggle(domainIds, domain.id)" />
                    <span class="break-all">{{ domain.name }}</span>
                </label>
                <p v-if="! domains.length" class="px-2 py-1.5 text-sm text-muted-foreground">No domains yet.</p>
            </div>
        </div>
        <div class="space-y-1.5">
            <Label>Single mailboxes <span class="font-normal text-muted-foreground">(webmail, name and password)</span></Label>
            <Input v-model="search" placeholder="Search mailboxes..." class="h-8" />
            <div class="max-h-36 space-y-0.5 overflow-y-auto rounded-md border p-1">
                <label v-for="mailbox in visibleMailboxes" :key="mailbox.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-accent">
                    <input type="checkbox" class="size-4 accent-primary" :checked="mailboxIds.includes(mailbox.id)" @change="toggle(mailboxIds, mailbox.id)" />
                    <span class="break-all">{{ mailbox.email }}</span>
                </label>
                <p v-if="! visibleMailboxes.length" class="px-2 py-1.5 text-sm text-muted-foreground">No mailboxes found.</p>
            </div>
        </div>
        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
    </div>
</template>
