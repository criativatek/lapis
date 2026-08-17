<script setup lang="ts">
import { ref } from 'vue';
import { prefersReducedMotion, shade } from '@/lib/chartTheme';
import type { TooltipContent } from '@/lib/chartTheme';

/**
 * Horizontal values drawn as folded ribbons instead of as bars.
 *
 * NOT A CANVAS, on purpose. A ribbon with a pointed tip, a fold at its tail and
 * a lit upper edge is three lines of CSS and a fight in a drawing context — and
 * building it in the DOM buys the accessibility back for free: every value is
 * real text, every row is a real button, and a screen reader needs no parallel
 * table to be told what is here.
 *
 * THE LENGTH IS THE VALUE AND NOTHING ELSE. The ribbon's width is the
 * percentage itself, and the tip is clipped OUT of that width rather than added
 * to it, so the furthest point of the shape sits exactly where a plain bar
 * would have ended. The fold, the shading and the shadow extend nothing.
 */

export type RibbonRow = {
    id: number;
    label: string;
    /** 0–100, or null when there is no value. Never a zero standing in for one. */
    value: number | null;
    /** Shown at the end of the ribbon, already formatted. */
    display: string;
    /** The performance tone this row is drawn in. */
    colour: string;
    tooltip: TooltipContent;
};

const props = withDefaults(
    defineProps<{
        rows: RibbonRow[];
        /** The row currently followed through the page, if any. */
        selectedId?: number | null;
        /** What this chart is, for anyone who cannot see it. */
        summary: string;
    }>(),
    { selectedId: null },
);

const emit = defineEmits<{ (event: 'select', id: number): void }>();

const hovered = ref<number | null>(null);

function isLit(id: number): boolean {
    return props.selectedId === null || props.selectedId === id;
}
</script>

<template>
    <div :aria-label="summary" role="group">
        <ul class="space-y-3.5">
            <li v-for="row in rows" :key="row.id" class="relative">
                <button
                    type="button"
                    class="group block w-full rounded-lg px-1 py-1 text-left transition-opacity duration-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="isLit(row.id) ? 'opacity-100' : 'opacity-40'"
                    :aria-pressed="selectedId === row.id"
                    @click="emit('select', row.id)"
                    @mouseenter="hovered = row.id"
                    @mouseleave="hovered = null"
                    @focus="hovered = row.id"
                    @blur="hovered = null"
                >
                    <div class="mb-1.5 flex items-baseline justify-between gap-3">
                        <span class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ row.label }}
                        </span>
                        <span class="text-sm font-semibold tabular-nums tracking-tight">{{ row.display }}</span>
                    </div>

                    <!-- The track: where a full ribbon would reach. Faint, so the
                         eye reads it as a scale rather than as a second value. -->
                    <div class="relative h-7 w-full overflow-visible rounded-md bg-muted/40">
                        <template v-if="row.value !== null">
                            <!-- The ribbon. Its WIDTH is the value; the tip is
                                 clipped out of it, never added to it. -->
                            <div
                                class="absolute inset-y-0 left-0 transition-all ease-out"
                                :class="prefersReducedMotion() ? 'duration-0' : 'duration-500'"
                                :style="{
                                    width: `${Math.max(row.value, 1.5)}%`,
                                    background: `linear-gradient(180deg, ${shade(row.colour, 0.16)} 0%, ${row.colour} 55%, ${shade(row.colour, -0.1)} 100%)`,
                                    clipPath: 'polygon(0 0, calc(100% - 10px) 0, 100% 50%, calc(100% - 10px) 100%, 0 100%, 6px 50%)',
                                }"
                            ></div>

                            <!-- The fold: a darker sliver at the tail, the face
                                 of the ribbon turning away from the reader. -->
                            <div
                                aria-hidden="true"
                                class="absolute bottom-0 left-0 h-1.5 transition-all ease-out"
                                :class="prefersReducedMotion() ? 'duration-0' : 'duration-500'"
                                :style="{
                                    width: `${Math.max(row.value, 1.5)}%`,
                                    background: shade(row.colour, -0.32),
                                    clipPath: 'polygon(0 0, calc(100% - 10px) 0, calc(100% - 5px) 100%, 0 100%)',
                                    opacity: 0.55,
                                }"
                            ></div>
                        </template>

                        <!-- No value is no ribbon. Never a stub standing in for
                             a zero (§37). -->
                        <span
                            v-else
                            class="absolute inset-y-0 left-2 flex items-center text-[11px] text-muted-foreground"
                        >
                            Sem resultado neste período
                        </span>
                    </div>
                </button>

                <!-- The same panel the canvases show, anchored to the row. -->
                <Transition
                    :enter-active-class="prefersReducedMotion() ? '' : 'transition duration-100 ease-out'"
                    enter-from-class="opacity-0 translate-y-1"
                    enter-to-class="opacity-100 translate-y-0"
                >
                    <div
                        v-if="hovered === row.id"
                        class="pointer-events-none absolute left-4 top-full z-30 mt-1 w-max max-w-[17rem] rounded-xl border border-border/80 bg-popover/95 p-3 shadow-lg ring-1 ring-black/5 backdrop-blur-sm dark:ring-white/10"
                    >
                        <p class="text-[11px] font-semibold uppercase tracking-wider">{{ row.tooltip.title }}</p>
                        <dl class="mt-2 space-y-1">
                            <div
                                v-for="line in row.tooltip.rows"
                                :key="line.label"
                                class="flex items-baseline justify-between gap-6"
                            >
                                <dt class="text-[11px] text-muted-foreground">{{ line.label }}</dt>
                                <dd
                                    class="text-xs tabular-nums"
                                    :class="[
                                        line.strong ? 'font-semibold' : 'font-medium text-foreground/90',
                                        line.trend === 'up' ? 'text-emerald-600 dark:text-emerald-400' : '',
                                        line.trend === 'down' ? 'text-rose-600 dark:text-rose-400' : '',
                                    ]"
                                >
                                    {{ line.value }}
                                </dd>
                            </div>
                        </dl>
                        <p v-if="row.tooltip.footer" class="mt-2 border-t border-border/60 pt-1.5 text-[10px] text-muted-foreground">
                            {{ row.tooltip.footer }}
                        </p>
                    </div>
                </Transition>
            </li>
        </ul>
    </div>
</template>
