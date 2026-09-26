<script setup lang="ts">
import type { QualitativeTone } from '@/lib/qualitativeTone';

/**
 * Um gráfico de barras horizontais em HTML/CSS puro — sem Chart.js, sem
 * canvas. Cada linha escreve, como TEXTO visível (nunca só em atributos), a
 * categoria, o número de alunos, a percentagem e uma barra cujo comprimento
 * é a percentagem. A cor nunca é a única distinção: uma categoria marcada
 * `emphasis: 'below'` (abaixo do limiar dos 49,5% ou banda negativa) recebe
 * também um padrão de listras diagonais e um rótulo textual visível.
 *
 * Legível em telemóvel (as etiquetas passam para cima da barra em ecrãs
 * estreitos) e na impressão (`print-color-adjust: exact` para que o
 * preenchimento e as listras saiam impressos).
 */

export type BarCategory = {
    key: string;
    label: string;
    count: number;
    percent: string | null;
    tone?: QualitativeTone | 'neutral';
    emphasis?: 'below' | null;
};

withDefaults(
    defineProps<{
        title: string;
        /** N total — escrito visivelmente na legenda: «N = 23 classificações consideradas». */
        total: number;
        categories: BarCategory[];
    }>(),
    {},
);

function barWidth(percent: string | null): string {
    if (percent === null) {
        return '0%';
    }

    const value = Number(percent);

    return `${Math.min(100, Math.max(0, value))}%`;
}

function countLabel(count: number): string {
    return count === 1 ? '1 aluno' : `${count} alunos`;
}

function percentLabel(percent: string | null): string {
    if (percent === null) {
        return '—';
    }

    return `${Number(percent).toFixed(1).replace('.', ',')} %`;
}

const TONE_FILL: Record<QualitativeTone | 'neutral', string> = {
    green: 'bg-emerald-400 dark:bg-emerald-600',
    blue: 'bg-blue-400 dark:bg-blue-600',
    amber: 'bg-amber-400 dark:bg-amber-600',
    red: 'bg-red-400 dark:bg-red-600',
    violet: 'bg-violet-400 dark:bg-violet-600',
    neutral: 'bg-slate-400 dark:bg-slate-600',
};

function fillClass(category: BarCategory): string {
    return TONE_FILL[category.tone ?? 'neutral'];
}
</script>

<template>
    <figure class="print:break-inside-avoid" aria-label="Gráfico de barras horizontais">
        <figcaption class="mb-1">
            <span class="block text-sm font-semibold">{{ title }}</span>
            <span class="block text-xs text-muted-foreground">
                N = {{ total }} {{ total === 1 ? 'classificação considerada' : 'classificações consideradas' }}
            </span>
        </figcaption>

        <ul class="mt-3 space-y-2.5" role="list">
            <li
                v-for="category in categories"
                :key="category.key"
                class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-3"
            >
                <div class="flex min-w-0 flex-wrap items-center gap-1.5 sm:w-56 sm:shrink-0">
                    <span class="text-sm font-medium break-words">{{ category.label }}</span>
                    <span
                        v-if="category.emphasis === 'below'"
                        class="inline-flex items-center rounded border border-rose-300 bg-rose-50 px-1 py-0.5 text-[10px] font-medium text-rose-800 dark:border-rose-900 dark:bg-rose-950 dark:text-rose-300"
                    >
                        abaixo do limiar
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ countLabel(category.count) }} · {{ percentLabel(category.percent) }}
                    </span>
                </div>

                <div
                    class="relative h-5 w-full min-w-0 shrink-0 overflow-hidden rounded sm:w-auto sm:flex-1 sm:shrink bg-muted/60 print:bg-transparent print:ring-1 print:ring-border"
                    role="img"
                    :aria-label="`${category.label}: ${countLabel(category.count)}, ${percentLabel(category.percent)}${category.emphasis === 'below' ? ', abaixo do limiar' : ''}`"
                >
                    <span
                        class="bar-fill block h-full rounded transition-[width]"
                        :class="[
                            fillClass(category),
                            category.emphasis === 'below' ? 'bar-stripe' : '',
                        ]"
                        :style="{ width: barWidth(category.percent) }"
                    />
                </div>
            </li>
        </ul>
    </figure>
</template>

<style scoped>
/*
 * Listras diagonais para as categorias abaixo do limiar — a cor nunca é a
 * única distinção. `print-color-adjust: exact` (e o prefixo -webkit para
 * Chromium) garantem que o preenchimento e as listras saem impressos em vez
 * de serem substituídos por branco, que é o comportamento por omissão do
 * browser ao imprimir.
 */
.bar-stripe {
    background-image: repeating-linear-gradient(
        45deg,
        rgba(255, 255, 255, 0.55) 0,
        rgba(255, 255, 255, 0.55) 4px,
        transparent 4px,
        transparent 9px
    );
}

.bar-fill {
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
</style>
