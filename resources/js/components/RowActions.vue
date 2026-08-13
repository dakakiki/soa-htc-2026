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
</script>

<template>
    <div class="flex items-center justify-end gap-3">
        <RouterLink
            :to="viewTo"
            :title="t('common.view')"
            :aria-label="t('common.view')"
            class="text-orange-500 hover:text-orange-600"
        >
            <IconEye :size="18" />
        </RouterLink>
        <RouterLink
            v-if="editTo"
            :to="editTo"
            :title="t('common.edit')"
            :aria-label="t('common.edit')"
            class="text-green-600 hover:text-green-700"
        >
            <IconPencil :size="18" />
        </RouterLink>
        <button
            v-if="deletable"
            type="button"
            :title="t('common.remove')"
            :aria-label="t('common.remove')"
            class="text-red-600 hover:text-red-700"
            @click="$emit('delete')"
        >
            <IconTrash :size="18" />
        </button>
    </div>
</template>
