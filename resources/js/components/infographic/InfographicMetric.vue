<script setup lang="ts">
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * A headline figure, built as a block with a face rather than as a flat card.
 *
 * The depth is entirely CSS — a lit top edge, a solid bottom edge and a soft
 * shadow — and carries no information at all. It exists so the numbers read as
 * objects on a page instead of rows in a table, which is most of the difference
 * between an infographic and a dashboard.
 *
 * Everything meaningful is text: the label, the value, the context line. A
 * reader who sees none of the shading loses nothing (§22).
 */

withDefaults(
    defineProps<{
        index: string;
        label: string;
        /** The figure itself. Already formatted — this decides nothing. */
        value: string;
        context?: string;
        /** A small accent bar in the block's corner, for series identity. */
        accent?: string;
    }>(),
    { context: undefined, accent: undefined },
);
</script>

<template>
    <div
        class="group relative overflow-hidden rounded-2xl border border-border bg-card p-5 shadow-[0_1px_0_0_var(--border),0_10px_24px_-18px_rgba(0,0,0,0.45)] transition-all duration-200 dark:shadow-[0_1px_0_0_rgba(255,255,255,0.06),0_12px_28px_-20px_rgba(0,0,0,0.9)]"
        :class="prefersReducedMotion() ? '' : 'hover:-translate-y-px hover:shadow-[0_1px_0_0_var(--border),0_16px_30px_-18px_rgba(0,0,0,0.5)]'"
    >
        <!-- The lit top edge. Decorative, and the first thing that sells the
             block as a solid rather than a rectangle. -->
        <span
            aria-hidden="true"
            class="absolute inset-x-0 top-0 h-px bg-gradient-to-r from-transparent via-foreground/15 to-transparent"
        ></span>

        <!-- A folded corner, in the domain's own ink when there is one. -->
        <span
            v-if="accent"
            aria-hidden="true"
            class="absolute right-0 top-0 size-10 opacity-70"
            :style="{
                background: `linear-gradient(225deg, ${accent} 0%, ${accent} 42%, transparent 42%)`,
            }"
        ></span>

        <div class="flex items-baseline gap-2">
            <span aria-hidden="true" class="text-[11px] font-semibold tabular-nums text-muted-foreground/70">{{ index }}</span>
            <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ label }}</p>
        </div>

        <p class="mt-2 text-[2rem] font-semibold leading-none tabular-nums tracking-tight">
            <slot name="value">{{ value }}</slot>
        </p>

        <p v-if="context" class="mt-2 text-xs leading-relaxed text-muted-foreground">{{ context }}</p>
        <slot />
    </div>
</template>
