<script setup lang="ts">
import OutcomeDonut from '@/components/infographic/OutcomeDonut.vue';

/**
 * The real donut from the Análise da Turma dashboard, on representative
 * numbers. Reused rather than redrawn: a landing page that draws its own
 * version of a chart the product already has is showing something the product
 * does not do.
 */

/** 0–100 of the class placed on each level of «Escala 1 a 5». */
const distribution = [
    { code: '5', count: 3, percent: 13 },
    { code: '4', count: 7, percent: 30 },
    { code: '3', count: 8, percent: 35 },
    { code: '2', count: 4, percent: 17 },
    { code: '1', count: 1, percent: 5 },
] as const;

const TONE: Record<string, string> = {
    '5': 'bg-emerald-500/85 dark:bg-emerald-400/85',
    '4': 'bg-emerald-400/70 dark:bg-emerald-500/60',
    '3': 'bg-amber-400/80 dark:bg-amber-500/70',
    '2': 'bg-rose-400/75 dark:bg-rose-500/65',
    '1': 'bg-rose-500/85 dark:bg-rose-600/75',
};
</script>

<template>
    <div class="bg-card p-5 sm:p-6">
        <OutcomeDonut
            :succeeded="18"
            :failed="5"
            :without-classification="1"
            success-share="78,3%"
            failure-share="21,7%"
        />

        <div class="mt-6 border-t border-border/70 pt-5">
            <p
                class="text-[11px] font-semibold tracking-[0.1em] text-muted-foreground uppercase"
            >
                Distribuição pela escala
            </p>
            <ul class="mt-3 flex items-end gap-1.5">
                <li
                    v-for="band in distribution"
                    :key="band.code"
                    class="flex flex-1 flex-col items-center gap-1.5"
                >
                    <span class="text-xs font-semibold tabular-nums">{{
                        band.count
                    }}</span>
                    <span
                        aria-hidden="true"
                        class="w-full rounded-sm"
                        :class="TONE[band.code]"
                        :style="{ height: `${8 + band.percent * 1.6}px` }"
                    />
                    <span
                        class="text-[11px] text-muted-foreground tabular-nums"
                        >{{ band.code }}</span
                    >
                </li>
            </ul>
            <p class="sr-only">
                Três alunos no 5, sete no 4, oito no 3, quatro no 2 e um no 1.
            </p>
        </div>
    </div>
</template>
