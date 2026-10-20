<script setup>
import { computed } from 'vue';

// Single-series column chart in plain HTML. Hover a column for its tooltip.
const props = defineProps({
    // [{ label, value, tooltip? }]
    items: { type: Array, required: true },
    height: { type: Number, default: 120 },
    // Show every Nth label to avoid collisions on dense axes.
    labelEvery: { type: Number, default: 1 },
    barClass: { type: String, default: 'bg-indigo-500' },
});

const max = computed(() => Math.max(1, ...props.items.map((i) => i.value)));
</script>

<template>
    <div>
        <div class="flex items-end gap-1" :style="{ height: `${height}px` }">
            <div
                v-for="(item, index) in items"
                :key="index"
                class="group relative flex h-full min-w-0 flex-1 items-end"
                :title="item.tooltip ?? `${item.label}: ${item.value}`"
            >
                <div
                    class="w-full rounded-t-[4px] transition-opacity group-hover:opacity-80"
                    :class="[barClass, item.value === 0 ? 'opacity-20' : '']"
                    :style="{ height: item.value === 0 ? '2px' : `${Math.max(4, (item.value / max) * 100)}%` }"
                />
            </div>
        </div>
        <div class="mt-1.5 flex gap-1">
            <span
                v-for="(item, index) in items"
                :key="index"
                class="min-w-0 flex-1 truncate text-center text-[10px] text-muted-foreground"
            >{{ index % labelEvery === 0 ? item.label : '' }}</span>
        </div>
    </div>
</template>
