<script setup>
import { ChevronLeftIcon, ChevronRightIcon } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import NativeSelect from '@/components/NativeSelect.vue';
import { PAGE_SIZES } from '@/lib/usePager';

// Footer for a list paged with usePager: range, rows per page and previous/next.
// Shown only when the list is longer than the smallest page size.
const props = defineProps({ pager: { type: Object, required: true } });
</script>

<template>
    <div v-if="pager.total.value > PAGE_SIZES[0]" class="mt-3 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3 text-sm">
        <p class="text-muted-foreground">{{ pager.from.value }}–{{ pager.to.value }} of {{ pager.total.value }}</p>
        <div class="flex items-center gap-3">
            <label class="flex items-center gap-2 text-muted-foreground">
                <span class="hidden sm:inline">Per page</span>
                <NativeSelect v-model="pager.size.value" class="h-8 w-[4.5rem] text-xs" aria-label="Rows per page">
                    <option v-for="n in PAGE_SIZES" :key="n" :value="n">{{ n }}</option>
                </NativeSelect>
            </label>
            <div class="flex items-center gap-1">
                <Button variant="outline" size="icon-sm" :disabled="pager.current.value <= 1" aria-label="Previous page" @click="pager.prev()">
                    <ChevronLeftIcon class="size-4" />
                </Button>
                <span class="min-w-14 text-center tabular-nums text-muted-foreground">{{ pager.current.value }} / {{ pager.pages.value }}</span>
                <Button variant="outline" size="icon-sm" :disabled="pager.current.value >= pager.pages.value" aria-label="Next page" @click="pager.next()">
                    <ChevronRightIcon class="size-4" />
                </Button>
            </div>
        </div>
    </div>
</template>
