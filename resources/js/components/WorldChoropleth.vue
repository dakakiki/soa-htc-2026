<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * Students per country on a world map.
 *
 * Countries are matched on ISO 3166-1 numeric (`countries.iso_numeric`), which is
 * exactly what the world-atlas geometry uses as its feature id — no name matching,
 * no lookup table. Geometry, projection and the topojson reader are imported on
 * mount so the ~110 KB dataset stays out of the main bundle.
 */
export interface CountryMapRow {
    iso: number;
    name: string;
    students: number;
    venues: number;
    submitted: number;
}

const props = defineProps<{ rows: CountryMapRow[] }>();

const { t, n } = useI18n();

interface Shape {
    id: number;
    d: string;
    row: CountryMapRow | null;
    bucket: number;
}

const shapes = shallowRef<Shape[]>([]);
const loading = ref(true);
const failed = ref(false);

const WIDTH = 960;
const HEIGHT = 480;

const byIso = computed(() => new Map(props.rows.map((r) => [r.iso, r])));

/**
 * Five buckets cut at quantiles of the countries that actually have students —
 * fixed thresholds would put almost everything in the first bucket, since a
 * handful of countries carry most of the roster.
 */
const thresholds = computed<number[]>(() => {
    const values = props.rows.map((r) => r.students).filter((v) => v > 0).sort((a, b) => a - b);
    if (values.length === 0) {
        return [];
    }
    return [0.2, 0.4, 0.6, 0.8].map((q) => values[Math.floor(q * (values.length - 1))]);
});

function bucketOf(students: number): number {
    if (students <= 0) {
        return 0;
    }
    const cuts = thresholds.value;
    let bucket = 1;
    for (const cut of cuts) {
        if (students > cut) {
            bucket += 1;
        }
    }
    return Math.min(bucket, 5);
}

/* ---- hover ---- */
const hover = ref<{ row: CountryMapRow; x: number; y: number } | null>(null);

function onEnter(shape: Shape, event: MouseEvent): void {
    if (!shape.row) {
        hover.value = null;
        return;
    }
    const box = (event.currentTarget as SVGPathElement).ownerSVGElement?.getBoundingClientRect();
    hover.value = {
        row: shape.row,
        x: box ? event.clientX - box.left : 0,
        y: box ? event.clientY - box.top : 0,
    };
}

function onMove(event: MouseEvent): void {
    if (!hover.value) {
        return;
    }
    const box = (event.currentTarget as SVGElement).getBoundingClientRect();
    hover.value = { ...hover.value, x: event.clientX - box.left, y: event.clientY - box.top };
}

const turnout = (row: CountryMapRow): string =>
    row.students === 0 ? '—' : `${Math.round((row.submitted / row.students) * 100)}%`;

onMounted(async () => {
    try {
        const [{ geoNaturalEarth1, geoPath }, { feature }, topology] = await Promise.all([
            import('d3-geo'),
            import('topojson-client'),
            import('world-atlas/countries-110m.json'),
        ]);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const collection = feature(topology as any, (topology as any).objects.countries) as any;
        const projection = geoNaturalEarth1().fitSize([WIDTH, HEIGHT], collection);
        const path = geoPath(projection);

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        shapes.value = collection.features.map((f: any): Shape => {
            const id = Number(f.id);
            const row = byIso.value.get(id) ?? null;
            return { id, d: path(f) ?? '', row, bucket: bucketOf(row?.students ?? 0) };
        });
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <div class="relative">
        <div v-if="loading" class="flex h-64 items-center justify-center text-sm text-gray-400">
            {{ t('common.loading') }}
        </div>

        <p v-else-if="failed" class="flex h-64 items-center justify-center text-sm text-gray-400">
            {{ t('dashboard.map.failed') }}
        </p>

        <template v-else>
            <svg :viewBox="`0 0 ${WIDTH} ${HEIGHT}`" class="w-full" role="img"
                :aria-label="t('dashboard.map.title')" @mousemove="onMove" @mouseleave="hover = null">
                <path v-for="shape in shapes" :key="shape.id" :d="shape.d"
                    class="shape" :class="`q${shape.bucket}`"
                    @mouseenter="onEnter(shape, $event)" />
            </svg>

            <!-- Tooltip rides the pointer inside the map box. -->
            <div v-if="hover" class="tip" :style="{ left: `${hover.x}px`, top: `${hover.y}px` }">
                <p class="font-medium">{{ hover.row.name }}</p>
                <p>{{ t('dashboard.map.students') }}: <b>{{ n(hover.row.students) }}</b></p>
                <p>{{ t('dashboard.map.venues') }}: {{ n(hover.row.venues) }} · {{ t('dashboard.map.turnout') }}: {{ turnout(hover.row) }}</p>
            </div>

            <div class="mt-2 flex flex-wrap items-center gap-3 text-xs text-gray-500">
                <span>{{ t('dashboard.map.legend') }}</span>
                <span class="flex items-center gap-1">
                    <i class="key q1"></i><i class="key q2"></i><i class="key q3"></i><i class="key q4"></i><i class="key q5"></i>
                </span>
                <span v-if="thresholds.length">1 – {{ n(thresholds[0]) }} … {{ n(thresholds[3]) }}+</span>
                <span class="flex items-center gap-1"><i class="key q0"></i>{{ t('dashboard.map.none') }}</span>
            </div>
        </template>
    </div>
</template>

<style scoped>
/*
 * A single-hue ramp built from the brand blues: light sky for the smallest
 * countries, brand navy (palette slot 4) for the largest. The admin is a
 * light-only UI, so the ramp does not follow the OS colour scheme — keying it
 * on `prefers-color-scheme` inverted the map on dark-mode machines.
 */
.shape {
    stroke: var(--color-brand-border, #e5e7eb);
    stroke-width: .5;
    transition: opacity .1s;
}
.shape:hover { opacity: .75; }

.q0 { fill: #e8eef3; }
.q1 { fill: #dce9f4; }
.q2 { fill: #b9d3e8; }
.q3 { fill: #7fb0d3; }
.q4 { fill: #3b7ba4; }
.q5 { fill: var(--color-brand-palette-4, #003758); }

.key {
    display: inline-block; width: 14px; height: 10px; border-radius: 2px;
    border: 1px solid rgba(0, 0, 0, .06);
}
.key.q0 { background: #e8eef3; }
.key.q1 { background: #dce9f4; }
.key.q2 { background: #b9d3e8; }
.key.q3 { background: #7fb0d3; }
.key.q4 { background: #3b7ba4; }
.key.q5 { background: var(--color-brand-palette-4, #003758); }

.tip {
    position: absolute; pointer-events: none; transform: translate(12px, -50%);
    background: #ffffff; border: 1px solid var(--color-brand-border, #e5e7eb);
    border-radius: 6px; box-shadow: 0 6px 20px -8px rgba(13, 36, 48, .35);
    padding: 8px 10px; font-size: 12px; line-height: 1.45; white-space: nowrap; color: #1f2937;
}
</style>
