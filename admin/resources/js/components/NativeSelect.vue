<script setup>
import { useVModel } from '@vueuse/core';
import { ChevronDownIcon } from '@lucide/vue';
import { cn } from 'cn';

const props = defineProps({
    modelValue: { type: [String, Number], required: false },
    class: { type: [Boolean, null, String, Object, Array], required: false, skipCheck: true },
});

const emits = defineEmits(['update:modelValue']);

const modelValue = useVModel(props, 'modelValue', emits, { passive: true });
</script>

<template>
    <div class="relative inline-block">
        <select
            v-model="modelValue"
            :class="cn(
                'dark:bg-input/30 border-input h-9 w-full appearance-none rounded-md border bg-transparent py-1 pl-2.5 pr-8 text-sm shadow-xs outline-none',
                props.class,
            )"
        >
            <slot />
        </select>
        <ChevronDownIcon class="pointer-events-none absolute right-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
    </div>
</template>
