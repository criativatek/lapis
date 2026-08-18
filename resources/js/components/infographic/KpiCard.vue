<script setup lang="ts">
import { Info } from '@lucide/vue';
import type { Component } from 'vue';
import { card } from '@/lib/surfaces';
import type { SurfaceTone } from '@/lib/surfaces';

/**
 * One headline figure, said in four parts: an icon, a label, the number, and
 * the one line of context that makes the number checkable.
 *
 * THE CONTEXT LINE IS NOT DECORATION. «68,4%» alone is unreadable — of what,
 * out of how many? Every card carries its base, because a rate without its
 * denominator is a number nobody can verify. What it does NOT carry is a
 * paragraph: the pedagogical explanation lives behind the info button, so the
 * card stays a figure rather than becoming a note (§39, §40).
 */

withDefaults(
    defineProps<{
        tone?: SurfaceTone;
        icon: Component;
        label: string;
        /** Already formatted by the page — «60,3%», «+8,8», «5 / 6». */
        value: string;
        /** A small unit or denominator set beside the number, not inside it. */
        unit?: string | null;
        context?: string | null;
        /** Shown as a pill under the value. Never colour alone: it has an icon. */
        trend?: { display: string; direction: 'up' | 'down' | 'flat' } | null;
        trendIcon?: Component | null;
        /** The pedagogical note, one click away rather than in the card. */
        help?: string | null;
    }>(),
    { tone: 'plain', unit: null, context: null, trend: null, trendIcon: null, help: null },
);

const TREND_CLASSES: Record<'up' | 'down' | 'flat', string> = {
    up: 'bg-emerald-100/70 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300',
    down: 'bg-rose-100/70 text-rose-800 dark:bg-rose-950/50 dark:text-rose-300',
    flat: 'bg-muted text-muted-foreground',
};

const ICON_CLASSES: Record<SurfaceTone, string> = {
    amber: 'bg-amber-100/80 text-amber-700 dark:bg-amber-950/50 dark:text-amber-300',
    violet: 'bg-violet-100/80 text-violet-700 dark:bg-violet-950/50 dark:text-violet-300',
    mint: 'bg-emerald-100/80 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300',
    sky: 'bg-sky-100/80 text-sky-700 dark:bg-sky-950/50 dark:text-sky-300',
    rose: 'bg-rose-100/80 text-rose-700 dark:bg-rose-950/50 dark:text-rose-300',
    plain: 'bg-muted text-muted-foreground',
};
</script>

<template>
    <section :class="[card(tone), 'flex flex-col rounded-[18px] p-4']">
        <div class="flex items-start gap-2.5">
            <span
                aria-hidden="true"
                class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl"
                :class="ICON_CLASSES[tone]"
            >
                <component :is="icon" class="size-[18px]" />
            </span>

            <p class="min-w-0 pt-1 text-[11px] font-semibold uppercase leading-tight tracking-[0.08em] text-muted-foreground">
                {{ label }}
            </p>

            <button
                v-if="help"
                type="button"
                class="ml-auto shrink-0 rounded-md p-0.5 text-muted-foreground/70 transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :title="help"
                :aria-label="`O que é: ${label}. ${help}`"
            >
                <Info aria-hidden="true" class="size-3.5" />
            </button>
        </div>

        <p class="mt-2.5 flex items-baseline gap-1.5">
            <span class="text-[2.25rem] font-semibold leading-none tabular-nums tracking-tight">{{ value }}</span>
            <span v-if="unit" class="text-sm font-medium text-muted-foreground">{{ unit }}</span>
        </p>

        <p
            v-if="trend"
            class="mt-2 inline-flex w-fit items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium tabular-nums"
            :class="TREND_CLASSES[trend.direction]"
        >
            <component :is="trendIcon" v-if="trendIcon" aria-hidden="true" class="size-3 shrink-0" />
            {{ trend.display }}
        </p>

        <p v-if="context" class="mt-2 text-[11px] leading-relaxed text-muted-foreground">{{ context }}</p>

        <slot />
    </section>
</template>
