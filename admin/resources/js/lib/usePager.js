import { computed, ref, watch } from 'vue';

// Client-side paging of a list, with a changeable page size.
export const PAGE_SIZES = [5, 10, 20, 50];

export function usePager(source, initialSize = 5) {
    const size = ref(initialSize);
    const page = ref(1);

    const total = computed(() => source.value.length);
    const pages = computed(() => Math.max(1, Math.ceil(total.value / size.value)));
    // Stay on a valid page when the list shrinks (a mailbox was removed or disabled).
    const current = computed(() => Math.min(page.value, pages.value));
    const items = computed(() => source.value.slice((current.value - 1) * size.value, current.value * size.value));
    const from = computed(() => (total.value ? (current.value - 1) * size.value + 1 : 0));
    const to = computed(() => Math.min(current.value * size.value, total.value));

    watch(size, () => { page.value = 1; });

    return {
        size, page, total, pages, current, items, from, to,
        prev: () => { page.value = Math.max(1, current.value - 1); },
        next: () => { page.value = Math.min(pages.value, current.value + 1); },
    };
}
