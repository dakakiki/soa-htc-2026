<script setup lang="ts">
import { IconLock } from '@tabler/icons-vue';

/**
 * A field whose value the signed-in user cannot change — their country, and for
 * a venue coordinator also the region and venue. Rendered as a read-only box
 * rather than a disabled select: there is no list behind it to open.
 */
withDefaults(defineProps<{ value?: string | null; dense?: boolean }>(), {
    value: null,
    dense: false,
});
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-md border border-gray-200 bg-gray-50 text-sm text-gray-600"
        :class="dense ? 'px-3 py-1.5' : 'mt-1 px-3 py-2'"
    >
        <IconLock :size="14" class="shrink-0 text-gray-400" />
        <span class="truncate">{{ value || $t('common.dash') }}</span>
    </div>
</template>
