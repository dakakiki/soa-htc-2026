<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import { TextStyle, Color } from '@tiptap/extension-text-style';
import {
    IconBold, IconItalic, IconUnderline, IconH2,
    IconList, IconListNumbers, IconLink, IconClearFormatting, IconPalette,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import Tooltip from '@/components/Tooltip.vue';
import { useThemeStore } from '@/stores/theme';

const { t } = useI18n();

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), { placeholder: '' });
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const editor = useEditor({
    content: props.modelValue || '',
    // TextStyle + Color let text carry a colour (rendered as inline <span style="color">,
    // which mPDF also honours in the certificate PDF).
    extensions: [StarterKit, Underline, Link.configure({ openOnClick: false }), TextStyle, Color],
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

// Text-colour palette, drawn from the active brand theme so colours stay on-brand
// wherever this editor is used (and match the app's own colours in the PDF).
const theme = useThemeStore();
const colorSwatches = computed<{ label: string; value: string }[]>(() => {
    const c = theme.theme?.colors;
    return [
        { label: t('editor.colorPrimary'), value: c?.primary ?? '#2563eb' },
        { label: t('editor.colorPrimaryDark'), value: c?.primary_hover ?? '#1d4ed8' },
        { label: t('editor.colorAccent'), value: c?.accent ?? '#0d9488' },
        { label: t('editor.colorAccentDark'), value: c?.accent_hover ?? '#0f766e' },
        { label: t('editor.colorLink'), value: c?.link ?? '#2563eb' },
    ];
});

const showColors = ref(false);
function applyColor(value: string): void {
    editor.value?.chain().focus().setColor(value).run();
    showColors.value = false;
}
function clearColor(): void {
    editor.value?.chain().focus().unsetColor().run();
    showColors.value = false;
}
</script>

<template>
    <div class="rounded-md border border-gray-300 focus-within:border-brand-primary">
        <div v-if="editor" class="flex flex-wrap items-center gap-0.5 border-b border-gray-200 p-1">
            <Tooltip :text="t('editor.bold')"><button type="button" :class="btn(editor.isActive('bold'))" :aria-label="t('editor.bold')" @click="editor.chain().focus().toggleBold().run()"><IconBold :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.italic')"><button type="button" :class="btn(editor.isActive('italic'))" :aria-label="t('editor.italic')" @click="editor.chain().focus().toggleItalic().run()"><IconItalic :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.underline')"><button type="button" :class="btn(editor.isActive('underline'))" :aria-label="t('editor.underline')" @click="editor.chain().focus().toggleUnderline().run()"><IconUnderline :size="16" /></button></Tooltip>
            <span class="mx-1 h-5 w-px bg-gray-200" />
            <Tooltip :text="t('editor.heading')"><button type="button" :class="btn(editor.isActive('heading', { level: 2 }))" :aria-label="t('editor.heading')" @click="editor.chain().focus().toggleHeading({ level: 2 }).run()"><IconH2 :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.bulletList')"><button type="button" :class="btn(editor.isActive('bulletList'))" :aria-label="t('editor.bulletList')" @click="editor.chain().focus().toggleBulletList().run()"><IconList :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.numberedList')"><button type="button" :class="btn(editor.isActive('orderedList'))" :aria-label="t('editor.numberedList')" @click="editor.chain().focus().toggleOrderedList().run()"><IconListNumbers :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.link')"><button type="button" :class="btn(editor.isActive('link'))" :aria-label="t('editor.link')" @click="setLink"><IconLink :size="16" /></button></Tooltip>
            <div class="relative">
                <Tooltip :text="t('editor.textColour')"><button type="button" :class="btn(showColors)" :aria-label="t('editor.textColour')" @click="showColors = !showColors"><IconPalette :size="16" /></button></Tooltip>
                <div v-if="showColors" class="fixed inset-0 z-10" @click="showColors = false" />
                <div v-if="showColors" class="absolute left-0 top-9 z-20 w-44 rounded-md border border-gray-200 bg-white p-2 shadow-lg">
                    <div class="grid grid-cols-5 gap-1.5">
                        <button v-for="s in colorSwatches" :key="s.value" type="button" :aria-label="s.label" :title="s.label"
                            class="h-6 w-6 rounded border border-gray-200 transition hover:scale-110"
                            :style="{ backgroundColor: s.value }" @click="applyColor(s.value)" />
                    </div>
                    <button type="button" class="mt-2 flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs text-gray-600 hover:bg-gray-100" @click="clearColor">
                        <span class="h-4 w-4 rounded border border-gray-300 bg-white" />
                        {{ t('editor.defaultColour') }}
                    </button>
                </div>
            </div>
            <span class="mx-1 h-5 w-px bg-gray-200" />
            <Tooltip :text="t('editor.clearFormatting')"><button type="button" :class="btn(false)" :aria-label="t('editor.clearFormatting')" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"><IconClearFormatting :size="16" /></button></Tooltip>
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
