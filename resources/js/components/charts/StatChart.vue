<script setup lang="ts">
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import type { ChartConfiguration } from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { animation, chromeColours, externalTooltipHandler, prefersReducedMotion } from '@/lib/chartTheme';
import type { TooltipResolver, TooltipState } from '@/lib/chartTheme';

/**
 * One chart, dressed in LÁPIS and readable without seeing it.
 *
 * THREE THINGS TRAVEL TOGETHER HERE.
 *
 * The canvas is what most people look at. The tooltip is REAL HTML rather than
 * Chart.js's own — a canvas-drawn tooltip cannot align a column of values or
 * set a label and its figure in two different weights, and that alignment is
 * most of what makes the difference between reading a chart and decoding one.
 * And the table underneath, visually hidden but never hidden from assistive
 * technology, carries the same numbers in words: a canvas is one opaque image
 * to a screen reader, and «gráfico de barras» tells nobody how a class is doing.
 *
 * The chart redraws when the theme changes, because a canvas cannot inherit a
 * CSS variable and the tokens it was drawn with have just been replaced.
 */

// Registered by hand rather than with ...registerables: only what these charts
// use is pulled into the bundle.
Chart.register(
    BarController, BarElement, LineController, LineElement, PointElement,
    ArcElement, CategoryScale, LinearScale, Filler, Tooltip,
);

const props = withDefaults(
    defineProps<{
        config: ChartConfiguration;
        /** Builds the tooltip for a point. Returning null hides it. */
        tooltip?: TooltipResolver;
        /** What this chart is, in one sentence, for anyone who cannot see it. */
        summary: string;
        headers: string[];
        rows: (string | number)[][];
        heightClass?: string;
    }>(),
    { heightClass: 'h-64', tooltip: undefined },
);

const emit = defineEmits<{ (event: 'select', dataIndex: number, datasetIndex: number): void }>();

const canvas = ref<HTMLCanvasElement | null>(null);
const chart = shallowRef<Chart | null>(null);

const state = ref<TooltipState>({ visible: false, x: 0, y: 0, content: null });

/** The caller's config, with the theme, the motion preference and the tooltip. */
const themed = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const config = props.config;

    return {
        ...config,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: animation(),
            ...config.options,
            plugins: {
                legend: { display: false },
                ...config.options?.plugins,
                tooltip: {
                    enabled: false,
                    position: 'nearest',
                    ...config.options?.plugins?.tooltip,
                    external: props.tooltip === undefined
                        ? undefined
                        : externalTooltipHandler(props.tooltip, (next) => (state.value = next)),
                },
            },
        },
        // Void the chrome reference so a theme change invalidates this computed
        // even when the caller's config object is unchanged.
        __chrome: chrome.surface,
    } as unknown as ChartConfiguration;
});

function draw(): void {
    if (canvas.value === null) {
        return;
    }

    chart.value?.destroy();
    chart.value = new Chart(canvas.value, themed.value);
}

/** A click on a bar or a point is a selection, not just a hover. */
function onClick(event: MouseEvent): void {
    if (chart.value === null) {
        return;
    }

    const points = chart.value.getElementsAtEventForMode(event, 'nearest', { intersect: true }, false);

    if (points.length > 0) {
        emit('select', points[0].index, points[0].datasetIndex);
    }
}

let observer: MutationObserver | null = null;

onMounted(() => {
    draw();

    // The theme is a class on <html>, so the only reliable signal that it moved
    // is the class moving. Watching it keeps the canvas honest without asking
    // every page to remember to tell us.
    if (typeof MutationObserver === 'function') {
        observer = new MutationObserver(() => draw());
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    }
});

watch(() => props.config, draw, { deep: true });

onBeforeUnmount(() => {
    observer?.disconnect();
    chart.value?.destroy();
    chart.value = null;
});
</script>

<template>
    <div class="relative">
        <div :class="heightClass" class="relative">
            <canvas
                ref="canvas"
                role="img"
                :aria-label="summary"
                @click="onClick"
                @mouseleave="state.visible = false"
            ></canvas>

            <!--
              The tooltip. Positioned over the canvas, never inside it, so the
              values can sit in an aligned column and the label and the figure
              can be set in two different weights (§3).
            -->
            <Transition
                :enter-active-class="prefersReducedMotion() ? '' : 'transition duration-100 ease-out'"
                enter-from-class="opacity-0 translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
                :leave-active-class="prefersReducedMotion() ? '' : 'transition duration-75 ease-in'"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div
                    v-if="state.visible && state.content"
                    class="pointer-events-none absolute z-30 w-max max-w-[17rem] -translate-x-1/2 -translate-y-full rounded-xl border border-border/80 bg-popover/95 p-3 shadow-lg ring-1 ring-black/5 backdrop-blur-sm dark:ring-white/10"
                    :style="{ left: `${state.x}px`, top: `${state.y - 12}px` }"
                >
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-foreground">
                        {{ state.content.title }}
                    </p>
                    <p v-if="state.content.subtitle" class="mt-0.5 text-[11px] text-muted-foreground">
                        {{ state.content.subtitle }}
                    </p>

                    <!-- Label left, value right, always in the same columns —
                         that alignment is what makes six figures scannable. -->
                    <dl class="mt-2 space-y-1">
                        <div v-for="row in state.content.rows" :key="row.label" class="flex items-baseline justify-between gap-6">
                            <dt class="flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                <span
                                    v-if="row.swatch"
                                    class="size-2 shrink-0 rounded-full"
                                    :style="{ backgroundColor: row.swatch }"
                                ></span>
                                {{ row.label }}
                            </dt>
                            <dd
                                class="text-xs tabular-nums"
                                :class="[
                                    row.strong ? 'font-semibold text-foreground' : 'font-medium text-foreground/90',
                                    row.trend === 'up' ? 'text-emerald-600 dark:text-emerald-400' : '',
                                    row.trend === 'down' ? 'text-rose-600 dark:text-rose-400' : '',
                                ]"
                            >
                                {{ row.value }}
                            </dd>
                        </div>
                    </dl>

                    <p v-if="state.content.footer" class="mt-2 border-t border-border/60 pt-1.5 text-[10px] text-muted-foreground">
                        {{ state.content.footer }}
                    </p>
                </div>
            </Transition>
        </div>

        <!-- The same numbers, in words. `sr-only` and not `hidden`: this is FOR
             reading, just not with the eyes. -->
        <table class="sr-only">
            <caption>{{ summary }}</caption>
            <thead>
                <tr>
                    <th v-for="header in headers" :key="header" scope="col">{{ header }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in rows" :key="index">
                    <th scope="row">{{ row[0] }}</th>
                    <td v-for="(cell, column) in row.slice(1)" :key="column">{{ cell }}</td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
