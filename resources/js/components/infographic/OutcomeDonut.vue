<script setup lang="ts">
import { Check, Circle, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * «Quantas classificações positivas e quantas negativas?»
 *
 * THE GRADES, NOT THE MOVEMENT. Positive is not the same as «progrediu» and
 * negative is not «regrediu» — a student can fall six points and stay
 * comfortably positive, or climb ten and still be negative. This ring answers
 * the first question only; who moved is a different visual on this page and
 * they are deliberately never drawn as one (§18, §19).
 *
 * A RING RATHER THAN A BAR, here and nowhere else on the page: this is the one
 * figure that is genuinely a whole split in two, the counts are small, and the
 * middle is where the denominator belongs — so the reader gets «6
 * classificados» and the split in a single glance. The counts and shares are
 * written out beside it, so nothing depends on estimating an angle.
 *
 * Drawn as SVG. Chart.js is already lazy-loaded on this page for the readings
 * that genuinely need axes; two arcs do not.
 */

const props = withDefaults(
    defineProps<{
        succeeded: number;
        failed: number;
        /** Graded, on a scale that says nothing about which side that grade is. */
        unplaced?: number;
        withoutClassification?: number;
        /** Already formatted by the page. */
        successShare: string;
        failureShare: string;
    }>(),
    { unplaced: 0, withoutClassification: 0 },
);

/** The denominator, and the number in the middle. */
const classified = computed<number>(() => props.succeeded + props.failed);

const RADIUS = 52;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const successLength = computed<number>(() => (classified.value === 0
    ? 0
    : (props.succeeded / classified.value) * CIRCUMFERENCE));

const students = (count: number): string => (count === 1 ? '1 aluno' : `${count} alunos`);
</script>

<template>
    <div class="flex flex-col items-center gap-5 sm:flex-row sm:items-center sm:gap-7">
        <div class="relative shrink-0">
            <svg viewBox="0 0 128 128" class="size-36" role="img" :aria-label="`${succeeded} classificações positivas e ${failed} negativas, de ${classified}.`">
                <circle cx="64" cy="64" :r="RADIUS" fill="none" class="stroke-muted/50" stroke-width="16" />

                <template v-if="classified > 0">
                    <!-- Failure fills the whole ring and success is drawn over
                         it, so the two arcs meet exactly and no rounding gap
                         opens between them. -->
                    <circle
                        cx="64"
                        cy="64"
                        :r="RADIUS"
                        fill="none"
                        stroke-width="16"
                        class="stroke-rose-400/80 dark:stroke-rose-500/70"
                        transform="rotate(-90 64 64)"
                    />
                    <circle
                        cx="64"
                        cy="64"
                        :r="RADIUS"
                        fill="none"
                        stroke-width="16"
                        stroke-linecap="butt"
                        class="stroke-emerald-500/85 transition-all ease-out dark:stroke-emerald-400/85"
                        :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                        :stroke-dasharray="`${successLength} ${CIRCUMFERENCE}`"
                        transform="rotate(-90 64 64)"
                    />
                </template>
            </svg>

            <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-[1.75rem] font-semibold leading-none tabular-nums tracking-tight">{{ classified }}</span>
                <span class="mt-1 text-[10px] uppercase tracking-wider text-muted-foreground">classificados</span>
            </div>
        </div>

        <!-- Never colour alone: icon, word, count and share on every row. -->
        <dl class="w-full min-w-0 space-y-2 text-sm">
            <div class="flex items-baseline gap-2">
                <Check aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-emerald-600 dark:text-emerald-400" />
                <dt class="text-muted-foreground">Classificação positiva</dt>
                <dd class="ml-auto font-semibold tabular-nums">{{ succeeded }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ successShare }}</dd>
            </div>

            <div class="flex items-baseline gap-2">
                <TriangleAlert aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-rose-600 dark:text-rose-400" />
                <dt class="text-muted-foreground">Classificação negativa</dt>
                <dd class="ml-auto font-semibold tabular-nums">{{ failed }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ failureShare }}</dd>
            </div>

            <!-- Outside the ring, and said so. -->
            <div v-if="withoutClassification > 0" class="flex items-baseline gap-2 border-t border-border/60 pt-2">
                <Circle aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Sem classificação</dt>
                <dd class="ml-auto font-semibold tabular-nums text-muted-foreground">{{ withoutClassification }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs text-muted-foreground">fora</dd>
            </div>

            <div v-if="unplaced > 0" class="flex items-baseline gap-2">
                <Circle aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Sem banda na escala</dt>
                <dd class="ml-auto font-semibold tabular-nums text-muted-foreground">{{ unplaced }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs text-muted-foreground">fora</dd>
            </div>

            <p v-if="classified === 0" class="pt-1 text-[11px] leading-relaxed text-muted-foreground">
                Ainda não há classificações atribuídas — {{ students(withoutClassification) }} por classificar.
            </p>
        </dl>
    </div>
</template>
