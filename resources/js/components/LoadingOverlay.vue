<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{ message?: string }>();

const { t } = useI18n();
</script>

<template>
    <div class="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-white/70">
        <div class="flex items-center gap-2 text-sm font-medium text-gray-600">
            <svg class="h-6 w-6 animate-spin text-blue-600" viewBox="0 0 24 24" fill="none">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
            </svg>
            <span>{{ message || t('common.loading') }}</span>
        </div>
    </div>
</template>
