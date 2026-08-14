<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconEye, IconPencil, IconTrash } from '@tabler/icons-vue';

defineProps<{
    viewTo: RouteLocationRaw;
    editTo?: RouteLocationRaw | null;
    deletable?: boolean;
}>();

defineEmits<{ (e: 'delete'): void }>();

const { t } = useI18n();

const chip = 'inline-flex h-9 w-9 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';
</script>

<template>
    <div class="flex items-center justify-end gap-2">
        <RouterLink
            :to="viewTo"
            :title="t('common.view')"
            :aria-label="t('common.view')"
            :class="[chip, 'text-orange-500']"
        >
            <IconEye :size="18" />
        </RouterLink>
        <RouterLink
            v-if="editTo"
            :to="editTo"
            :title="t('common.edit')"
            :aria-label="t('common.edit')"
            :class="[chip, 'text-green-600']"
        >
            <IconPencil :size="18" />
        </RouterLink>
        <button
            v-if="deletable"
            type="button"
            :title="t('common.remove')"
            :aria-label="t('common.remove')"
            :class="[chip, 'text-red-600']"
            @click="$emit('delete')"
        >
            <IconTrash :size="18" />
        </button>
    </div>
</template>
