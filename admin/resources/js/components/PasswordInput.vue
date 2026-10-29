<script setup>
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import { EyeIcon, EyeOffIcon, WandSparklesIcon, CopyIcon, CheckIcon } from '@lucide/vue';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { generatePassword } from '@/lib/password';

// Password field that is hidden by default, with a show/hide toggle, a generator and a copy button.
const model = defineModel({ type: String, default: '' });

defineProps({
    id: { type: String, default: undefined },
    length: { type: Number, default: 16 },
    placeholder: { type: String, default: undefined },
});

const visible = ref(false);
const copied = ref(false);
let timer;

function generate(length) {
    // Stays hidden unless the user chooses to reveal it, use the copy button to take it.
    model.value = generatePassword(length);
}

async function copy() {
    if (! model.value) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(model.value);
        } else {
            const area = document.createElement('textarea');
            area.value = model.value;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            area.remove();
        }
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => (copied.value = false), 1500);
        toast.success('Password copied');
    } catch {
        toast.error('Could not copy, show the password and copy it by hand');
    }
}
</script>

<template>
    <div class="flex items-center gap-2">
        <div class="relative min-w-0 flex-1">
            <Input
                :id="id"
                v-model="model"
                :type="visible ? 'text' : 'password'"
                :placeholder="placeholder"
                autocomplete="new-password"
                autocapitalize="off"
                spellcheck="false"
                class="pr-10"
            />
            <button
                type="button"
                class="absolute right-1.5 top-1/2 flex size-7 -translate-y-1/2 items-center justify-center rounded-md text-muted-foreground transition hover:bg-accent hover:text-foreground"
                :title="visible ? 'Hide password' : 'Show password'"
                :aria-label="visible ? 'Hide password' : 'Show password'"
                :aria-pressed="visible"
                @click="visible = ! visible"
            >
                <component :is="visible ? EyeOffIcon : EyeIcon" class="size-4" />
            </button>
        </div>
        <Button type="button" variant="outline" size="icon" class="shrink-0" title="Copy password" aria-label="Copy password" :disabled="! model" @click="copy">
            <component :is="copied ? CheckIcon : CopyIcon" class="size-4" />
        </Button>
        <Button type="button" variant="outline" class="shrink-0 gap-2" title="Generate a strong password" @click="generate(length)">
            <WandSparklesIcon class="size-4" />
            <span class="hidden sm:inline">Generate</span>
        </Button>
    </div>
</template>
