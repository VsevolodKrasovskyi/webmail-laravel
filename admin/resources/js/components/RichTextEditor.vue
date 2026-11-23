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

// Image with an adjustable width; the width goes into the HTML so it survives sending.
const ResizableImage = Image.extend({
    addNodeView() {
        return ({ node: initialNode, editor: ed, getPos }) => {
            let node = initialNode;

            const dom = document.createElement('span');
            dom.className = 'rte-img';

            const img = document.createElement('img');
            const label = document.createElement('span');
            label.className = 'rte-img-label';
            dom.append(img, label);

            const render = () => {
                img.src = node.attrs.src;
                img.alt = node.attrs.alt ?? '';
                img.style.width = node.attrs.width ? `${node.attrs.width}px` : '';
            };
            render();

            // Corner handles: dragging keeps the aspect ratio, the width is saved on release.
            ['nw', 'ne', 'sw', 'se'].forEach((corner) => {
                const handle = document.createElement('span');
                handle.className = `rte-img-handle rte-img-handle-${corner}`;
                handle.addEventListener('pointerdown', (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    handle.setPointerCapture(event.pointerId);

                    const startX = event.clientX;
                    const startWidth = img.getBoundingClientRect().width;
                    const direction = corner.includes('w') ? -1 : 1;
                    let width = startWidth;

                    const showLabel = () => {
                        const rect = img.getBoundingClientRect();
                        label.textContent = `${Math.round(rect.width)} x ${Math.round(rect.height)}`;
                    };

                    const onMove = (e) => {
                        width = Math.max(30, startWidth + direction * (e.clientX - startX));
                        img.style.width = `${Math.round(width)}px`;
                        showLabel();
                    };

                    const onUp = () => {
                        handle.removeEventListener('pointermove', onMove);
                        handle.removeEventListener('pointerup', onUp);
                        label.textContent = '';
                        const pos = getPos();
                        if (typeof pos !== 'number') return;
                        ed.view.dispatch(ed.view.state.tr.setNodeMarkup(pos, undefined, { ...node.attrs, width: Math.round(width) }));
                    };

                    handle.addEventListener('pointermove', onMove);
                    handle.addEventListener('pointerup', onUp);
                    showLabel();
                });
                dom.append(handle);
            });

            return {
                dom,
                update(updated) {
                    if (updated.type !== node.type) return false;
                    node = updated;
                    render();
                    return true;
                },
                stopEvent: (event) => event.target instanceof HTMLElement && event.target.classList.contains('rte-img-handle'),
                ignoreMutation: () => true,
            };
        };
    },
    addAttributes() {
        return {
            ...this.parent?.(),
            width: {
                default: null,
                parseHTML: (el) => el.getAttribute('width') || el.style.width?.replace('px', '') || null,
                renderHTML: (attrs) => (attrs.width
                    ? { width: attrs.width, style: `width:${attrs.width}px;height:auto;max-width:100%` }
                    : { style: 'max-width:100%;height:auto' }),
            },
        };
    },
});

const editor = useEditor({
    content: props.modelValue,
    extensions: [
        StarterKit,
        Underline,
        Link.configure({ openOnClick: false }),
        ResizableImage,
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

<style>
.rte-img {
    position: relative;
    display: inline-block;
    max-width: 100%;
    line-height: 0;
}

.rte-img img {
    max-width: 100%;
    height: auto;
}

.rte-img.ProseMirror-selectednode img {
    outline: 1.5px solid #4f46e5;
}

.rte-img-handle {
    display: none;
    position: absolute;
    width: 9px;
    height: 9px;
    border: 1.5px solid #4f46e5;
    border-radius: 50%;
    background: #fff;
    touch-action: none;
}

.rte-img.ProseMirror-selectednode .rte-img-handle {
    display: block;
}

.rte-img-handle-nw { top: -5px; left: -5px; cursor: nwse-resize; }
.rte-img-handle-ne { top: -5px; right: -5px; cursor: nesw-resize; }
.rte-img-handle-sw { bottom: -5px; left: -5px; cursor: nesw-resize; }
.rte-img-handle-se { bottom: -5px; right: -5px; cursor: nwse-resize; }

.rte-img-label {
    position: absolute;
    top: 6px;
    right: 6px;
    border-radius: 3px;
    background: rgba(0, 0, 0, 0.75);
    padding: 1px 5px;
    font-size: 10px;
    line-height: 1.4;
    color: #fff;
    pointer-events: none;
}

.rte-img-label:empty {
    display: none;
}
</style>
