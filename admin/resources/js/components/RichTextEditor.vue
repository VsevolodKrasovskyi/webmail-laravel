<script setup>
import { onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import Image from '@tiptap/extension-image';
import {
    BoldIcon, ItalicIcon, UnderlineIcon, ListIcon, ListOrderedIcon, LinkIcon, ImageIcon, QuoteIcon,
} from '@lucide/vue';
import { Button } from '@/components/ui/button';

const props = defineProps({
    modelValue: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

function fileToDataUrl(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onload = () => resolve(reader.result);
        reader.onerror = reject;
        reader.readAsDataURL(file);
    });
}

async function insertImageFiles(files) {
    for (const file of files) {
        if (! file.type.startsWith('image/')) continue;
        const dataUrl = await fileToDataUrl(file);
        editor.value?.chain().focus().setImage({ src: dataUrl }).run();
    }
}

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Underline,
        Link.configure({ openOnClick: false }),
        Image,
    ],
    editorProps: {
        attributes: {
            class: 'prose prose-sm max-w-none min-h-[18rem] px-4 py-3 text-sm text-neutral-900 focus:outline-none',
        },
        handlePaste(view, event) {
            const files = Array.from(event.clipboardData?.files ?? []).filter((f) => f.type.startsWith('image/'));
            if (files.length) {
                insertImageFiles(files);
                return true;
            }
            return false;
        },
        handleDrop(view, event) {
            const files = Array.from(event.dataTransfer?.files ?? []).filter((f) => f.type.startsWith('image/'));
            if (files.length) {
                insertImageFiles(files);
                return true;
            }
            return false;
        },
    },
    onUpdate: ({ editor: e }) => {
        emit('update:modelValue', e.getHTML());
    },
});

watch(() => props.modelValue, (value) => {
    if (editor.value && value !== editor.value.getHTML()) {
        editor.value.commands.setContent(value, false);
    }
});

onBeforeUnmount(() => {
    editor.value?.destroy();
});
</script>

<template>
    <div class="overflow-hidden rounded-md border">
        <div class="flex flex-wrap items-center gap-0.5 border-b bg-muted/30 p-1.5">
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('bold') }" @click="editor.chain().focus().toggleBold().run()" title="Bold">
                <BoldIcon class="size-4" />
            </button>
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('italic') }" @click="editor.chain().focus().toggleItalic().run()" title="Italic">
                <ItalicIcon class="size-4" />
            </button>
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('underline') }" @click="editor.chain().focus().toggleUnderline().run()" title="Underline">
                <UnderlineIcon class="size-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-border" />
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('bulletList') }" @click="editor.chain().focus().toggleBulletList().run()" title="Bullet list">
                <ListIcon class="size-4" />
            </button>
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('orderedList') }" @click="editor.chain().focus().toggleOrderedList().run()" title="Numbered list">
                <ListOrderedIcon class="size-4" />
            </button>
            <button type="button" class="rounded p-1.5 hover:bg-accent" :class="{ 'bg-accent': editor?.isActive('blockquote') }" @click="editor.chain().focus().toggleBlockquote().run()" title="Quote">
                <QuoteIcon class="size-4" />
            </button>
            <span class="mx-1 h-5 w-px bg-border" />
            <button
                type="button"
                class="rounded p-1.5 hover:bg-accent"
                title="Link"
                @click="() => {
                    const url = window.prompt('Link URL');
                    if (url) editor.chain().focus().setLink({ href: url }).run();
                }"
            >
                <LinkIcon class="size-4" />
            </button>
            <label class="cursor-pointer rounded p-1.5 hover:bg-accent" title="Insert image">
                <ImageIcon class="size-4" />
                <input
                    type="file"
                    accept="image/*"
                    multiple
                    class="hidden"
                    @change="(e) => { insertImageFiles(Array.from(e.target.files ?? [])); e.target.value = ''; }"
                />
            </label>
        </div>
        <EditorContent :editor="editor" class="bg-white" />
    </div>
</template>
