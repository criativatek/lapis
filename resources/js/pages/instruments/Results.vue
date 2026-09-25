<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import DataTable from '@/components/analysis/DataTable.vue';
import LabelledBarChart from '@/components/analysis/LabelledBarChart.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TableShell from '@/components/TableShell.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import { statusToneClasses } from '@/lib/statusTone';
import type { Analysis, Cell, ResultsAnalysisProps } from '@/types/resultsAnalysis';

const props = defineProps<ResultsAnalysisProps>();

// ------------------------------------------------------------- identificação

const appliedOnFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone: 'UTC',
});

const appliedOnLabel = computed(() =>
    appliedOnFormatter.format(new Date(`${props.context.instrument.applied_on}T00:00:00Z`)),
);

// ------------------------------------------------------------- formatação

/** «72,4 %», nunca zero para null — a marca já vem em 1 casa do servidor. */
function pct(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return `${Number(value).toFixed(1).replace('.', ',')} %`;
}

/** «72,42 %» — o valor truncado a 2 casas, vírgula decimal pt-PT. */
function pctPrecise(value: string): string {
    return `${Number(value).toFixed(2).replace('.', ',')} %`;
}

function toneClassFor(band: Cell['band']): string {
    if (band === null) {
        return qualitativeToneClasses.neutral;
    }

    return qualitativeToneClasses[qualitativeToneFor(band, props.context.scale.bands)];
}

/**
 * O servidor só envia `value_precise` quando o arredondamento a 1 casa
 * sugeriria outra banda ou o outro lado do limiar (§Anexo A) — nunca
 * recalculado aqui.
 */
function displayValue(cell: Cell): string {
    return cell.value_precise !== null ? pctPrecise(cell.value_precise) : pct(cell.value);
}

function hasPreciseFootnote(cell: Cell): boolean {
    return cell.value_precise !== null;
}

// ------------------------------------------------------------- dimensão selecionada

const selectedDimensionKey = ref<string>('global');

const selectedDimension = computed(() =>
    props.dimensions.find((dimension) => dimension.key === selectedDimensionKey.value) ?? props.dimensions[0],
);

const selectedAnalysis = computed<Analysis>(() => selectedDimension.value.analysis);

const thresholdColumnsAvailable = computed(() => props.context.threshold.value !== null);

// ------------------------------------------------------------- missing breakdown

type MissingRow = { key: string; label: string; count: number };

const MISSING_LABELS: Record<string, string> = {
    pending: 'Por classificar',
    under_review: 'Em revisão',
    absent: 'Ausentes',
    absent_justified: 'Ausências justificadas',
    exempt: 'Dispensados',
    not_applicable: 'Não aplicável',
    annulled: 'Anulados',
};

const missingBreakdown = computed<MissingRow[]>(() => {
    const missing = selectedAnalysis.value.missing;

    return (Object.keys(MISSING_LABELS) as Array<keyof typeof MISSING_LABELS>)
        .map((key) => ({ key, label: MISSING_LABELS[key], count: missing[key as keyof typeof missing] as number }))
        .filter((row) => row.count > 0);
});

// ------------------------------------------------------------- distribuição

const quantitativeChartTitle = computed(() => {
    const label = selectedDimension.value.key === 'global' ? 'Classificação global' : selectedDimension.value.label;

    return `Distribuição quantitativa — ${label}`;
});

const qualitativeChartTitle = computed(() => {
    const label = selectedDimension.value.key === 'global' ? 'Classificação global' : selectedDimension.value.label;

    return `Distribuição qualitativa — ${label}`;
});

const quantitativeCategories = computed(() =>
    selectedAnalysis.value.quantitative.classes.map((row) => ({
        key: row.key,
        label: row.label,
        count: row.count,
        percent: row.percent,
        tone: row.below_threshold ? ('red' as const) : ('neutral' as const),
        emphasis: row.below_threshold ? ('below' as const) : null,
    })),
);

const quantitativeRows = computed(() =>
    selectedAnalysis.value.quantitative.classes.map((row) => ({
        key: row.key,
        label: row.label,
        count: row.count,
        percent: row.percent,
    })),
);

const qualitativeCategories = computed(() => {
    const analysis = selectedAnalysis.value.qualitative;

    const bandRows = analysis.categories.map((category) => ({
        key: category.key,
        label: category.label,
        count: category.count,
        percent: category.percent,
        tone: qualitativeToneFor(category, props.context.scale.bands),
        emphasis: category.is_negative ? ('below' as const) : null,
    }));

    if (analysis.unplaced > 0) {
        bandRows.push({
            key: 'unplaced',
            label: 'Sem apreciação',
            count: analysis.unplaced,
            percent: null,
            tone: 'neutral',
            emphasis: null,
        });
    }

    return bandRows;
});

const qualitativeRows = computed(() => {
    const analysis = selectedAnalysis.value.qualitative;
    const rows = analysis.categories.map((category) => ({
        key: category.key,
        label: category.label,
        count: category.count,
        percent: category.percent,
    }));

    if (analysis.unplaced > 0) {
        rows.push({ key: 'unplaced', label: 'Sem apreciação', count: analysis.unplaced, percent: null });
    }

    return rows;
});

// ------------------------------------------------------------- alunos (ordenação)

const orderedStudents = computed(() => {
    const inScope = props.students.filter((student) => student.status !== 'out_of_scope');
    const outOfScope = props.students.filter((student) => student.status === 'out_of_scope');

    return [...inScope, ...outOfScope];
});

// ------------------------------------------------------------- observações do professor

const noteForm = useForm<{ body: string; lock_version: number }>({
    body: props.note.body,
    lock_version: props.note.lock_version,
});

function submitNote(): void {
    noteForm.put(props.links.note, { preserveScroll: true });
}

const noteUpdatedLabel = computed(() => {
    if (props.note.updated_at === null) {
        return null;
    }

    const formatted = new Intl.DateTimeFormat('pt-PT', {
        dateStyle: 'short',
        timeStyle: 'short',
    }).format(new Date(props.note.updated_at));

    return props.note.updated_by ? `${formatted} · ${props.note.updated_by}` : formatted;
});
</script>

<template>
    <Head :title="context.instrument.title" />

    <div class="space-y-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <div class="flex flex-wrap items-start gap-3">
                    <Heading
                        :title="context.instrument.title"
                        :description="`${context.class.label} · ${context.period.label} · ${appliedOnLabel}`"
                    />
                    <Badge class="mt-1" variant="secondary" :class="statusToneClasses(context.instrument.status)">
                        {{ context.instrument.status_label }}
                    </Badge>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ context.instrument.type ?? 'Sem tipo' }} · {{ context.instrument.purpose_label }}
                </p>
                <Link :href="`/classes/${context.class.ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
        </div>

        <!-- Separador Grelha de correção | Resultados -->
        <nav class="flex gap-1 border-b border-border" aria-label="Secções do elemento de avaliação">
            <a
                :href="links.grid"
                class="border-b-2 border-transparent px-3 py-2 text-sm text-muted-foreground hover:text-foreground"
            >
                Grelha de correção
            </a>
            <span class="border-b-2 border-primary px-3 py-2 text-sm font-medium text-foreground" aria-current="page">
                Resultados
            </span>
        </nav>

        <!-- Estado não oficial: nada de indicadores, gráficos, tabelas ou números -->
        <div
            v-if="!availability.official"
            class="rounded-md border border-border bg-muted/20 px-4 py-3 text-sm"
            role="status"
        >
            <p class="font-medium">Estado: {{ availability.status_label }}</p>
            <p v-if="availability.message" class="mt-1 text-muted-foreground">{{ availability.message }}</p>
            <a :href="links.grid" class="mt-2 inline-block text-sm text-muted-foreground hover:underline">
                ← Voltar à grelha de correção
            </a>
        </div>

        <!-- Avisos de diagnóstico -->
        <div
            v-if="availability.official && context.is_diagnostic"
            class="rounded-md border border-sky-300 bg-sky-50 px-4 py-3 text-sm text-sky-950 dark:border-sky-900 dark:bg-sky-950/40 dark:text-sky-100"
            role="status"
        >
            Avaliação diagnóstica — utiliza a mesma escala e a mesma linguagem de avaliação dos
            restantes instrumentos. Serve para identificar potencialidades, dificuldades e necessidades
            de acompanhamento.
            <template v-if="!context.counts_toward_classification">
                Está configurada para não contar para a classificação e, por isso, não entra nas médias
                classificativas do período.
            </template>
        </div>

        <div
            v-if="availability.official && context.diagnostic_counts_warning"
            class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-100"
            role="alert"
        >
            Este instrumento é diagnóstico, mas está atualmente configurado para contar para a
            classificação do período — contrário à regra do produto. Reveja esta opção em «Editar
            elemento de avaliação».
        </div>

        <!-- a) Resultados por aluno -->
        <section v-if="availability.official" aria-labelledby="section-students" class="space-y-3">
            <h2 id="section-students" class="text-base font-semibold">Resultados por aluno</h2>

            <TableShell v-if="orderedStudents.length > 0">
                <template #head>
                    <tr>
                        <th scope="col" class="px-3 py-2 font-medium">Nº</th>
                        <th scope="col" class="px-3 py-2 text-left font-medium">Aluno</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Classificação global</th>
                        <th scope="col" class="px-3 py-2 text-left font-medium">Apreciação</th>
                        <th
                            v-for="domain in context.domains"
                            :key="domain.key"
                            scope="col"
                            class="px-3 py-2 text-left font-medium"
                        >
                            {{ domain.name }}
                        </th>
                    </tr>
                </template>
                <template #body>
                    <tr
                        v-for="student in orderedStudents"
                        :key="student.enrollment_id"
                        :class="student.status === 'out_of_scope' ? 'text-muted-foreground' : ''"
                    >
                        <td class="px-3 py-1.5">{{ student.class_number ?? '—' }}</td>
                        <td class="px-3 py-1.5 font-medium">{{ student.name }}</td>

                        <template v-if="student.status === 'out_of_scope'">
                            <td class="px-3 py-1.5 text-right" colspan="1">Não abrangido</td>
                            <td class="px-3 py-1.5" :colspan="1 + context.domains.length">—</td>
                        </template>
                        <template v-else-if="student.global.value === null">
                            <td class="px-3 py-1.5 text-right">{{ student.status_label }}</td>
                            <td class="px-3 py-1.5" :colspan="1 + context.domains.length">—</td>
                        </template>
                        <template v-else>
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ displayValue(student.global) }}
                                <sup
                                    v-if="hasPreciseFootnote(student.global)"
                                    class="cursor-help text-amber-600"
                                    title="Valor apresentado com duas casas: o arredondamento a uma casa sugeriria outra apreciação ou o outro lado do limiar; a apreciação usa o valor exato."
                                    aria-describedby="precise-footnote"
                                >*</sup>
                                <span v-if="student.global.is_partial" class="ml-1 text-xs font-normal text-amber-600">parcial</span>
                            </td>
                            <td class="px-3 py-1.5">
                                <Badge v-if="student.global.band" :class="toneClassFor(student.global.band)" class="text-[10px]">
                                    {{ student.global.band.label }}
                                </Badge>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                            <td v-for="domain in context.domains" :key="domain.key" class="px-3 py-1.5">
                                <template v-if="student.domains[domain.key] && student.domains[domain.key]!.value !== null">
                                    <span class="tabular-nums">{{ displayValue(student.domains[domain.key]!) }}</span>
                                    <sup
                                        v-if="hasPreciseFootnote(student.domains[domain.key]!)"
                                        class="cursor-help text-amber-600"
                                        title="Valor apresentado com duas casas: o arredondamento a uma casa sugeriria outra apreciação ou o outro lado do limiar; a apreciação usa o valor exato."
                                    >*</sup>
                                    <Badge
                                        v-if="student.domains[domain.key]!.band"
                                        :class="toneClassFor(student.domains[domain.key]!.band)"
                                        class="ml-1 text-[10px]"
                                    >
                                        {{ student.domains[domain.key]!.band!.label }}
                                    </Badge>
                                    <span v-if="student.domains[domain.key]!.is_partial" class="ml-1 text-xs font-normal text-amber-600">parcial</span>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">—</span>
                            </td>
                        </template>
                    </tr>
                </template>
            </TableShell>
            <p v-else class="text-sm text-muted-foreground">Esta turma não tem alunos abrangidos.</p>

            <p v-if="students.some((student) => student.global.value_precise !== null || Object.values(student.domains).some((cell) => cell?.value_precise))" id="precise-footnote" class="flex items-start gap-1 text-xs text-muted-foreground">
                <span aria-hidden="true">*</span>
                Valor apresentado com duas casas: o arredondamento a uma casa sugeriria outra apreciação ou o outro lado do limiar; a apreciação usa o valor exato.
            </p>
        </section>

        <!-- b) Indicadores da turma -->
        <section v-if="availability.official" aria-labelledby="section-indicators" class="space-y-3">
            <h2 id="section-indicators" class="text-base font-semibold">Indicadores da turma</h2>

            <div class="flex flex-wrap gap-1.5" role="group" aria-label="Selecionar dimensão">
                <button
                    v-for="dimension in dimensions"
                    :key="dimension.key"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm"
                    :class="dimension.key === selectedDimensionKey
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border text-muted-foreground hover:text-foreground'"
                    :aria-pressed="dimension.key === selectedDimensionKey"
                    @click="selectedDimensionKey = dimension.key"
                >
                    {{ dimension.label }}
                </button>
            </div>

            <p class="text-xs text-muted-foreground">
                As percentagens usam os {{ selectedAnalysis.classified }} alunos avaliados como denominador.
            </p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-lg border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Alunos avaliados</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ selectedAnalysis.classified }}</p>
                    <p class="text-xs text-muted-foreground">de {{ selectedAnalysis.universe }} abrangidos</p>
                </div>
                <div class="rounded-lg border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Média</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ pct(selectedAnalysis.mean) }}</p>
                </div>
                <div class="rounded-lg border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Mediana</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">{{ pct(selectedAnalysis.median) }}</p>
                </div>
                <template v-if="selectedAnalysis.threshold.available && context.threshold.value !== null">
                    <div class="rounded-lg border border-border bg-card p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Inferiores a {{ context.threshold.label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ selectedAnalysis.threshold.below!.count }}
                            <span class="text-sm font-normal text-muted-foreground">({{ pct(selectedAnalysis.threshold.below!.percent) }})</span>
                        </p>
                    </div>
                    <div class="rounded-lg border border-border bg-card p-4">
                        <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Iguais ou superiores a {{ context.threshold.label }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ selectedAnalysis.threshold.at_or_above!.count }}
                            <span class="text-sm font-normal text-muted-foreground">({{ pct(selectedAnalysis.threshold.at_or_above!.percent) }})</span>
                        </p>
                    </div>
                </template>
                <div v-else class="rounded-lg border border-border bg-card p-4 sm:col-span-2 lg:col-span-2">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Limiar</p>
                    <p class="mt-1 text-sm text-muted-foreground">{{ context.threshold.explanation }}</p>
                </div>
            </div>

            <div class="rounded-lg border border-border bg-muted/20 p-4 text-sm">
                <p class="font-medium">Sem classificação</p>
                <ul v-if="missingBreakdown.length > 0" class="mt-1 space-y-0.5 text-muted-foreground">
                    <li v-for="row in missingBreakdown" :key="row.key">{{ row.label }}: {{ row.count }}</li>
                </ul>
                <p v-else class="mt-1 text-muted-foreground">Nenhuma classificação em falta.</p>
                <p class="mt-1 text-muted-foreground">Não abrangidos: {{ selectedAnalysis.out_of_scope }}</p>
                <p v-if="selectedAnalysis.partial > 0" class="mt-1 text-amber-700 dark:text-amber-400">
                    {{ selectedAnalysis.partial }} {{ selectedAnalysis.partial === 1 ? 'resultado parcial' : 'resultados parciais' }}
                </p>
            </div>
        </section>

        <!-- c) Distribuição -->
        <section v-if="availability.official" aria-labelledby="section-distribution" class="space-y-6">
            <h2 id="section-distribution" class="text-base font-semibold">Distribuição</h2>

            <div class="space-y-3">
                <LabelledBarChart
                    :title="quantitativeChartTitle"
                    :total="selectedAnalysis.quantitative.total"
                    :categories="quantitativeCategories"
                />
                <DataTable caption="Classe" :rows="quantitativeRows" :total="selectedAnalysis.quantitative.total" />
            </div>

            <div class="space-y-3">
                <template v-if="selectedAnalysis.qualitative.available">
                    <LabelledBarChart
                        :title="qualitativeChartTitle"
                        :total="selectedAnalysis.qualitative.total"
                        :categories="qualitativeCategories"
                    />
                    <DataTable caption="Apreciação" :rows="qualitativeRows" :total="selectedAnalysis.qualitative.total" />
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    A escala configurada para esta turma não define apreciações qualitativas.
                </p>
            </div>
        </section>

        <!-- d) Resultados por domínio -->
        <section v-if="availability.official && context.domains.length > 0" aria-labelledby="section-domains" class="space-y-3">
            <h2 id="section-domains" class="text-base font-semibold">Resultados por domínio</h2>

            <TableShell>
                <template #head>
                    <tr>
                        <th scope="col" class="px-3 py-2 text-left font-medium">Domínio</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Peso no perfil</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Avaliados</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Média</th>
                        <th scope="col" class="px-3 py-2 text-right font-medium">Mediana</th>
                        <template v-if="thresholdColumnsAvailable">
                            <th scope="col" class="px-3 py-2 text-right font-medium">&lt; {{ context.threshold.label }}</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">&ge; {{ context.threshold.label }}</th>
                        </template>
                    </tr>
                </template>
                <template #body>
                    <tr v-for="domain in context.domains" :key="domain.key">
                        <td class="px-3 py-1.5 font-medium">{{ domain.name }}</td>
                        <td class="px-3 py-1.5 text-right tabular-nums">{{ domain.weight_percent ? pct(domain.weight_percent).replace(',0 %', ' %') : '—' }}</td>
                        <template v-if="dimensions.find((dimension) => dimension.key === domain.key)">
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ dimensions.find((dimension) => dimension.key === domain.key)!.analysis.classified }}
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ pct(dimensions.find((dimension) => dimension.key === domain.key)!.analysis.mean) }}
                            </td>
                            <td class="px-3 py-1.5 text-right tabular-nums">
                                {{ pct(dimensions.find((dimension) => dimension.key === domain.key)!.analysis.median) }}
                            </td>
                            <template v-if="thresholdColumnsAvailable">
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ dimensions.find((dimension) => dimension.key === domain.key)!.analysis.threshold.below!.count }}
                                    ({{ pct(dimensions.find((dimension) => dimension.key === domain.key)!.analysis.threshold.below!.percent) }})
                                </td>
                                <td class="px-3 py-1.5 text-right tabular-nums">
                                    {{ dimensions.find((dimension) => dimension.key === domain.key)!.analysis.threshold.at_or_above!.count }}
                                    ({{ pct(dimensions.find((dimension) => dimension.key === domain.key)!.analysis.threshold.at_or_above!.percent) }})
                                </td>
                            </template>
                        </template>
                        <td v-else class="px-3 py-1.5 text-right text-muted-foreground" :colspan="thresholdColumnsAvailable ? 5 : 3">—</td>
                    </tr>
                </template>
            </TableShell>
        </section>

        <!-- e) Notas metodológicas -->
        <section
            v-if="availability.official && (context.notes.length > 0 || context.threshold.value === null)"
            aria-labelledby="section-notes"
            class="space-y-1"
        >
            <h2 id="section-notes" class="text-sm font-semibold text-muted-foreground">Notas metodológicas</h2>
            <ul class="list-disc space-y-0.5 pl-5 text-xs text-muted-foreground">
                <li v-if="context.threshold.value === null">{{ context.threshold.explanation }}</li>
                <li v-for="(note, index) in context.notes" :key="index">{{ note }}</li>
            </ul>
        </section>

        <!-- f) Relatório descritivo -->
        <section v-if="availability.official" aria-labelledby="section-report" class="space-y-4 border-t border-border pt-6">
            <h2 id="section-report" class="text-base font-semibold">Relatório descritivo</h2>

            <article v-for="section in report?.sections ?? []" :key="section.key" class="space-y-2">
                <h3 class="text-sm font-semibold">{{ section.title }}</h3>
                <p v-for="(paragraph, index) in section.paragraphs" :key="index" class="text-sm text-muted-foreground">
                    {{ paragraph }}
                </p>
                <TableShell v-if="section.table">
                    <template #head>
                        <tr>
                            <th v-for="(column, index) in section.table.columns" :key="index" scope="col" class="px-3 py-2 text-left font-medium">
                                {{ column }}
                            </th>
                        </tr>
                    </template>
                    <template #body>
                        <tr v-for="(row, rowIndex) in section.table.rows" :key="rowIndex">
                            <td v-for="(value, colIndex) in row" :key="colIndex" class="px-3 py-1.5">{{ value }}</td>
                        </tr>
                    </template>
                </TableShell>
            </article>

            <p class="text-xs text-amber-700 dark:text-amber-400">
                As observações são texto livre e podem conter informação identificável. Reveja-as antes de
                partilhar o relatório.
            </p>

            <div class="flex flex-wrap gap-3 print:hidden">
                <a :href="links.report" class="text-sm text-muted-foreground hover:underline">
                    Versão para impressão (sem nomes)
                </a>
                <a :href="links.report_with_individual" class="text-sm text-muted-foreground hover:underline">
                    Versão para impressão com resultados individuais
                </a>
            </div>
        </section>

        <!-- Observações do professor: sempre visível, mesmo sem resultados oficiais -->
        <section aria-labelledby="section-notes-teacher" class="space-y-2 rounded-lg border border-border bg-muted/10 p-4">
            <h3 id="section-notes-teacher" class="text-sm font-semibold">Observações do professor</h3>
            <Textarea
                v-model="noteForm.body"
                :disabled="!can_edit"
                :readonly="!can_edit"
                rows="5"
                aria-label="Observações do professor"
                placeholder="Escreva aqui as suas observações sobre este instrumento."
            />
            <InputError :message="noteForm.errors.body" />
            <p class="text-xs text-muted-foreground">
                As observações ficam guardadas à parte e não se perdem quando os indicadores são recalculados.
            </p>
            <p v-if="noteUpdatedLabel" class="text-xs text-muted-foreground">
                Última alteração: {{ noteUpdatedLabel }}
            </p>
            <Button v-if="can_edit" type="button" size="sm" :disabled="noteForm.processing" @click="submitNote">
                Guardar observações
            </Button>
        </section>
    </div>
</template>

<style scoped>
@media print {
    nav[aria-label='Secções do elemento de avaliação'] {
        display: none;
    }
}
</style>
