<script setup lang="ts">
import { IconSearch } from '@tabler/icons-vue';
import type { ArchiveBreakdownRow } from '@/api/archive';

const props = withDefaults(
    defineProps<{
        title: string;
        label: string;
        rows: ArchiveBreakdownRow[];
        truncated?: boolean;
        hint?: string;
        search?: string;
        headerClass?: string;
    }>(),
    { truncated: false, hint: '', search: '', headerClass: 'bg-brand-primary' }
);

const emit = defineEmits<{ (e: 'update:search', value: string): void }>();

const pct = (part: number, whole: number): string => (whole > 0 ? Math.round((100 * part) / whole) + '%' : '—');
</script>

<template>
    <div>
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="text-sm font-semibold text-gray-700">{{ title }}</h2>
            <span v-if="truncated" class="text-xs text-gray-400">{{ $t('archive.topSchools') }}</span>
            <span v-if="hint" class="text-xs text-gray-400">{{ hint }}</span>
            <div class="relative ml-auto">
                <IconSearch :size="15" class="pointer-events-none absolute left-2 top-1/2 -translate-y-1/2 text-gray-400" />
                <input :value="search" type="search" :placeholder="$t('archive.searchPlaceholder')"
                    class="w-48 rounded-md border border-gray-300 py-1 pl-7 pr-2 text-sm"
                    @input="emit('update:search', ($event.target as HTMLInputElement).value)">
            </div>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200">
            <div class="max-h-[28rem] overflow-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 text-left text-xs uppercase tracking-wide text-white" :class="headerClass">
                        <tr>
                            <th class="px-4 py-2">{{ label }}</th>
                            <th class="px-4 py-2 text-right">{{ $t('archive.registered') }}</th>
                            <th class="px-4 py-2 text-right">{{ $t('archive.participated') }}</th>
                            <th class="px-4 py-2 text-right">%</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="rows.length === 0">
                            <td colspan="4" class="px-4 py-6 text-center text-gray-400">{{ $t('archive.noRows') }}</td>
                        </tr>
                        <tr v-for="row in rows" :key="row.name" class="odd:bg-white even:bg-gray-50 hover:bg-brand-primary-soft">
                            <td class="px-4 py-2 text-gray-800">{{ row.name }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ row.registered.toLocaleString() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums text-gray-600">{{ row.participated.toLocaleString() }}</td>
                            <td class="px-4 py-2 text-right tabular-nums font-medium" :class="row.participated === 0 ? 'text-amber-600' : 'text-gray-900'">
                                {{ pct(row.participated, row.registered) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</template>
