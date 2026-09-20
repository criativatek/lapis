<script setup lang="ts">
/**
 * O RESUMO DO TOPO — o que o professor tem de perceber em segundos.
 *
 * Quantas estão ativas, que níveis estão mobilizados, e quando é a próxima
 * revisão que ele próprio marcou.
 *
 * NADA AQUI É INVENTADO. O número de níveis sai das intervenções que a
 * página recebeu; se nenhuma tiver nível legal, essa linha não aparece de
 * todo em vez de mostrar «Universal 0, Seletiva 0». A próxima revisão é uma
 * data que o professor escolheu — nenhuma regra deriva prazos do tempo
 * decorrido.
 *
 * As pastilhas de nível trazem sempre o rótulo por extenso e a contagem; a
 * cor distingue, não informa sozinha.
 */

import { CalendarClock } from '@lucide/vue';
import { computed } from 'vue';
import type { SummarisableIntervention } from '@/lib/interventionPresentation';
import { summarise } from '@/lib/interventionPresentation';

const props = defineProps<{
    interventions: SummarisableIntervention[];
    /** A data de hoje em ISO, injectada para o cálculo ser testável. */
    today: string;
    /**
     * A ordem por que o enquadramento em vigor nomeia os seus níveis. Vem de
     * `supportMeasureLevels`: é o diploma que a fixa, não este componente.
     */
    levelOrder?: string[];
}>();

const emit = defineEmits<{ 'show-pending': [] }>();

const summary = computed(() => summarise(props.interventions, props.today, props.levelOrder ?? []));

/** «15 de novembro de 2026», como uma pessoa o diz. */
const nextReview = computed(() =>
    summary.value.nextReviewOn === null
        ? null
        : new Date(summary.value.nextReviewOn).toLocaleDateString('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' }),
);
</script>

<template>
    <section v-if="interventions.length > 0" class="rounded-xl border border-border bg-muted/20 p-4" aria-labelledby="interventions-summary-heading">
        <h2 id="interventions-summary-heading" class="text-xs font-semibold tracking-wide text-muted-foreground uppercase">Resumo</h2>

        <p class="mt-1.5 text-lg font-medium">
            {{ summary.activeCount }}
            {{ summary.activeCount === 1 ? 'estratégia ou medida ativa' : 'estratégias e medidas ativas' }}
            <span v-if="summary.activeCount !== interventions.length" class="text-sm font-normal text-muted-foreground">
                de {{ interventions.length }} registadas
            </span>
        </p>

        <!-- Só os níveis que existem mesmo nestes registos (§4). -->
        <ul v-if="summary.levels.length" class="mt-2.5 flex flex-wrap gap-1.5">
            <li
                v-for="level in summary.levels"
                :key="level.level"
                class="rounded-full px-2.5 py-1 text-xs"
                :class="level.toneClasses"
            >
                {{ level.label }} · {{ level.count }}
            </li>
        </ul>

        <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-sm">
            <p v-if="nextReview" class="inline-flex items-center gap-1.5 text-muted-foreground">
                <CalendarClock class="size-4 shrink-0" aria-hidden="true" />
                Próxima revisão: <span class="font-medium text-foreground">{{ nextReview }}</span>
            </p>

            <button
                v-if="summary.pendingReviewCount > 0"
                type="button"
                class="inline-flex min-h-9 items-center gap-1.5 rounded-full bg-amber-100 px-3 py-1 text-xs text-amber-900 hover:bg-amber-200 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none dark:bg-amber-950 dark:text-amber-300 dark:hover:bg-amber-900"
                @click="emit('show-pending')"
            >
                <CalendarClock class="size-3.5 shrink-0" aria-hidden="true" />
                {{ summary.pendingReviewCount }}
                {{ summary.pendingReviewCount === 1 ? 'com revisão pendente' : 'com revisão pendente' }}
                <span class="font-medium underline">Ver só estas</span>
            </button>
        </div>
    </section>
</template>
