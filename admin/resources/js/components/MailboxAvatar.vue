<script setup>
import { avatarClass, mailboxInitials } from '@/lib/avatar';

defineProps({
    email: { type: String, required: true },
    name: { type: String, default: null },
    size: { type: String, default: 'md' },
});

const sizes = { sm: 'size-8 text-xs', md: 'size-10 text-sm' };
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center rounded-full font-semibold uppercase text-white"
        :class="[avatarClass(email), sizes[size]]"
        aria-hidden="true"
    >{{ mailboxInitials(email, name) }}</span>
</template>
