<script setup lang="ts">
import { onBeforeUnmount, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import {
    IconBold, IconItalic, IconUnderline, IconH2,
    IconList, IconListNumbers, IconLink, IconClearFormatting,
} from '@tabler/icons-vue';

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), { placeholder: '' });
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const editor = useEditor({
    content: props.modelValue || '',
    extensions: [StarterKit, Underline, Link.configure({ openOnClick: false })],
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        // Treat an empty document as an empty string, not "<p></p>".
        emit('update:modelValue', editor.getText().trim() === '' ? '' : html);
    },
    editorProps: {
        attributes: { class: 'prose-content min-h-[8rem] px-3 py-2 focus:outline-none' },
    },
});

// Keep the editor in sync when the bound value changes from outside (e.g. edit load).
watch(
    () => props.modelValue,
    (value) => {
        if (!editor.value) return;
        const current = editor.value.getText().trim() === '' ? '' : editor.value.getHTML();
        if ((value || '') !== current) {
            editor.value.commands.setContent(value || '', { emitUpdate: false });
        }
    },
);

onBeforeUnmount(() => editor.value?.destroy());

const btn = (active: boolean): string =>
    `inline-flex h-8 w-8 items-center justify-center rounded ${active ? 'bg-brand-primary-soft text-brand-primary' : 'text-gray-600 hover:bg-gray-100'}`;

function setLink(): void {
    if (!editor.value) return;
    const previous = editor.value.getAttributes('link').href as string | undefined;
    const url = window.prompt('Link URL', previous ?? 'https://');
    if (url === null) return;
    if (url === '') {
        editor.value.chain().focus().unsetLink().run();
        return;
    }
    editor.value.chain().focus().extendMarkRange('link').setLink({ href: url }).run();
}
</script>

<template>
    <div class="rounded-md border border-gray-300 focus-within:border-brand-primary">
        <div v-if="editor" class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 p-1">
            <button type="button" :class="btn(editor.isActive('bold'))" title="Bold" @click="editor.chain().focus().toggleBold().run()"><IconBold :size="16" /></button>
            <button type="button" :class="btn(editor.isActive('italic'))" title="Italic" @click="editor.chain().focus().toggleItalic().run()"><IconItalic :size="16" /></button>
            <button type="button" :class="btn(editor.isActive('underline'))" title="Underline" @click="editor.chain().focus().toggleUnderline().run()"><IconUnderline :size="16" /></button>
            <span class="mx-1 h-5 w-px bg-gray-200" />
            <button type="button" :class="btn(editor.isActive('heading', { level: 2 }))" title="Heading" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"><IconH2 :size="16" /></button>
            <button type="button" :class="btn(editor.isActive('bulletList'))" title="Bullet list" @click="editor.chain().focus().toggleBulletList().run()"><IconList :size="16" /></button>
            <button type="button" :class="btn(editor.isActive('orderedList'))" title="Numbered list" @click="editor.chain().focus().toggleOrderedList().run()"><IconListNumbers :size="16" /></button>
            <button type="button" :class="btn(editor.isActive('link'))" title="Link" @click="setLink"><IconLink :size="16" /></button>
            <span class="mx-1 h-5 w-px bg-gray-200" />
            <button type="button" :class="btn(false)" title="Clear formatting" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"><IconClearFormatting :size="16" /></button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>

<style scoped>
/* Tailwind's reset strips list/heading styles, so restore them inside the editor. */
:deep(.prose-content) {
    font-size: 0.875rem;
    color: #111827;
}
:deep(.prose-content p) {
    margin: 0 0 0.5rem;
}
:deep(.prose-content h2) {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0.5rem 0;
}
:deep(.prose-content ul) {
    list-style: disc;
    padding-left: 1.5rem;
    margin: 0 0 0.5rem;
}
:deep(.prose-content ol) {
    list-style: decimal;
    padding-left: 1.5rem;
    margin: 0 0 0.5rem;
}
:deep(.prose-content a) {
    color: var(--color-brand-link, #2563eb);
    text-decoration: underline;
}
:deep(.prose-content:focus) {
    outline: none;
}
</style>
