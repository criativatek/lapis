<script setup lang="ts">
import { computed } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * A headline figure, drawn as a semicircular arc.
 *
 * SVG AND NOTHING ELSE — no library was installed for this, and none is
 * needed: an arc is one path, and one path that respects the page's own tokens
 * beats a dependency that does not.
 *
 * THE ARC IS A READING AID, NOT THE VALUE. The number is written in the middle
 * at full size and is what anybody actually reads; the sweep only says «about
 * two thirds of the way» at a glance. That distinction matters because the
 * sweep is drawn on a normalised 0–100 scale purely so the geometry has ends —
 * the figure itself is the canonical Média Ponderada and is neither rescaled
 * nor recomputed here.
 *
 * A missing value gets an empty track and «—», never a needle resting on zero:
 * a class with no result is not a class averaging nothing (§37 of the
 * statistics decision).
 */

const props = withDefaults(
    defineProps<{
        /** 0–100, or null when there is nothing to show. */
        percent: number | null;
        /** Already formatted — this component decides no arithmetic. */
        display: string;
        label: string;
        /** The mention, when the scale has one for this figure. */
        badge?: string | null;
        badgeClass?: string;
        /** A line of context under the badge. */
        caption?: string | null;
        /** The arc's ink. Falls back to the page's primary. */
        colour?: string;
        size?: 'md' | 'lg';
    }>(),
    { badge: null, badgeClass: '', caption: null, colour: '#6366f1', size: 'lg' },
);

// The drawing box, in the SVG's own units. A semicircle of radius 80 inside a
// 200×116 viewport leaves room for the rounded cap not to be clipped.
const RADIUS = 80;
const CENTRE_X = 100;
const CENTRE_Y = 96;
const STROKE = 16;

/** Half a circle: left of centre, over the top, to the right of centre. */
const arc = `M ${CENTRE_X - RADIUS} ${CENTRE_Y} A ${RADIUS} ${RADIUS} 0 0 1 ${CENTRE_X + RADIUS} ${CENTRE_Y}`;

const length = Math.PI * RADIUS;

/** How much of the arc is drawn. Clamped so a stray value cannot overshoot. */
const filled = computed<number>(() => {
    if (props.percent === null) {
        return 0;
    }

    return Math.max(0, Math.min(100, props.percent)) / 100;
});

const offset = computed(() => length * (1 - filled.value));
</script>

<template>
    <div class="flex flex-col items-center">
        <div class="relative w-full max-w-[15rem]">
            <svg
                :viewBox="`0 0 200 ${CENTRE_Y + 20}`"
                class="w-full"
                role="img"
                :aria-label="`${label}: ${display}${badge ? ` — ${badge}` : ''}`"
            >
                <!-- The track. Very light, so it reads as the room the value
                     has rather than as a second quantity. -->
                <path
                    :d="arc"
                    fill="none"
                    :stroke-width="STROKE"
                    stroke-linecap="round"
                    class="stroke-muted"
                    :opacity="0.55"
                />

                <path
                    v-if="percent !== null"
                    :d="arc"
                    fill="none"
                    :stroke="colour"
                    :stroke-width="STROKE"
                    stroke-linecap="round"
                    :stroke-dasharray="length"
                    :stroke-dashoffset="offset"
                    :class="prefersReducedMotion() ? '' : 'transition-[stroke-dashoffset] duration-700 ease-out'"
                />
            </svg>

            <!-- The number, sitting in the arc's own opening. Absolute so it
                 stays centred whatever the card's width does. -->
            <div class="pointer-events-none absolute inset-x-0 bottom-0 flex flex-col items-center">
                <p
                    class="font-semibold tabular-nums leading-none tracking-tight"
                    :class="size === 'lg' ? 'text-[2.5rem]' : 'text-3xl'"
                >
                    {{ display }}
                </p>
                <p
                    v-if="badge"
                    class="mt-1.5 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                    :class="badgeClass"
                >
                    {{ badge }}
                </p>
            </div>
        </div>

        <p class="mt-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ label }}</p>
        <p v-if="caption" class="mt-0.5 text-center text-xs text-muted-foreground">{{ caption }}</p>
        <slot />
    </div>
</template>
