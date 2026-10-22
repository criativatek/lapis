<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CircleAlert, Info, Sparkles } from '@lucide/vue';
import type { ChartConfiguration } from 'chart.js';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import { levelChangeText, percent, points } from '@/lib/assessmentComparison';
import { categoryAxis, chromeColours, percentAxis } from '@/lib/chartTheme';
import type { TooltipContent } from '@/lib/chartTheme';
import { seriesColour, trendArrow, trendWord } from '@/lib/studentYear';
import type { YearPoint, YearReading } from '@/lib/studentYear';
import { card } from '@/lib/surfaces';

const StatChart = defineAsyncComponent(() => import('@/components/charts/StatChart.vue'));

/**
 * «Ao longo do ano» (Pro) — este aluno em QUALQUER dois momentos comparáveis, e cada domínio em todos.
 *
 * O QUE A PÁGINA JÁ DIZ NÃO SE REPETE. «Como evoluiu» compara os dois últimos momentos na leitura
 * escolhida; «Em que domínios» mostra o acumulado e a última variação. Aqui o professor escolhe os dois
 * momentos, vê a mudança de NÍVEL e a travessia da linha da escala, e cada domínio ao longo de todo o
 * ano — sempre com a origem dos dados e o motivo de uma comparação não se fazer.
 *
 * LÊ-SE, NÃO SE CALCULA. Tudo chega decidido do servidor (o mesmo que compara a turma). A secção diz que
 * usa o resultado de cada período por si, e não a leitura contínua, para ninguém comparar 70,2 % com
 * 68,9 % sem saber porquê. Não procura persistência nem causas: uma descida são dois números.
 */

const props = defineProps<{
    reading: YearReading;
    /** Where the student's page lives, and what its query already carries (the `leitura`). */
    action: string;
    query?: Record<string, string>;
    studentName: string;
}>();

const fromKey = ref(props.reading.selection.from ?? '');
const toKey = ref(props.reading.selection.to ?? '');

// The selects follow what is shown, never what was last clicked (the server orders the two in time).
watch(
    () => props.reading.selection,
    (selection) => {
        fromKey.value = selection.from ?? '';
        toKey.value = selection.to ?? '';
    },
);

function choose(): void {
    if (fromKey.value === '' || toKey.value === '' || fromKey.value === toKey.value) {
        return;
    }

    router.get(props.action, { ...(props.query ?? {}), de: fromKey.value, para: toKey.value }, { preserveScroll: true, preserveState: true, replace: true });
}

function optionText(point: YearPoint): string {
    return `${point.label} — ${point.basis.kind === 'frozen' ? point.basis.label : 'hoje'}`;
}

const ready = computed(() => props.reading.state === 'ready' && props.reading.from !== null && props.reading.to !== null);

const MOVEMENT_CLASS = {
    up: 'text-emerald-700 dark:text-emerald-400',
    down: 'text-rose-700 dark:text-rose-400',
    flat: 'text-muted-foreground',
    unavailable: 'text-muted-foreground',
} as const;

function reasonText(reason: string | null): string {
    switch (reason) {
        case 'missing_from':
            return 'Sem resultado no primeiro momento.';
        case 'missing_to':
            return 'Sem resultado no segundo momento.';
        case 'missing_both':
            return 'Sem valor em nenhum dos momentos.';
        case 'not_comparable':
            return 'Os critérios mudaram entre os dois momentos.';
        default:
            return '';
    }
}

// ------------------------------------------------------------------ gráfico factual

const chart = computed(() => props.reading.series);

const lines = computed(() => [
    { key: 'result', label: 'Resultado global', values: chart.value.result, colour: '#27272a', width: 3 },
    ...chart.value.domains.map((domain, index) => ({ key: domain.ulid, label: domain.name, values: domain.values, colour: seriesColour(index), width: 2 })),
]);

const chartConfig = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();

    return {
        type: 'line',
        data: {
            labels: chart.value.points.map((point) => point.label),
            datasets: lines.value.map((line) => ({
                label: line.label,
                data: line.values.map((value) => (value === null ? null : Number(value))),
                borderColor: line.colour,
                backgroundColor: line.colour,
                borderWidth: line.width,
                // A kept moment is a square and a period a circle: told apart without colour.
                pointStyle: chart.value.points.map((point) => (point.kind === 'kept_moment' ? 'rect' : 'circle')),
                pointRadius: line.key === 'result' ? 5 : 4,
                pointBackgroundColor: chrome.surface,
                pointBorderColor: line.colour,
                pointBorderWidth: 2,
                tension: 0,
                // NEVER spanGaps: a moment without a value is a gap, and joining across it would draw a value nobody has.
                spanGaps: false,
            })),
        },
        options: { scales: { x: categoryAxis(chrome), y: percentAxis(chrome) } },
    } as ChartConfiguration;
});

const chartTooltip = (index: number): TooltipContent | null => {
    const point = chart.value.points[index];

    if (point === undefined) {
        return null;
    }

    return {
        title: point.label,
        subtitle: point.basis_kind === 'frozen' ? 'Momento guardado' : 'Calculado com os dados de hoje',
        rows: lines.value.map((line) => ({ label: line.label, value: percent(line.values[index]), strong: line.key === 'result' })),
    };
};

const chartHeaders = computed(() => ['Momento', ...lines.value.map((line) => line.label)]);
const chartRows = computed(() => chart.value.points.map((point, index) => [point.label, ...lines.value.map((line) => percent(line.values[index]))]));
</script>

<template>
    <section :class="[card('plain'), 'p-5']" aria-labelledby="ao-longo-do-ano" data-test="year-reading">
        <h2 id="ao-longo-do-ano" class="flex items-center gap-2 text-sm font-semibold">
            <Sparkles class="size-4" />
            Ao longo do ano
        </h2>
        <p class="mt-0.5 text-xs text-muted-foreground">
            Compare {{ studentName }} em dois momentos à sua escolha, com o resultado do próprio período. A «leitura contínua» acima
            junta os períodos, por isso os números podem ser diferentes.
        </p>

        <p v-if="!ready" class="mt-4 text-sm text-muted-foreground" data-test="year-unavailable">
            {{ reading.reason ?? 'Ainda não há dois momentos para comparar.' }}
        </p>

        <template v-else-if="reading.from && reading.to && reading.result">
            <!-- ----------------------------------------------- escolha dos momentos -->
            <form class="mt-4 grid gap-3 sm:grid-cols-2" @submit.prevent="choose">
                <label class="space-y-1 text-xs">
                    <span class="font-medium">De</span>
                    <select
                        v-model="fromKey"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        data-test="year-select-from"
                        @change="choose"
                    >
                        <option v-for="option in reading.options" :key="option.key" :value="option.key">{{ optionText(option) }}</option>
                    </select>
                </label>
                <label class="space-y-1 text-xs">
                    <span class="font-medium">Para</span>
                    <select
                        v-model="toKey"
                        class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                        data-test="year-select-to"
                        @change="choose"
                    >
                        <option v-for="option in reading.options" :key="option.key" :value="option.key">{{ optionText(option) }}</option>
                    </select>
                </label>
            </form>

            <!-- ---------------------------------------- origem, cobertura e limites -->
            <div class="mt-3 space-y-2 text-xs" data-test="year-limits">
                <p class="text-muted-foreground">
                    <span class="font-medium text-foreground">{{ reading.from.label }}:</span> {{ reading.from.basis.label }}.
                    <span class="font-medium text-foreground">{{ reading.to.label }}:</span> {{ reading.to.basis.label }}.
                </p>
                <p v-if="reading.basis_note" class="flex gap-1.5 rounded-lg bg-muted/50 p-2.5">
                    <Info class="mt-0.5 size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span>{{ reading.basis_note }}</span>
                </p>
                <p
                    v-for="caution in reading.cautions"
                    :key="caution.text"
                    class="flex gap-1.5 rounded-lg border border-amber-200/70 bg-amber-50/70 p-2.5 dark:border-amber-900/40 dark:bg-amber-950/20"
                >
                    <CircleAlert class="mt-0.5 size-3.5 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                    <span>{{ caution.text }}</span>
                </p>
                <div
                    v-if="reading.comparability && reading.comparability.reasons.length"
                    class="rounded-lg border border-amber-200/70 bg-amber-50/70 p-2.5 dark:border-amber-900/40 dark:bg-amber-950/20"
                    data-test="year-not-comparable"
                >
                    <p class="flex gap-1.5 font-medium">
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                        Estes dois momentos não são totalmente comparáveis
                    </p>
                    <ul class="ml-5 mt-1 list-disc space-y-0.5">
                        <li v-for="reason in reading.comparability.reasons" :key="reason.code">{{ reason.text }}</li>
                    </ul>
                </div>
            </div>

            <!-- ----------------------------------------------- o resultado global -->
            <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2" data-test="year-result">
                <div class="rounded-lg border border-border/70 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Resultado global</dt>
                    <dd class="mt-1 tabular-nums">
                        {{ percent(reading.result.from?.value) }}
                        <span class="mx-1 text-muted-foreground">→</span>
                        <span class="font-medium">{{ percent(reading.result.to?.value) }}</span>
                    </dd>
                    <dd class="mt-1 text-xs" :class="MOVEMENT_CLASS[reading.result.movement.state]">
                        {{ trendArrow(reading.result.movement.state) }} {{ trendWord(reading.result.movement.state) }}
                        <template v-if="reading.result.movement.points !== null"> · {{ points(reading.result.movement.points) }}</template>
                    </dd>
                    <dd v-if="reading.result.movement.reason" class="mt-0.5 text-xs text-muted-foreground">
                        {{ reasonText(reading.result.movement.reason) }}
                    </dd>
                </div>
                <div class="rounded-lg border border-border/70 p-3">
                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Nível</dt>
                    <dd class="mt-1 text-sm" data-test="year-result-level">{{ levelChangeText(reading.result.level) }}</dd>
                    <dd v-if="reading.assigned && (reading.assigned.from || reading.assigned.to)" class="mt-2 border-t border-border/60 pt-2 text-xs" data-test="year-assigned">
                        <span class="text-muted-foreground">Nível atribuído:</span>
                        {{ levelChangeText(reading.assigned.change) }}
                    </dd>
                </div>
            </dl>

            <!-- --------------------------------------------------- por domínio -->
            <div v-if="reading.domains.length" class="mt-5 border-t border-border/60 pt-4">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Por domínio</h3>
                <table class="mt-2 w-full text-sm" data-test="year-domains">
                    <caption class="sr-only">
                        Resultado por domínio de {{ studentName }} nos dois momentos, variação e mudança de nível.
                    </caption>
                    <thead>
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            <th scope="col" class="pb-2">Domínio</th>
                            <th scope="col" class="pb-2 text-right">{{ reading.from.label }}</th>
                            <th scope="col" class="pb-2 text-right">{{ reading.to.label }}</th>
                            <th scope="col" class="pb-2 text-right">Variação</th>
                            <th scope="col" class="pb-2 pl-4">Nível</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="domain in reading.domains" :key="domain.ulid">
                            <th scope="row" class="py-2 pr-3 text-left font-normal">{{ domain.name }}</th>
                            <td class="py-2 text-right tabular-nums">{{ percent(domain.from) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ percent(domain.to) }}</td>
                            <td class="py-2 text-right tabular-nums">
                                <span v-if="domain.movement.state !== 'unavailable'" :class="MOVEMENT_CLASS[domain.movement.state]">
                                    {{ trendArrow(domain.movement.state) }} {{ points(domain.movement.points) }}
                                </span>
                                <span v-else class="text-xs text-muted-foreground">{{ reasonText(domain.movement.reason) || 'Não comparável' }}</span>
                            </td>
                            <td class="py-2 pl-4 text-xs text-muted-foreground">
                                <template v-if="domain.level.state !== 'unavailable'">{{ levelChangeText(domain.level) }}</template>
                                <template v-else>—</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- ------------------------------------------------ gráfico factual -->
            <div class="mt-5 border-t border-border/60 pt-4" data-test="year-chart">
                <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Resultado e domínios em cada momento</h3>
                <template v-if="chart.enough">
                    <StatChart
                        class="mt-3 block"
                        :config="chartConfig"
                        :tooltip="chartTooltip"
                        :summary="`Resultado global e resultado por domínio de ${studentName} em cada momento, no resultado de cada período.`"
                        :headers="chartHeaders"
                        :rows="chartRows"
                        height-class="h-64"
                    />
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground" data-test="year-legend">
                        <li v-for="line in lines" :key="line.key" class="flex items-center gap-1.5">
                            <span class="inline-block h-0.5 w-4 rounded" :style="{ backgroundColor: line.colour }" aria-hidden="true"></span>
                            {{ line.label }}
                        </li>
                    </ul>
                    <p class="mt-1 text-xs text-muted-foreground">Círculo: fim de período. Quadrado: momento guardado.</p>
                </template>
                <p v-else class="mt-2 text-sm text-muted-foreground" data-test="year-chart-empty">
                    Ainda não há dois momentos comparáveis com resultado para desenhar um gráfico.
                </p>
                <p v-if="chart.note" class="mt-1 text-xs text-muted-foreground" data-test="year-chart-note">{{ chart.note }}</p>
            </div>

            <!-- --------------------------------------------- como se calcula -->
            <div class="mt-5 border-t border-border/60 pt-3">
                <details v-for="item in reading.explanations" :key="item.key" class="py-1.5 text-xs" :data-test="`year-how-${item.key}`">
                    <summary class="cursor-pointer font-medium">{{ item.title }}</summary>
                    <p class="mt-1 leading-relaxed text-muted-foreground">{{ item.text }}</p>
                </details>
            </div>
        </template>
    </section>
</template>
