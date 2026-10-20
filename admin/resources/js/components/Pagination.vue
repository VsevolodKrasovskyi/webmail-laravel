<script setup>
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    // Laravel paginator meta: { current_page, last_page, from, to, total, links }
    meta: Object,
});
</script>

<template>
    <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t px-4 py-3 text-sm text-muted-foreground">
        <p>
            Showing {{ meta.from }}–{{ meta.to }} of {{ meta.total }}
        </p>
        <div class="flex items-center gap-1">
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
