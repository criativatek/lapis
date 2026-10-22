<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowRight, ArrowUpRight, CircleAlert, Info, Minus, Scale, TrendingUp, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import StrengthsSection from '@/components/assessment-analysis/StrengthsSection.vue';
import TrendsSection from '@/components/assessment-analysis/TrendsSection.vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import KpiCard from '@/components/infographic/KpiCard.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import {
    levelChangeText,
    levelReasonWord,
    movementWord,
    percent,
    points,
    reasonWord,
    studentsCount,
} from '@/lib/assessmentComparison';
import type { Comparison, ComparedStudent, Counts, PointInfo, StudentMovement } from '@/lib/assessmentComparison';
import type { ClassStrengths } from '@/lib/classStrengths';
import { card } from '@/lib/surfaces';
import type { ClassTrends } from '@/lib/trajectories';

/**
 * Análise da Avaliação — comparar dois momentos de uma turma (Pro).
 *
 * LÊ-SE, NÃO SE CALCULA. Tudo o que está nesta página — médias, variações, contagens, mudanças
 * de nível, as razões de uma comparação recusada — chega calculado do servidor, que o monta a
 * partir dos mesmos números de Resultados e da Pauta. A página escolhe o que mostrar primeiro e
 * em que palavras; não faz contas.
 *
 * NUNCA UMA COMPARAÇÃO ARTIFICIAL. Quando os dois momentos não estão demonstravelmente sob os
 * mesmos critérios, os números aparecem lado a lado e a variação não existe: em vez dela, o
 * motivo, por extenso. Um aluno só com um dos momentos não é «zero»: é «sem comparação».
 *
 * NÃO É UM VEREDICTO. «Progrediu» é a regra que a aplicação já tem — qualquer alteração na casa
 * decimal mostrada — e a página explica-a (secção «Como se calcula»). Nenhuma causa é sugerida.
 */

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    availability: { comparison: boolean; trends: boolean; reason: string | null };
    comparison: Comparison;
    strengths: ClassStrengths;
    trends: ClassTrends;
}>();

const base = computed(() => `/classes/${props.schoolClass.ulid}/analise-avaliacao`);

// ------------------------------------------------------------------ escolha dos momentos

const fromKey = ref(props.comparison.selection.from ?? '');
const toKey = ref(props.comparison.selection.to ?? '');

// The server may order the two moments in time (or fall back from an invalid choice): the
// selects follow what is being shown, never what was last clicked.
watch(
    () => props.comparison.selection,
    (selection) => {
        fromKey.value = selection.from ?? '';
        toKey.value = selection.to ?? '';
    },
);

function choose(): void {
    if (fromKey.value === '' || toKey.value === '' || fromKey.value === toKey.value) {
        return;
    }

    visit({ de: fromKey.value, para: toKey.value });
}

/**
 * The two sections of the page share one URL: asking for other moments in one keeps what the other
 * is showing, so choosing a moment for the strengths never undoes the comparison and vice versa.
 */
function visit(changes: { de?: string; para?: string; momento?: string }): void {
    const query: Record<string, string> = {};
    const comparisonIsExplicit = !props.comparison.selection.defaulted;
    const from = changes.de ?? (comparisonIsExplicit ? props.comparison.selection.from : null);
    const to = changes.para ?? (comparisonIsExplicit ? props.comparison.selection.to : null);
    const moment = changes.momento ?? (props.strengths.selection.defaulted ? null : props.strengths.selection.moment);

    if (from && to) {
        query.de = from;
        query.para = to;
    }

    if (moment) {
        query.momento = moment;
    }

    router.get(base.value, query, { preserveScroll: true, preserveState: true, replace: true });
}

function chooseMoment(momentKey: string): void {
    visit({ momento: momentKey });
}

function optionText(point: PointInfo): string {
    return `${point.label} — ${point.basis.kind === 'frozen' ? point.basis.label : 'hoje'} (${point.students_with_result}/${point.students_total})`;
}

// ------------------------------------------------------------------ leitura

const ready = computed(() => props.comparison.state === 'ready');
const cls = computed(() => props.comparison.class);
const levelsOk = computed(() => props.comparison.levels?.comparable === true);
const percentagesOk = computed(() => props.comparison.comparability?.percentages === true);

function share(counts: Counts, key: 'up' | 'flat' | 'down'): string | null {
    return counts === null ? null : `${counts[key]}`;
}

const classChange = computed(() => cls.value?.paired.change ?? null);

const classTrend = computed(() => {
    const change = classChange.value;

    return change === null ? null : { display: { up: 'Subiu', down: 'Desceu', flat: 'Estável' }[change.direction], direction: change.direction };
});

// Alunos: filtro por estado e linha expandida.
type Filter = 'all' | 'up' | 'flat' | 'down' | 'unavailable';
const filter = ref<Filter>('all');
const expanded = ref<Set<string>>(new Set());

const filters = computed<Array<{ key: Filter; label: string; count: number }>>(() => {
    const all = props.comparison.students;
    const count = (state: StudentMovement['state']) => all.filter((student) => student.movement.state === state).length;

    return [
        { key: 'all', label: 'Todos', count: all.length },
        { key: 'up', label: 'Progrediram', count: count('up') },
        { key: 'flat', label: 'Mantiveram-se', count: count('flat') },
        { key: 'down', label: 'Regrediram', count: count('down') },
        { key: 'unavailable', label: 'Sem comparação', count: count('unavailable') },
    ];
});

const visibleStudents = computed(() =>
    filter.value === 'all' ? props.comparison.students : props.comparison.students.filter((student) => student.movement.state === filter.value),
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

function figureText(figure: ComparedStudent['from']): string {
    if (figure === null || figure.value === null) {
        return '—';
    }

    return `${percent(figure.value)}${figure.band?.label ? ` · ${figure.band.label}` : ''}`;
}

function assignedText(student: ComparedStudent): string {
    const { from, to, change } = student.assigned;

    if (from === null && to === null && student.assigned.from_value === null && student.assigned.to_value === null) {
        return '—';
    }

    if (change.state === 'unavailable' && change.reason === 'missing') {
        return 'Sem nível atribuído num dos momentos.';
    }

    return levelChangeText(change);
}

function hasDistribution(summary: { distribution: Array<{ from: number; to: number }> }): boolean {
    return summary.distribution.some((level) => level.from > 0 || level.to > 0);
}

const MOVEMENT_ICON = { up: ArrowUpRight, down: ArrowDownRight, flat: Minus, unavailable: Minus } as const;
const MOVEMENT_CLASS: Record<StudentMovement['state'], string> = {
    up: 'text-emerald-700 dark:text-emerald-400',
    down: 'text-rose-700 dark:text-rose-400',
    flat: 'text-muted-foreground',
    unavailable: 'text-muted-foreground',
};
</script>

<template>
    <Head title="Análise da Avaliação" />

    <div class="mx-auto w-full max-w-6xl space-y-8 p-4">
        <!-- ================================================== cabeçalho -->
        <div class="space-y-1">
            <Heading
                title="Análise da Avaliação"
                :description="`${schoolClass.label} · ${schoolClass.subject} — a avaliação da turma ao longo do tempo e num momento.`"
            />
            <div class="flex flex-wrap gap-x-3 gap-y-1 text-sm">
                <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                <Link :href="`/classes/${schoolClass.ulid}/results/estatistica`" class="text-primary hover:underline">Estatística →</Link>
                <Link :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`" class="text-primary hover:underline">Quadro Síntese →</Link>
            </div>
            <nav aria-label="Nesta página" class="flex flex-wrap gap-2 pt-2 text-sm">
                <a href="#comparar" class="rounded-full border border-border px-3 py-1 hover:bg-muted/40">Comparar dois momentos</a>
                <a href="#pontos-fortes-titulo" class="rounded-full border border-border px-3 py-1 hover:bg-muted/40">Pontos fortes e dificuldades</a>
                <a href="#tendencias-titulo" class="rounded-full border border-border px-3 py-1 hover:bg-muted/40">Tendências e resultados persistentes</a>
            </nav>
        </div>

        <h2 id="comparar" class="scroll-mt-4 text-lg font-semibold tracking-tight">Comparar dois momentos</h2>

        <!-- ================================================== sem dados suficientes -->
        <EmptyState
            v-if="!ready"
            title="Ainda não há dois momentos para comparar."
            :description="comparison.reason ?? availability.reason ?? undefined"
            :icon="Scale"
        />

        <template v-else-if="comparison.from && comparison.to && cls">
            <!-- ================================================== os dois momentos -->
            <section aria-labelledby="momentos" class="space-y-4">
                <SectionHeading
                    id="momentos"
                    index="01"
                    title="Os dois momentos"
                    description="Escolha o ponto de partida e o ponto de chegada. Só aparecem momentos com resultados."
                />

                <form class="grid gap-3 sm:grid-cols-[1fr_auto_1fr]" @submit.prevent="choose">
                    <label class="space-y-1 text-sm">
                        <span class="font-medium">De</span>
                        <select
                            v-model="fromKey"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            data-test="select-from"
                            @change="choose"
                        >
                            <option v-for="option in comparison.options" :key="option.key" :value="option.key">{{ optionText(option) }}</option>
                        </select>
                    </label>
                    <ArrowRight class="hidden size-5 self-end pb-2.5 text-muted-foreground sm:block" aria-hidden="true" />
                    <label class="space-y-1 text-sm">
                        <span class="font-medium">Para</span>
                        <select
                            v-model="toKey"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            data-test="select-to"
                            @change="choose"
                        >
                            <option v-for="option in comparison.options" :key="option.key" :value="option.key">{{ optionText(option) }}</option>
                        </select>
                    </label>
                </form>

                <p v-if="comparison.selection.swapped" class="text-xs text-muted-foreground">
                    Os momentos foram ordenados no tempo: o mais antigo é o ponto de partida.
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div
                        v-for="(point, index) in [comparison.from, comparison.to]"
                        :key="point.key"
                        :class="[card('plain'), 'space-y-1 p-4 text-sm']"
                        :data-test="index === 0 ? 'point-from' : 'point-to'"
                    >
                        <p class="text-xs uppercase tracking-wide text-muted-foreground">{{ index === 0 ? 'De' : 'Para' }}</p>
                        <p class="text-base font-semibold">{{ point.label }}</p>
                        <p class="text-muted-foreground">{{ point.basis.label }}</p>
                        <p>
                            {{ point.students_with_result }} de {{ studentsCount(point.students_total) }} com resultado
                            <span v-if="point.partial_coverage > 0" class="text-amber-700 dark:text-amber-500">
                                · {{ point.partial_coverage }} com cobertura parcial
                            </span>
                        </p>
                    </div>
                </div>

                <!-- A origem dos dados e o que limita a comparação: sempre à vista. -->
                <div class="space-y-2" data-test="limits">
                    <p v-if="comparison.basis_note" class="flex gap-2 rounded-lg bg-muted/50 p-3 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                        <span>{{ comparison.basis_note }}</span>
                    </p>
                    <p
                        v-for="caution in comparison.cautions"
                        :key="caution.code + caution.text"
                        class="flex gap-2 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 text-sm dark:border-amber-900/40 dark:bg-amber-950/20"
                    >
                        <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                        <span>{{ caution.text }}</span>
                    </p>
                    <div
                        v-if="comparison.comparability && comparison.comparability.reasons.length"
                        class="space-y-1 rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 text-sm dark:border-amber-900/40 dark:bg-amber-950/20"
                        data-test="not-comparable"
                    >
                        <p class="flex gap-2 font-medium">
                            <CircleAlert class="mt-0.5 size-4 shrink-0 text-amber-700 dark:text-amber-500" aria-hidden="true" />
                            Estes dois momentos não são totalmente comparáveis
                        </p>
                        <ul class="ml-6 list-disc space-y-0.5">
                            <li v-for="reason in comparison.comparability.reasons" :key="reason.code">{{ reason.text }}</li>
                        </ul>
                    </div>
                    <p v-for="notice in comparison.notices.filter((item) => item.text !== '')" :key="notice.code + notice.moment" class="text-xs text-muted-foreground">
                        {{ notice.moment ? `${notice.moment}: ` : '' }}{{ notice.text }}
                    </p>
                </div>
            </section>

            <!-- ================================================== a turma -->
            <section aria-labelledby="turma" class="space-y-4">
                <SectionHeading
                    id="turma"
                    index="02"
                    title="A turma"
                    description="A evolução é a dos mesmos alunos nos dois momentos — os que têm resultado em ambos."
                />

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <KpiCard
                        tone="sky"
                        :icon="TrendingUp"
                        label="Evolução da turma"
                        :value="classChange ? points(classChange.points) : '—'"
                        :context="
                            percentagesOk
                                ? `${percent(cls.paired.from_mean)} → ${percent(cls.paired.to_mean)} · ${studentsCount(cls.paired.students)}`
                                : 'Sem comparação: critérios diferentes'
                        "
                        :trend="classTrend"
                    />
                    <KpiCard
                        tone="mint"
                        :icon="ArrowUpRight"
                        label="Progrediram"
                        :value="share(cls.counts, 'up') ?? '—'"
                        :context="cls.counts ? `de ${studentsCount(cls.counts.compared)} comparados` : 'Sem comparação'"
                    />
                    <KpiCard
                        tone="plain"
                        :icon="Minus"
                        label="Mantiveram-se"
                        :value="share(cls.counts, 'flat') ?? '—'"
                        :context="cls.counts ? `de ${studentsCount(cls.counts.compared)} comparados` : 'Sem comparação'"
                    />
                    <KpiCard
                        tone="rose"
                        :icon="ArrowDownRight"
                        label="Regrediram"
                        :value="share(cls.counts, 'down') ?? '—'"
                        :context="cls.counts ? `de ${studentsCount(cls.counts.compared)} comparados` : 'Sem comparação'"
                    />
                </div>

                <dl class="grid gap-3 text-sm sm:grid-cols-2" data-test="class-means">
                    <div :class="[card('plain'), 'p-4']">
                        <dt class="text-muted-foreground">Média em «{{ comparison.from.label }}»</dt>
                        <dd class="text-lg font-semibold">{{ percent(cls.moments.from.mean) }}</dd>
                        <dd class="text-xs text-muted-foreground">{{ studentsCount(cls.moments.from.students) }} com resultado</dd>
                    </div>
                    <div :class="[card('plain'), 'p-4']">
                        <dt class="text-muted-foreground">Média em «{{ comparison.to.label }}»</dt>
                        <dd class="text-lg font-semibold">{{ percent(cls.moments.to.mean) }}</dd>
                        <dd class="text-xs text-muted-foreground">{{ studentsCount(cls.moments.to.students) }} com resultado</dd>
                    </div>
                </dl>

                <p
                    v-if="cls.not_compared.missing_from + cls.not_compared.missing_to + cls.not_compared.missing_both > 0"
                    class="text-sm text-muted-foreground"
                    data-test="not-compared-note"
                >
                    <span v-if="cls.not_compared.missing_from">{{ studentsCount(cls.not_compared.missing_from) }} sem resultado no primeiro momento. </span>
                    <span v-if="cls.not_compared.missing_to">{{ studentsCount(cls.not_compared.missing_to) }} sem resultado no segundo momento. </span>
                    <span v-if="cls.not_compared.missing_both">{{ studentsCount(cls.not_compared.missing_both) }} sem resultado em nenhum dos momentos. </span>
                    Um resultado em falta não conta como zero.
                </p>
            </section>

            <!-- ================================================== domínios -->
            <section v-if="comparison.domains.length" aria-labelledby="dominios" class="space-y-4">
                <SectionHeading
                    id="dominios"
                    index="03"
                    title="Por domínio"
                    description="O mesmo cálculo, domínio a domínio, sobre os alunos com valor nos dois momentos."
                />

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="domains-table">
                        <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">Domínio</th>
                                <th class="px-3 py-2 text-right font-medium">Peso</th>
                                <th class="px-3 py-2 text-right font-medium">{{ comparison.from.label }}</th>
                                <th class="px-3 py-2 text-right font-medium">{{ comparison.to.label }}</th>
                                <th class="px-3 py-2 text-right font-medium">Variação</th>
                                <th class="px-3 py-2 text-right font-medium">Prog. / Man. / Reg.</th>
                                <th class="px-3 py-2 text-right font-medium">Alunos</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="domain in comparison.domains" :key="domain.ulid">
                                <td class="px-3 py-2 font-medium">{{ domain.name }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">
                                    {{ domain.weight_percent === null ? '—' : percent(domain.weight_percent) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ percent(domain.paired.from_mean) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ percent(domain.paired.to_mean) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <span v-if="domain.paired.change" :class="MOVEMENT_CLASS[domain.paired.change.direction]">
                                        {{ points(domain.paired.change.points) }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    <template v-if="domain.counts">{{ domain.counts.up }} / {{ domain.counts.flat }} / {{ domain.counts.down }}</template>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums text-muted-foreground">{{ domain.paired.students }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- ================================================== níveis -->
            <section aria-labelledby="niveis" class="space-y-4">
                <SectionHeading
                    id="niveis"
                    index="04"
                    title="Mudanças de nível"
                    description="O nível do resultado e o nível atribuído pelo professor, na ordem e na linha da própria escala."
                />

                <div
                    v-if="comparison.levels && !levelsOk"
                    class="rounded-lg border border-amber-200/70 bg-amber-50/70 p-3 text-sm dark:border-amber-900/40 dark:bg-amber-950/20"
                    data-test="levels-refused"
                >
                    <p class="font-medium">Os níveis não se comparam entre estes dois momentos.</p>
                    <ul class="ml-5 list-disc">
                        <li v-for="reason in comparison.levels.reasons" :key="reason.code">{{ reason.text }}</li>
                    </ul>
                </div>

                <div v-else-if="comparison.levels?.result && comparison.levels.assigned" class="grid gap-4 lg:grid-cols-2" data-test="levels-block">
                    <div v-for="block in [
                        { title: 'Nível do resultado', data: comparison.levels.result },
                        { title: 'Nível atribuído', data: comparison.levels.assigned },
                    ]" :key="block.title" :class="[card('plain'), 'space-y-3 p-4 text-sm']">
                        <h3 class="font-semibold">{{ block.title }}</h3>
                        <p>
                            <span class="text-emerald-700 dark:text-emerald-400">{{ block.data.counts.up }} subiram</span> ·
                            {{ block.data.counts.same }} mantiveram ·
                            <span class="text-rose-700 dark:text-rose-400">{{ block.data.counts.down }} desceram</span>
                            <span class="text-muted-foreground"> (de {{ studentsCount(block.data.counts.compared) }})</span>
                        </p>
                        <p v-if="block.data.crossed.to_positive || block.data.crossed.to_negative">
                            {{ block.data.crossed.to_positive }} passaram a nível positivo · {{ block.data.crossed.to_negative }} passaram a nível negativo
                        </p>
                        <p v-if="block.data.counts.not_compared > 0" class="text-xs text-muted-foreground">
                            {{ studentsCount(block.data.counts.not_compared) }} sem nível num dos momentos.
                        </p>
                        <p v-if="!hasDistribution(block.data)" class="text-xs text-muted-foreground">Não há níveis para mostrar nestes dois momentos.</p>
                        <table v-else class="w-full text-xs">
                            <thead class="text-left text-muted-foreground">
                                <tr>
                                    <th class="py-1 font-medium">Nível</th>
                                    <th class="py-1 text-right font-medium">{{ comparison.from.label }}</th>
                                    <th class="py-1 text-right font-medium">{{ comparison.to.label }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="level in block.data.distribution" :key="level.code" class="border-t border-border/60">
                                    <td class="py-1">{{ level.label }}<span v-if="level.is_negative" class="text-muted-foreground"> (negativo)</span></td>
                                    <td class="py-1 text-right tabular-nums">{{ level.from }}</td>
                                    <td class="py-1 text-right tabular-nums">{{ level.to }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- ================================================== alunos -->
            <section aria-labelledby="alunos" class="space-y-4">
                <SectionHeading
                    id="alunos"
                    index="05"
                    title="Cada aluno"
                    description="Lista nominal, só para os professores da turma. Os números são os de Resultados."
                />

                <div class="flex flex-wrap gap-1.5" role="group" aria-label="Filtrar alunos">
                    <button
                        v-for="item in filters"
                        :key="item.key"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs transition-colors"
                        :class="filter === item.key ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                        :aria-pressed="filter === item.key"
                        :data-test="`filter-${item.key}`"
                        @click="filter = item.key"
                    >
                        {{ item.label }} ({{ item.count }})
                    </button>
                </div>

                <div class="overflow-x-auto rounded-lg border border-border">
                    <table class="w-full text-sm" data-test="students-table">
                        <thead class="bg-muted/40 text-left text-xs text-muted-foreground">
                            <tr>
                                <th class="px-3 py-2 font-medium">N.º</th>
                                <th class="px-3 py-2 font-medium">Aluno</th>
                                <th class="px-3 py-2 font-medium">{{ comparison.from.label }}</th>
                                <th class="px-3 py-2 font-medium">{{ comparison.to.label }}</th>
                                <th class="px-3 py-2 font-medium">Variação</th>
                                <th class="px-3 py-2 font-medium">Nível atribuído</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="student in visibleStudents" :key="student.enrollment_ulid">
                                <tr class="border-t border-border align-top" :data-test="`student-${student.movement.state}`">
                                    <td class="px-3 py-2 tabular-nums text-muted-foreground">{{ student.class_number ?? '' }}</td>
                                    <td class="px-3 py-2 font-medium">
                                        <button
                                            type="button"
                                            class="text-left hover:underline"
                                            :aria-expanded="expanded.has(student.enrollment_ulid)"
                                            @click="toggle(student.enrollment_ulid)"
                                        >
                                            {{ student.name }}
                                        </button>
                                    </td>
                                    <td class="px-3 py-2 tabular-nums">{{ figureText(student.from) }}</td>
                                    <td class="px-3 py-2 tabular-nums">{{ figureText(student.to) }}</td>
                                    <td class="px-3 py-2">
                                        <span class="inline-flex items-center gap-1" :class="MOVEMENT_CLASS[student.movement.state]">
                                            <component :is="MOVEMENT_ICON[student.movement.state]" class="size-4" aria-hidden="true" />
                                            {{ movementWord(student.movement.state) }}
                                            <span v-if="student.movement.points !== null" class="tabular-nums">({{ points(student.movement.points) }})</span>
                                        </span>
                                        <p v-if="student.movement.reason" class="text-xs text-muted-foreground">{{ reasonWord(student.movement.reason) }}</p>
                                    </td>
                                    <td class="px-3 py-2">{{ assignedText(student) }}</td>
                                </tr>
                                <tr v-if="expanded.has(student.enrollment_ulid)" class="bg-muted/20" data-test="student-detail">
                                    <td />
                                    <td colspan="5" class="px-3 py-2">
                                        <ul class="grid gap-x-6 gap-y-1 text-xs sm:grid-cols-2">
                                            <li v-for="domain in comparison.domains" :key="domain.ulid" class="flex justify-between gap-3">
                                                <span>{{ domain.name }}</span>
                                                <span class="tabular-nums">
                                                    {{ percent(student.domains[domain.ulid]?.from) }} → {{ percent(student.domains[domain.ulid]?.to) }}
                                                    <span v-if="student.domains[domain.ulid]?.movement.points !== null" :class="MOVEMENT_CLASS[student.domains[domain.ulid].movement.state]">
                                                        ({{ points(student.domains[domain.ulid].movement.points) }})
                                                    </span>
                                                </span>
                                            </li>
                                        </ul>
                                        <p v-if="student.result_level.state !== 'unavailable'" class="mt-2 text-xs text-muted-foreground">
                                            Nível do resultado: {{ levelChangeText(student.result_level) }}
                                        </p>
                                        <p v-else-if="levelsOk" class="mt-2 text-xs text-muted-foreground">
                                            Nível do resultado: {{ levelReasonWord(student.result_level.reason) }}
                                        </p>
                                    </td>
                                </tr>
                            </template>
                            <tr v-if="visibleStudents.length === 0">
                                <td colspan="6" class="px-3 py-6 text-center text-muted-foreground">Nenhum aluno neste grupo.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Users class="size-3.5" aria-hidden="true" />
                    A lista é de uso do professor da turma; um número sozinho não é um diagnóstico.
                </p>
            </section>

            <!-- ================================================== como se calcula -->
            <section aria-labelledby="calculo" class="space-y-3">
                <SectionHeading id="calculo" index="06" title="Como se calcula" description="As regras por trás de cada número, em palavras simples." />
                <div class="divide-y divide-border rounded-lg border border-border">
                    <details v-for="item in comparison.explanations" :key="item.key" class="group px-4 py-3 text-sm" :data-test="`how-${item.key}`">
                        <summary class="cursor-pointer font-medium">{{ item.title }}</summary>
                        <p class="mt-2 leading-relaxed text-muted-foreground">{{ item.text }}</p>
                    </details>
                </div>
            </section>
        </template>

        <hr class="border-border" />

        <StrengthsSection :strengths="strengths" @select="chooseMoment" />

        <hr class="border-border" />

        <TrendsSection :trends="trends" />
    </div>
</template>
