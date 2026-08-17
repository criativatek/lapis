<script setup lang="ts">
import {
    ArcElement,
    BarController,
    BarElement,
    CategoryScale,
    Chart,
    Filler,
    Legend,
    LineController,
    LineElement,
    LinearScale,
    PointElement,
    Tooltip,
} from 'chart.js';
import type { ChartConfiguration } from 'chart.js';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { animationOptions, chromeColours } from '@/lib/charts';

/**
 * One chart, dressed in the page's own tokens and readable without seeing it.
 *
 * TWO THINGS TRAVEL TOGETHER HERE, and both matter. The canvas is what most
 * people look at. The table underneath it — visually hidden, never hidden from
 * assistive technology — is the same data in words, because a canvas is one
 * opaque image to a screen reader and «gráfico de barras» tells nobody how the
 * class is doing (§42).
 *
 * The chart re-renders when the theme changes: a canvas cannot inherit a CSS
 * variable, so the neutral chrome is read from the document at draw time and
 * has to be read again when the document changes underneath it.
 */

// Registered by hand rather than with Chart.register(...registerables): only
// what these charts use is pulled into the bundle.
Chart.register(
    BarController, BarElement, LineController, LineElement, PointElement,
    ArcElement, CategoryScale, LinearScale, Filler, Legend, Tooltip,
);

const props = withDefaults(
    defineProps<{
        /** The chart.js configuration, already themed by the calling chart. */
        config: ChartConfiguration;
        /** What this chart is, in one sentence, for anyone who cannot see it. */
        summary: string;
        /** Column headers of the textual equivalent. */
        headers: string[];
        /** The same data as rows of words and numbers. */
        rows: (string | number)[][];
        /** Tailwind height class — charts differ in how much room they need. */
        heightClass?: string;
    }>(),
    { heightClass: 'h-64' },
);

const canvas = ref<HTMLCanvasElement | null>(null);
const chart = shallowRef<Chart | null>(null);

/** The caller's config, with the chrome and the motion preference applied. */
const themed = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const config = props.config;

    return {
        ...config,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: animationOptions(),
            ...config.options,
            plugins: {
                legend: { display: false },
                ...config.options?.plugins,
                tooltip: {
                    backgroundColor: chrome.surface,
                    titleColor: chrome.text,
                    bodyColor: chrome.muted,
                    borderColor: chrome.grid,
                    borderWidth: 1,
                    padding: 10,
                    cornerRadius: 8,
                    displayColors: false,
                    titleFont: { weight: 600, size: 12 },
                    bodyFont: { size: 12 },
                    ...config.options?.plugins?.tooltip,
                },
            },
        },
    } as ChartConfiguration;
});

function draw(): void {
    if (canvas.value === null) {
        return;
    }

    chart.value?.destroy();
    chart.value = new Chart(canvas.value, themed.value);
}

/**
 * The theme is a class on <html>, so the only reliable signal that it changed
 * is the class changing. Watching it keeps the canvas honest without asking
 * every page to remember to tell us.
 */
let observer: MutationObserver | null = null;

onMounted(() => {
    draw();

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
    <div>
        <div :class="heightClass" class="relative">
            <canvas ref="canvas" role="img" :aria-label="summary"></canvas>
        </div>

        <!--
          The same numbers, in words. `sr-only` and not `hidden`: this is FOR
          reading, just not with the eyes. It is also what a keyboard reaches,
          since a canvas has nothing inside it to focus.
        -->
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
