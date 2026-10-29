<script setup>
import { Link } from '@inertiajs/vue3';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const props = defineProps({
    // Laravel paginator meta: { current_page, last_page, from, to, total, links }
    meta: Object,
    // Optional: sizes offered in a "Rows per page" dropdown, and the current one.
    sizes: { type: Array, default: null },
    perPage: { type: Number, default: null },
});

const emit = defineEmits(['size']);
</script>

<template>
    <div v-if="sizes || meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t px-4 py-3 text-sm text-muted-foreground">
        <p>
            <template v-if="meta.total">Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}</template>
            <template v-else>0 results</template>
        </p>
        <div v-if="sizes" class="flex items-center gap-2">
            <span class="hidden sm:inline">Rows per page</span>
            <Select :model-value="perPage ?? meta.per_page" @update:model-value="(v) => emit('size', Number(v))">
                <SelectTrigger size="sm" class="w-[4.75rem] text-xs" aria-label="Rows per page">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="n in sizes" :key="n" :value="n">{{ n }}</SelectItem>
                </SelectContent>
            </Select>
        </div>
        <div v-if="meta.last_page > 1" class="flex items-center gap-1">
            <template v-for="(link, index) in meta.links" :key="index">
                <span
                    v-if="! link.url"
                    class="rounded-md px-3 py-1.5 text-muted-foreground/50"
                    v-html="link.label"
                />
                <Link
                    v-else
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    class="rounded-md px-3 py-1.5 hover:bg-accent"
                    :class="{ 'bg-accent font-medium text-accent-foreground': link.active }"
                    v-html="link.label"
                />
            </template>
        </div>
    </div>
</template>
