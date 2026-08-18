<script setup lang="ts">
import { Check, Minus, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { prefersReducedMotion } from '@/lib/chartTheme';

/**
 * «Quantos alunos tiveram classificação positiva?», answered in one line.
 *
 * THE GRADES THE TEACHER GAVE, not the mentions the averages landed on. This is
 * the number a conselho de turma quotes, so it counts decisions; the
 * statistical reading lives further down the page under «Como se distribuem os
 * resultados» and says there which figure it bands.
 *
 * A SEGMENTED BAR RATHER THAN A DOUGHNUT. The question is about two parts of
 * one whole, and a bar shows a proportion as a length the eye reads directly —
 * a doughnut turns it into an angle, which nobody estimates well, and then
 * hides the counts behind a hover.
 *
 * WHAT IS AND IS NOT IN THE DENOMINATOR is stated on the card, because a rate
 * without its base is a number nobody can check. Students nobody has graded yet
 * stand apart and are never folded in: not having been graded is not a failure.
 */

export type SuccessFigures = {
    succeeded: number;
    failed: number;
    /** Graded, on a scale that says nothing about which side that grade is. */
    unplaced: number;
    without_classification: number;
    /** succeeded + failed — the denominator, and nothing else. */
    placed: number;
    /** Already formatted by the caller, or null when nobody is placed. */
    rate: string | null;
    failure_rate: string | null;
};

const props = defineProps<{
    figures: SuccessFigures;
    /** «83,3%» — formatted by the page, so this decides no arithmetic. */
    rateDisplay: string;
    successShare: string;
    failureShare: string;
}>();

/** Widths for the bar. Null placed means there is nothing to divide. */
const successWidth = computed<number>(() => (props.figures.placed === 0
    ? 0
    : (props.figures.succeeded / props.figures.placed) * 100));

const students = (count: number): string => (count === 1 ? '1 aluno' : `${count} alunos`);
</script>

<template>
    <div>
        <p class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Taxa de sucesso</p>

        <p class="mt-1.5 text-[2.5rem] font-semibold leading-none tabular-nums tracking-tight">
            {{ rateDisplay }}
        </p>
        <p class="mt-1 text-[11px] text-muted-foreground">
            <template v-if="figures.placed > 0">
                de {{ students(figures.placed) }} com classificação atribuída
            </template>
            <template v-else>Ainda não há classificações atribuídas</template>
        </p>

        <!-- Two parts of one whole. -->
        <div class="mt-3 flex h-3.5 w-full overflow-hidden rounded-full bg-muted/50">
            <span
                v-if="figures.succeeded > 0"
                class="h-full bg-emerald-500/80 transition-all ease-out first:rounded-l-full last:rounded-r-full"
                :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                :style="{ width: `${successWidth}%` }"
            ></span>
            <span
                v-if="figures.failed > 0"
                class="h-full bg-rose-500/75 transition-all ease-out first:rounded-l-full last:rounded-r-full"
                :class="prefersReducedMotion() ? 'duration-0' : 'duration-700'"
                :style="{ width: `${100 - successWidth}%` }"
            ></span>
        </div>

        <!-- Never colour alone: an icon, a word, a count and a share. -->
        <dl class="mt-3 space-y-1.5 text-sm">
            <div class="flex items-baseline gap-2">
                <Check aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-emerald-600 dark:text-emerald-400" />
                <dt class="text-muted-foreground">Sucesso</dt>
                <dd class="ml-auto font-semibold tabular-nums">{{ figures.succeeded }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ successShare }}</dd>
            </div>

            <div class="flex items-baseline gap-2">
                <TriangleAlert aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-rose-600 dark:text-rose-400" />
                <dt class="text-muted-foreground">Insucesso</dt>
                <dd class="ml-auto font-semibold tabular-nums">{{ figures.failed }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs tabular-nums text-muted-foreground">{{ failureShare }}</dd>
            </div>

            <!-- Outside the rate, and said so. -->
            <div v-if="figures.without_classification > 0" class="flex items-baseline gap-2">
                <Minus aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Por classificar</dt>
                <dd class="ml-auto font-semibold tabular-nums text-muted-foreground">{{ figures.without_classification }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs text-muted-foreground">fora</dd>
            </div>

            <div v-if="figures.unplaced > 0" class="flex items-baseline gap-2">
                <Minus aria-hidden="true" class="size-3.5 shrink-0 translate-y-0.5 text-muted-foreground/60" />
                <dt class="text-muted-foreground">Sem menção na escala</dt>
                <dd class="ml-auto font-semibold tabular-nums text-muted-foreground">{{ figures.unplaced }}</dd>
                <dd class="w-14 shrink-0 text-right text-xs text-muted-foreground">fora</dd>
            </div>
        </dl>

        <p class="mt-2.5 text-[11px] leading-relaxed text-muted-foreground">
            <template v-if="figures.without_classification > 0 || figures.unplaced > 0">
                Conta as classificações que atribuiu, não as médias. Quem ainda não tem classificação
                fica de fora da taxa — não ter sido classificado não é um insucesso.
            </template>
            <template v-else>Conta as classificações que atribuiu, não as médias calculadas.</template>
        </p>
    </div>
</template>
