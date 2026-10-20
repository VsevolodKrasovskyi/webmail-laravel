<script setup>
import { ArrowUpIcon, ArrowDownIcon, ArrowUpDownIcon } from '@lucide/vue';
import { TableHead } from '@/components/ui/table';

const props = defineProps({
    field: { type: String, required: true },
    sort: { type: String, default: null },
    direction: { type: String, default: 'asc' },
});

const emit = defineEmits(['sort']);
</script>

<template>
    <TableHead>
        <button type="button" class="inline-flex items-center gap-1 hover:text-foreground" @click="emit('sort', field)">
            <slot />
            <ArrowUpIcon v-if="sort === field && direction === 'asc'" class="size-3.5" />
            <ArrowDownIcon v-else-if="sort === field && direction === 'desc'" class="size-3.5" />
            <ArrowUpDownIcon v-else class="size-3.5 text-muted-foreground/50" />
        </button>
    </TableHead>
</template>
