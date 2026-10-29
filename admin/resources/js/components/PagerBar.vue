<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

// Footer for a table paged on the server (a Laravel paginator, or the same shape):
// range, rows per page (always shown) and previous/next.
const props = defineProps({
    meta: { type: Object, required: true },
    sizes: { type: Array, default: () => [5, 10, 20, 50] },
    // false = a fixed page size, no dropdown
    selectable: { type: Boolean, default: true },
});

const emit = defineEmits(['page', 'size']);
</script>

<template>
    <div class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3 text-sm">
        <p class="text-muted-foreground">
            <template v-if="meta.total">{{ meta.from }}–{{ meta.to }} of {{ meta.total }}</template>
            <template v-else>0 of 0</template>
        </p>
        <div class="flex items-center gap-3">
            <div v-if="selectable" class="flex items-center gap-2 text-muted-foreground">
                <span class="hidden sm:inline">Rows per page</span>
                <Select :model-value="meta.per_page" @update:model-value="(v) => emit('size', Number(v))">
                    <SelectTrigger size="sm" class="w-[4.5rem] text-xs text-foreground" aria-label="Rows per page">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="n in sizes" :key="n" :value="n">{{ n }}</SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="flex items-center gap-1">
                <Button variant="outline" size="icon-sm" :disabled="meta.current_page <= 1" aria-label="Previous page" @click="emit('page', meta.current_page - 1)">
                    <ChevronLeftIcon class="size-4" />
                </Button>
                <span class="min-w-14 text-center tabular-nums text-muted-foreground">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <Button variant="outline" size="icon-sm" :disabled="meta.current_page >= meta.last_page" aria-label="Next page" @click="emit('page', meta.current_page + 1)">
                    <ChevronRightIcon class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
