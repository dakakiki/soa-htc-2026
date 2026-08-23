<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useEditor, EditorContent } from '@tiptap/vue-3';
import StarterKit from '@tiptap/starter-kit';
import Underline from '@tiptap/extension-underline';
import Link from '@tiptap/extension-link';
import { TextStyle, Color } from '@tiptap/extension-text-style';
import Image from '@tiptap/extension-image';
import { TableKit } from '@tiptap/extension-table/kit';
import {
    IconBold, IconItalic, IconUnderline, IconH2, IconH3, IconH4,
    IconList, IconListNumbers, IconLink, IconClearFormatting, IconPalette,
    IconPhoto, IconTable, IconTablePlus, IconTableMinus, IconTableOff,
    IconColumnInsertRight, IconColumnRemove,
} from '@tabler/icons-vue';
import { useI18n } from 'vue-i18n';
import Tooltip from '@/components/Tooltip.vue';
import MediaPickerModal from '@/components/MediaPickerModal.vue';
import type { CmsMedia } from '@/types/models';
import { useThemeStore } from '@/stores/theme';

const { t } = useI18n();

/**
 * `rich` turns on the article toolset — images, tables and the smaller headings.
 * It is off everywhere else on purpose: a certificate or a question body has no
 * business holding a table, and the extra buttons would only be noise there.
 *
 * `minHeight` is a Tailwind class, because a page body needs far more room than
 * a one-paragraph field.
 */
const props = withDefaults(
    defineProps<{ modelValue: string; placeholder?: string; rich?: boolean; minHeight?: string }>(),
    { placeholder: '', rich: false, minHeight: 'min-h-[8rem]' },
);
const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const editor = useEditor({
    content: props.modelValue || '',
    // TextStyle + Color let text carry a colour (rendered as inline <span style="color">,
    // which mPDF also honours in the certificate PDF).
    extensions: [
        StarterKit,
        Underline,
        Link.configure({ openOnClick: false }),
        TextStyle,
        Color,
        // Only the article editor gets images and tables; see the `rich` prop.
        ...(props.rich
            ? [Image.configure({ inline: false }), TableKit.configure({ table: { resizable: true } })]
            : []),
    ],
    onUpdate: ({ editor }) => {
        const html = editor.getHTML();
        // Treat an empty document as an empty string, not "<p></p>".
        emit('update:modelValue', editor.getText().trim() === '' ? '' : html);
    },
    editorProps: {
        attributes: { class: `prose-content ${props.minHeight} px-3 py-2 focus:outline-none` },
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
        // The free palette slots from Theme settings — the house colours authors reach for.
        { label: t('editor.colorPalette1'), value: c?.palette_1 ?? '#fbba00' },
        { label: t('editor.colorPalette2'), value: c?.palette_2 ?? '#f39200' },
        { label: t('editor.colorPalette3'), value: c?.palette_3 ?? '#97bddd' },
        { label: t('editor.colorPalette4'), value: c?.palette_4 ?? '#003758' },
    ];
});

const showMedia = ref(false);

/** Insert a library image at the cursor, with its alt text. */
function insertImage(media: CmsMedia): void {
    editor.value?.chain().focus().setImage({ src: media.url, alt: media.alt ?? '' }).run();
    showMedia.value = false;
}

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
            <!-- The smaller headings only matter in a long article. -->
            <template v-if="rich">
                <Tooltip :text="t('editor.heading3')"><button type="button" :class="btn(editor.isActive('heading', { level: 3 }))" :aria-label="t('editor.heading3')" @click="editor.chain().focus().toggleHeading({ level: 3 }).run()"><IconH3 :size="16" /></button></Tooltip>
                <Tooltip :text="t('editor.heading4')"><button type="button" :class="btn(editor.isActive('heading', { level: 4 }))" :aria-label="t('editor.heading4')" @click="editor.chain().focus().toggleHeading({ level: 4 }).run()"><IconH4 :size="16" /></button></Tooltip>
            </template>
            <Tooltip :text="t('editor.bulletList')"><button type="button" :class="btn(editor.isActive('bulletList'))" :aria-label="t('editor.bulletList')" @click="editor.chain().focus().toggleBulletList().run()"><IconList :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.numberedList')"><button type="button" :class="btn(editor.isActive('orderedList'))" :aria-label="t('editor.numberedList')" @click="editor.chain().focus().toggleOrderedList().run()"><IconListNumbers :size="16" /></button></Tooltip>
            <Tooltip :text="t('editor.link')"><button type="button" :class="btn(editor.isActive('link'))" :aria-label="t('editor.link')" @click="setLink"><IconLink :size="16" /></button></Tooltip>
            <div class="relative">
                <Tooltip :text="t('editor.textColour')"><button type="button" :class="btn(showColors)" :aria-label="t('editor.textColour')" @click="showColors = !showColors"><IconPalette :size="16" /></button></Tooltip>
                <div v-if="showColors" class="fixed inset-0 z-10" @click="showColors = false" />
                <div v-if="showColors" class="absolute left-0 top-9 z-20 w-44 rounded-md border border-gray-200 bg-white p-2 shadow-lg">
                    <div class="grid grid-cols-5 gap-1.5">
                        <Tooltip v-for="s in colorSwatches" :key="s.label" :text="s.label">
                            <button type="button" :aria-label="s.label"
                                class="h-6 w-6 rounded border border-gray-200 transition hover:scale-110"
                                :style="{ backgroundColor: s.value }" @click="applyColor(s.value)" />
                        </Tooltip>
                    </div>
                    <button type="button" class="mt-2 flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs text-gray-600 hover:bg-gray-100" @click="clearColor">
                        <span class="h-4 w-4 rounded border border-gray-300 bg-white" />
                        {{ t('editor.defaultColour') }}
                    </button>
                </div>
            </div>
            <span class="mx-1 h-5 w-px bg-gray-200" />
            <template v-if="rich">
                <span class="mx-1 h-5 w-px bg-gray-200" />
                <Tooltip :text="t('editor.image')"><button type="button" :class="btn(showMedia)" :aria-label="t('editor.image')" @click="showMedia = true"><IconPhoto :size="16" /></button></Tooltip>
                <Tooltip :text="t('editor.table')"><button type="button" :class="btn(editor.isActive('table'))" :aria-label="t('editor.table')" @click="editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()"><IconTable :size="16" /></button></Tooltip>
                <!-- Row and column controls only mean anything inside a table. -->
                <template v-if="editor.isActive('table')">
                    <Tooltip :text="t('editor.addRow')"><button type="button" :class="btn(false)" :aria-label="t('editor.addRow')" @click="editor.chain().focus().addRowAfter().run()"><IconTablePlus :size="16" /></button></Tooltip>
                    <Tooltip :text="t('editor.deleteRow')"><button type="button" :class="btn(false)" :aria-label="t('editor.deleteRow')" @click="editor.chain().focus().deleteRow().run()"><IconTableMinus :size="16" /></button></Tooltip>
                    <Tooltip :text="t('editor.addColumn')"><button type="button" :class="btn(false)" :aria-label="t('editor.addColumn')" @click="editor.chain().focus().addColumnAfter().run()"><IconColumnInsertRight :size="16" /></button></Tooltip>
                    <Tooltip :text="t('editor.deleteColumn')"><button type="button" :class="btn(false)" :aria-label="t('editor.deleteColumn')" @click="editor.chain().focus().deleteColumn().run()"><IconColumnRemove :size="16" /></button></Tooltip>
                    <Tooltip :text="t('editor.deleteTable')"><button type="button" :class="btn(false)" :aria-label="t('editor.deleteTable')" @click="editor.chain().focus().deleteTable().run()"><IconTableOff :size="16" /></button></Tooltip>
                </template>
            </template>
            <Tooltip :text="t('editor.clearFormatting')"><button type="button" :class="btn(false)" :aria-label="t('editor.clearFormatting')" @click="editor.chain().focus().unsetAllMarks().clearNodes().run()"><IconClearFormatting :size="16" /></button></Tooltip>
        </div>
        <EditorContent :editor="editor" />

        <MediaPickerModal v-if="showMedia" @close="showMedia = false" @select="insertImage" />
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
/* Images and tables exist only in the `rich` editor, but the rules are harmless
   elsewhere: without those extensions the nodes never appear. */
:deep(.prose-content img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.375rem;
}
:deep(.prose-content table) {
    width: 100%;
    border-collapse: collapse;
    margin: 0 0 0.5rem;
    table-layout: fixed;
}
:deep(.prose-content th),
:deep(.prose-content td) {
    border: 1px solid #d1d5db;
    padding: 0.35rem 0.5rem;
    vertical-align: top;
    position: relative;
}
:deep(.prose-content th) {
    background: #f9fafb;
    font-weight: 600;
    text-align: left;
}
/* The cell the caret is in, so row/column buttons act somewhere visible. */
:deep(.prose-content .selectedCell) {
    background: var(--color-brand-primary-soft, #eff6ff);
}
:deep(.prose-content .column-resize-handle) {
    position: absolute;
    right: -2px;
    top: 0;
    bottom: 0;
    width: 4px;
    background: var(--color-brand-primary, #2563eb);
    cursor: col-resize;
}
</style>
