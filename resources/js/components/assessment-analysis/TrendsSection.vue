<script setup lang="ts">
import { CircleAlert, Info, TrendingUp } from '@lucide/vue';
import { computed, ref } from 'vue';
import TrajectorySummary from '@/components/assessment-analysis/TrajectorySummary.vue';
import EmptyState from '@/components/EmptyState.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import { percent, studentsCount } from '@/lib/assessmentComparison';
import { card } from '@/lib/surfaces';
import { stepsSentence, TREND_ARROW, TREND_CLASS, TREND_WORD } from '@/lib/trajectories';
import type { ClassReading, ClassTrends, TrendCounts, TrendState } from '@/lib/trajectories';

/**
 * Tendências e resultados abaixo do nível positivo persistentes — a turma ao longo do ano (Pro).
 *
 * DUAS PERGUNTAS, NUNCA MISTURADAS. Uma TENDÊNCIA precisa de três momentos comparáveis com valor; um
 * resultado abaixo do nível positivo PERSISTENTE precisa de dois, incluindo o mais recente. A página
 * diz as duas regras lado a lado e mostra, em cada conclusão, os valores e os momentos que a sustentam.
 *
 * LÊ-SE, NÃO SE CALCULA. Tudo chega decidido. Um momento sem valor é uma lacuna — nunca zero — e um
 * momento com critérios ou escala diferentes fica de fora, dito. Descreve notas: não diagnostica, não
 * prevê e não recomenda.
 */
const props = defineProps<{ trends: ClassTrends }>();

const ready = computed(() => props.trends.state === 'ready' && props.trends.class.global !== null);

// ------------------------------------------------------------------ filtro e detalhe dos alunos

type Filter = 'all' | 'persistent' | TrendState;
const filter = ref<Filter>('all');
const expanded = ref<Set<string>>(new Set());

const filters = computed<Array<{ key: Filter; label: string; count: number }>>(() => {
    const rows = props.trends.students;
    const trend = (state: TrendState) => rows.filter((row) => row.global.trend.state === state).length;

    return [
        { key: 'all', label: 'Todos', count: rows.length },
        { key: 'persistent', label: 'Abaixo do nível positivo persistente', count: rows.filter((row) => row.global.persistent.state === 'yes').length },
        { key: 'consistent_up', label: TREND_WORD.consistent_up, count: trend('consistent_up') },
        { key: 'consistent_down', label: TREND_WORD.consistent_down, count: trend('consistent_down') },
        { key: 'stable', label: TREND_WORD.stable, count: trend('stable') },
        { key: 'variable', label: TREND_WORD.variable, count: trend('variable') },
        { key: 'insufficient', label: TREND_WORD.insufficient, count: trend('insufficient') },
    ];
});

const visible = computed(() =>
    props.trends.students.filter((row) => {
        if (filter.value === 'all') {
            return true;
        }

        return filter.value === 'persistent' ? row.global.persistent.state === 'yes' : row.global.trend.state === filter.value;
    }),
);

function toggle(ulid: string): void {
    const next = new Set(expanded.value);

    if (next.has(ulid)) {
        next.delete(ulid);
    } else {
        next.add(ulid);
    }

    expanded.value = next;
}

function domainsPersistent(row: ClassTrends['students'][number]): string[] {
    return props.trends.counts.domains.filter((domain) => row.domains[domain.ulid]?.persistent.state === 'yes').map((domain) => domain.name);
}

const COUNT_ORDER: TrendState[] = ['consistent_up', 'stable', 'variable', 'consistent_down', 'insufficient'];

function countsLine(counts: TrendCounts): string {
    return COUNT_ORDER.filter((state) => counts.trend[state] > 0)
        .map((state) => `${TREND_ARROW[state]} ${counts.trend[state]} ${TREND_WORD[state].toLowerCase()}`)
        .join(' · ');
}

function persistentLine(counts: TrendCounts): string {
    return counts.with_latest === 0 ? 'Sem valores no momento mais recente' : `${counts.persistent.yes} de ${studentsCount(counts.with_latest)} com valor no momento mais recente`;
}

function cohortSentence(reading: ClassReading): string {
    return reading.cohort === 0 ? 'Nenhum aluno com valor em todos os momentos.' : `Média dos ${studentsCount(reading.cohort)} com valor em todos os momentos.`;
}
</script>

<template>
    <section aria-labelledby="tendencias-titulo" class="space-y-6" data-test="trends">
        <div>
            <h2 id="tendencias-titulo" class="scroll-mt-4 text-lg font-semibold tracking-tight">Tendências e resultados persistentes</h2>
            <p class="mt-0.5 text-sm text-muted-foreground">
                A turma, cada domínio e cada aluno ao longo do ano: o que se vê em três ou mais momentos (tendência) e o que se repete em
                momentos seguidos abaixo do nível positivo.
            </p>
        </div>

        <EmptyState
            v-if="!ready"
            title="Ainda não há trajetórias para ler."
            :description="trends.reason ?? undefined"
            :icon="TrendingUp"
        />

        <template v-else-if="trends.class.global">
            <!-- ================================================== as duas regras, lado a lado -->
            <div class="grid gap-3 sm:grid-cols-2" data-test="trends-rules">
                <div :class="[card('sky'), 'space-y-1 p-4 text-sm']">
                    <h3 class="font-semibold">Tendência: pelo menos 3 momentos</h3>
                    <p class="text-muted-foreground">
                        Progressão consistente, regressão consistente, estável ou evolução variável, a partir dos passos entre momentos
                        seguidos com valor.
                    </p>
                </div>
                <div :class="[card('amber'), 'space-y-1 p-4 text-sm']">
                    <h3 class="font-semibold">Abaixo do nível positivo persistente: 2 momentos</h3>
                    <p class="text-muted-foreground">
                        Abaixo do nível positivo no momento mais recente e no anterior. É outra pergunta: não é uma tendência.
                    </p>
                </div>
            </div>

            <!-- ================================================== a linha e os limites -->
            <div class="space-y-2 text-sm" data-test="trends-limits">
                <p class="text-muted-foreground" data-test="trends-line">
                    <span class="mr-1 font-medium text-foreground">Momentos da linha:</span>
                    <template v-for="(point, index) in trends.points" :key="point.key">
                        <span v-if="index > 0" aria-hidden="true"> → </span>
                        <span :class="point.comparable ? '' : 'line-through'">{{ point.label }}</span>
                    </template>
                    · {{ trends.availability.comparable_points }} comparáveis, ancorados em «{{ trends.reference?.label }}».
                </p>
                <p v-if="trends.availability.reason" class="flex gap-2 rounded-lg bg-muted/50 p-3" data-test="trends-reason">
                    <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                    <span>{{ trends.availability.reason }}</span>
                </p>
                <div
                    v-if="trends.left_out.length"
                    class="space-y-1 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 dark:border-amber-900/40 dark:bg-amber-950/20"
                    data-test="trends-left-out"
                >
                    <p class="flex gap-2 font-medium">
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                        Momentos fora da linha
                    </p>
                    <ul class="ml-6 list-disc space-y-0.5">
                        <li v-for="left in trends.left_out" :key="left.key">
                            <span class="font-medium">{{ left.label }}:</span> {{ left.reasons.map((reason) => reason.text).join(' ') }}
                        </li>
                    </ul>
                </div>
                <p
                    v-for="caution in trends.cautions"
                    :key="caution.code"
                    class="flex gap-2 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 dark:border-amber-900/40 dark:bg-amber-950/20"
                >
                    <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                    <span>{{ caution.text }}</span>
                </p>
            </div>

            <!-- ================================================== a turma e os domínios -->
            <section aria-labelledby="tend-turma" class="space-y-4">
                <SectionHeading
                    id="tend-turma"
                    index="01"
                    title="A turma e cada domínio"
                    description="A tendência da média dos mesmos alunos em todos os momentos; ao lado, quantos alunos há em cada tendência."
                />

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="trends-domains">
                        <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Domínio</th>
                                <th class="px-3 py-2 font-medium">Tendência da turma</th>
                                <th class="px-3 py-2 font-medium">Alunos por tendência</th>
                                <th class="px-3 py-2 font-medium">Abaixo do nível positivo persistente</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border align-top">
                            <tr data-test="trends-class-row">
                                <td class="px-3 py-2 font-medium">Resultado global</td>
                                <td class="px-3 py-2">
                                    <p class="font-medium" :class="TREND_CLASS[trends.class.global.trend.state]">
                                        {{ TREND_ARROW[trends.class.global.trend.state] }} {{ TREND_WORD[trends.class.global.trend.state] }}
                                    </p>
                                    <p class="text-xs text-muted-foreground tabular-nums">{{ trends.class.global.means.map((value) => percent(value)).join(' → ') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ stepsSentence(trends.class.global.trend) }}. {{ cohortSentence(trends.class.global) }}</p>
                                </td>
                                <td class="px-3 py-2 text-xs">{{ trends.counts.global ? countsLine(trends.counts.global) : '—' }}</td>
                                <td class="px-3 py-2 text-xs">{{ trends.counts.global ? persistentLine(trends.counts.global) : '—' }}</td>
                            </tr>
                            <tr v-for="(domain, index) in trends.class.domains" :key="domain.ulid" data-test="trends-domain-row">
                                <td class="px-3 py-2 font-medium">{{ domain.name }}</td>
                                <td class="px-3 py-2">
                                    <p class="font-medium" :class="TREND_CLASS[domain.trend.state]">{{ TREND_ARROW[domain.trend.state] }} {{ TREND_WORD[domain.trend.state] }}</p>
                                    <p class="text-xs text-muted-foreground tabular-nums">{{ domain.means.map((value) => percent(value)).join(' → ') }}</p>
                                    <p class="text-xs text-muted-foreground">{{ stepsSentence(domain.trend) }}. {{ cohortSentence(domain) }}</p>
                                </td>
                                <td class="px-3 py-2 text-xs">{{ countsLine(trends.counts.domains[index]) }}</td>
                                <td class="px-3 py-2 text-xs">{{ persistentLine(trends.counts.domains[index]) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================================================== os alunos -->
            <section aria-labelledby="tend-alunos" class="space-y-4">
                <SectionHeading
                    id="tend-alunos"
                    index="02"
                    title="Cada aluno"
                    description="Lista nominal, só para os professores da turma. Os valores e momentos mostrados sustentam cada conclusão; não são um diagnóstico."
                />

                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar alunos">
                    <button
                        v-for="item in filters"
                        :key="item.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs transition-colors"
                        :class="filter === item.key ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        :aria-pressed="filter === item.key"
                        :data-test="`trends-filter-${item.key}`"
                        @click="filter = item.key"
                    >
                        {{ item.label }} ({{ item.count }})
                    </button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="trends-students">
                        <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">N.º</th>
                                <th class="px-3 py-2 font-medium">Aluno</th>
                                <th class="px-3 py-2 font-medium">Resultado global ao longo do ano</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="row in visible" :key="row.enrollment_ulid">
                                <tr class="border-t border-border align-top" :data-test="`trends-student-${row.global.trend.state}${row.global.persistent.state === 'yes' ? '-persistent' : ''}`">
                                    <td class="px-3 py-2 tabular-nums text-muted-foreground">{{ row.class_number ?? '' }}</td>
                                    <td class="px-3 py-2 font-medium">
                                        <button type="button" class="text-left hover:underline" :aria-expanded="expanded.has(row.enrollment_ulid)" @click="toggle(row.enrollment_ulid)">
                                            {{ row.name }}
                                        </button>
                                        <p v-if="domainsPersistent(row).length" class="mt-0.5 text-xs font-normal text-muted-foreground">
                                            Também em: {{ domainsPersistent(row).join(', ') }}
                                        </p>
                                    </td>
                                    <td class="px-3 py-2"><TrajectorySummary :reading="row.global" :points="trends.points" /></td>
                                </tr>
                                <tr v-if="expanded.has(row.enrollment_ulid)" class="bg-muted/20" data-test="trends-student-detail">
                                    <td />
                                    <td colspan="2" class="px-3 py-3">
                                        <div class="grid gap-4 sm:grid-cols-2">
                                            <div v-for="domain in trends.class.domains" :key="domain.ulid" class="space-y-1">
                                                <p class="text-xs font-semibold uppercase tracking-wider text-muted-foreground">{{ domain.name }}</p>
                                                <TrajectorySummary :reading="row.domains[domain.ulid]" :points="trends.points" />
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="visible.length === 0">
                                <td colspan="3" class="px-3 py-6 text-center text-muted-foreground">Nenhum aluno neste grupo.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================================================== explicações -->
            <div class="divide-y divide-border rounded-lg border border-border">
                <details v-for="item in trends.explanations" :key="item.key" class="px-4 py-3 text-sm" :data-test="`trends-how-${item.key}`">
                    <summary class="cursor-pointer font-medium">{{ item.title }}</summary>
                    <p class="mt-2 leading-relaxed text-muted-foreground">{{ item.text }}</p>
                </details>
            </div>
        </template>
    </section>
</template>
