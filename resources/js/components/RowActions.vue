<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from 'vue-router';
import { useI18n } from 'vue-i18n';
import { IconEye, IconPencil, IconTrash } from '@tabler/icons-vue';
import Tooltip from '@/components/Tooltip.vue';

defineProps<{
    viewTo?: RouteLocationRaw | null;
    editTo?: RouteLocationRaw | null;
    deletable?: boolean;
}>();

defineEmits<{ (e: 'delete'): void }>();

const { t } = useI18n();

const chip = 'inline-flex h-7 w-7 items-center justify-center rounded-md border border-gray-300 bg-gray-100 hover:bg-gray-200';
</script>

<template>
    <div class="flex items-center justify-end gap-1.5">
        <Tooltip v-if="viewTo" :text="t('common.view')">
            <RouterLink :to="viewTo" :aria-label="t('common.view')" :class="[chip, 'text-orange-500']">
                <IconEye :size="16" />
            </RouterLink>
        </Tooltip>
        <Tooltip v-if="editTo" :text="t('common.edit')">
            <RouterLink :to="editTo" :aria-label="t('common.edit')" :class="[chip, 'text-green-600']">
                <IconPencil :size="16" />
            </RouterLink>
        </Tooltip>
        <Tooltip v-if="deletable" :text="t('common.remove')">
            <button type="button" :aria-label="t('common.remove')" :class="[chip, 'text-red-600']" @click="$emit('delete')">
                <IconTrash :size="16" />
            </button>
        </Tooltip>
    </div>
</template>
