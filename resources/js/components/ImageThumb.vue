<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import Tooltip from '@/components/Tooltip.vue';

/**
 * A preview of an uploaded image with a red "X" delete badge in the corner. The
 * parent wires up what removal means (clear the field, call the server), so this
 * is reusable on any image/file field. Emits `remove` on click.
 */
withDefaults(defineProps<{ src: string; alt?: string; imgClass?: string; removable?: boolean }>(), {
    alt: '',
    imgClass: 'h-12 max-w-[12rem] object-contain',
    removable: true,
});
const emit = defineEmits<{ (e: 'remove'): void }>();
</script>

<template>
    <div class="flex items-start gap-3">
        <img :src="src" :alt="alt" :class="imgClass" />
        <Tooltip v-if="removable" :text="$t('common.remove')">
            <button type="button" :aria-label="$t('common.remove')"
                class="flex h-5 w-5 items-center justify-center rounded bg-red-600 text-white shadow hover:bg-red-700"
                @click="emit('remove')">
                <IconX :size="12" stroke-width="3" />
            </button>
        </Tooltip>
    </div>
</template>
