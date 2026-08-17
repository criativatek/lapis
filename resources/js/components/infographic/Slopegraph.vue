<script setup lang="ts">
import { computed } from 'vue';
import { formatPoints, pct, prefersReducedMotion } from '@/lib/chartTheme';

/**
 * Two moments, joined.
 *
 * A LINE CHART OF TWO POINTS IS MOSTLY EMPTY AXIS. It spends a tall box and a
 * 0–100 scale to draw one segment, and the thing the reader actually wants —
 * how far it moved — is left to be estimated off a grid. A slopegraph gives the
 * segment the whole box, writes both values at their ends and the difference in
 * the middle, and reads in one glance.
 *
 * SVG rather than a canvas, so every series is a real element: focusable,
 * hoverable, and describable without a parallel table beside it.
 *
 * THE SLOPE IS FAITHFUL. Both ends are placed on the same 0–100 scale, so the
 * steepness of a line is the size of its change and two lines can be compared
 * against each other. Nothing is exaggerated to look more dramatic.
 */

export type Slope = {
    id: number;
    label: string;
    /** 0–100. Null at either end means there is nothing to join. */
    from: number | null;
    to: number | null;
    colour: string;
    /** The mention or other qualifier shown at the right end, if any. */
    badge?: string;
    badgeClass?: string;
};

const props = withDefaults(
    defineProps<{
        slopes: Slope[];
        fromLabel: string;
        toLabel: string;
        selectedId?: number | null;
        summary: string;
        /** Shows the label of each series beside its left point. */
        showLabels?: boolean;
    }>(),
    { selectedId: null, showLabels: true },
);

const emit = defineEmits<{ (event: 'select', id: number): void }>();

/** The drawing box, in the SVG's own units. */
const HEIGHT = 260;
const TOP = 18;
const BOTTOM = 18;

/** 0–100 becomes a y, with 100 at the top. */
function yOf(value: number): number {
    return TOP + (1 - value / 100) * (HEIGHT - TOP - BOTTOM);
}

const drawn = computed(() => props.slopes.filter((slope) => slope.from !== null && slope.to !== null));

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}

function change(slope: Slope): number | null {
    return slope.from === null || slope.to === null ? null : slope.to - slope.from;
}
</script>

<template>
    <div>
        <div class="flex items-start gap-3">
            <!-- Left column: where each series started. -->
            <div class="hidden w-36 shrink-0 pt-[18px] sm:block">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ fromLabel }}</p>
            </div>

            <div class="relative min-w-0 flex-1">
                <svg
                    :viewBox="`0 0 100 ${HEIGHT}`"
                    preserveAspectRatio="none"
                    class="h-[260px] w-full overflow-visible"
                    role="img"
                    :aria-label="summary"
                >
                    <!-- The two moments, as uprights. Structure, not data. -->
                    <line x1="0" :y1="TOP - 10" x2="0" :y2="HEIGHT - BOTTOM + 10" class="stroke-border" stroke-width="1" vector-effect="non-scaling-stroke" />
                    <line x1="100" :y1="TOP - 10" x2="100" :y2="HEIGHT - BOTTOM + 10" class="stroke-border" stroke-width="1" vector-effect="non-scaling-stroke" />

                    <g v-for="slope in drawn" :key="slope.id" :opacity="isLit(slope.id) ? 1 : 0.2">
                        <line
                            x1="0"
                            :y1="yOf(slope.from!)"
                            x2="100"
                            :y2="yOf(slope.to!)"
                            :stroke="slope.colour"
                            :stroke-width="selectedId === slope.id ? 3.5 : 2.25"
                            stroke-linecap="round"
                            vector-effect="non-scaling-stroke"
                            :class="prefersReducedMotion() ? '' : 'transition-all duration-300'"
                        />
                        <circle cx="0" :cy="yOf(slope.from!)" r="4" :fill="slope.colour" vector-effect="non-scaling-stroke" />
                        <circle cx="100" :cy="yOf(slope.to!)" r="5" :fill="slope.colour" vector-effect="non-scaling-stroke" />
                    </g>
                </svg>
            </div>

            <div class="hidden w-36 shrink-0 pt-[18px] text-right sm:block">
                <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ toLabel }}</p>
            </div>
        </div>

        <!--
          The readable half. Every series as a row: its ink, its name, both
          values and the change between them — which is what a reader wants and
          what an SVG cannot spell out on its own.
        -->
        <ul class="mt-4 space-y-1">
            <li v-for="slope in slopes" :key="slope.id">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 rounded-lg px-2 py-1.5 text-left transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="[
                        isLit(slope.id) ? 'opacity-100' : 'opacity-40',
                        selectedId === slope.id ? 'bg-muted/50' : 'hover:bg-muted/30',
                    ]"
                    :aria-pressed="selectedId === slope.id"
                    @click="emit('select', slope.id)"
                >
                    <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: slope.colour }"></span>
                    <span v-if="showLabels" class="min-w-0 flex-1 truncate text-xs font-medium">{{ slope.label }}</span>

                    <span class="shrink-0 text-xs tabular-nums text-muted-foreground">{{ pct(slope.from === null ? null : String(slope.from)) }}</span>
                    <span aria-hidden="true" class="shrink-0 text-muted-foreground/50">→</span>
                    <span class="shrink-0 text-xs font-semibold tabular-nums">{{ pct(slope.to === null ? null : String(slope.to)) }}</span>

                    <span
                        v-if="change(slope) !== null"
                        class="w-24 shrink-0 text-right text-xs font-medium tabular-nums"
                        :class="change(slope)! > 0 ? 'text-emerald-600 dark:text-emerald-400'
                            : change(slope)! < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-muted-foreground'"
                    >
                        {{ change(slope)! > 0 ? '↑' : change(slope)! < 0 ? '↓' : '→' }}
                        {{ formatPoints(change(slope)) }} p.p.
                    </span>
                    <span v-else class="w-24 shrink-0 text-right text-[11px] text-muted-foreground">sem comparação</span>

                    <span
                        v-if="slope.badge"
                        class="hidden shrink-0 rounded-md px-2 py-0.5 text-[11px] font-medium sm:inline-block"
                        :class="slope.badgeClass"
                    >{{ slope.badge }}</span>
                </button>
            </li>
        </ul>
    </div>
</template>
