<script setup>
import { ref } from 'vue';
import { XIcon } from '@lucide/vue';
import { toast } from 'vue-sonner';

// Chip input for email addresses: Enter, comma, space, blur or paste commits them.
const model = defineModel({ type: Array, default: () => [] });

defineProps({
    placeholder: { type: String, default: 'name@example.com' },
    id: { type: String, default: undefined },
});

const draft = ref('');
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

function commit() {
    const parts = draft.value.split(/[,;\s]+/).map((p) => p.trim()).filter(Boolean);
    const next = [...model.value];
    const rejected = [];

    for (const part of parts) {
        if (! EMAIL.test(part)) {
            rejected.push(part);
        } else if (! next.includes(part)) {
            next.push(part);
        }
    }

    model.value = next;
    // Keep what was typed if it isn't a valid address, so it can be fixed.
    draft.value = rejected.join(' ');
    if (rejected.length) {
        toast.error(`Not a valid email: ${rejected.join(', ')}`);
    }
}

function remove(address) {
    model.value = model.value.filter((a) => a !== address);
}

function onBackspace() {
    if (! draft.value && model.value.length) {
        model.value = model.value.slice(0, -1);
    }
}

defineExpose({ commit });
</script>

<template>
    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
        <span
            v-for="address in model"
            :key="address"
            class="inline-flex max-w-full items-center gap-1 rounded-full bg-secondary py-0.5 pl-2.5 pr-1 text-xs"
        >
            <span class="truncate">{{ address }}</span>
            <button type="button" class="rounded-full p-0.5 text-muted-foreground hover:bg-background hover:text-foreground" :aria-label="`Remove ${address}`" @click="remove(address)">
                <XIcon class="size-3" />
            </button>
        </span>
        <input
            :id="id"
            v-model="draft"
            type="text"
            inputmode="email"
            autocapitalize="off"
            :placeholder="model.length ? '' : placeholder"
            class="min-w-32 flex-1 bg-transparent py-1 text-sm outline-none placeholder:text-muted-foreground"
            @keydown.enter.prevent="commit"
            @keydown.,.prevent="commit"
            @keydown.space="commit"
            @keydown.backspace="onBackspace"
            @blur="commit"
            @paste="() => $nextTick(commit)"
        />
    </div>
</template>
