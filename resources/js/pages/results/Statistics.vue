<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, Minus, TrendingDown, TrendingUp, X } from '@lucide/vue';
import type { ChartConfiguration } from 'chart.js';
import { computed, ref } from 'vue';
import StatChart from '@/components/charts/StatChart.vue';
import Heading from '@/components/Heading.vue';
import {
    chromeColours,
    formatPercent,
    formatPoints,
    formatShare,
    seriesColour,
    TONE_COLOURS,
    TREND_COLOURS,
} from '@/lib/charts';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type { Evolution } from '@/lib/results';

type Band = { scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean } | null;
type Level = { code: string; label: string; sequence: number; is_negative: boolean } | null;

type DomainCell = {
    domain_id: number;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    self_assessment: Level;
    mention: Band;
};

type Student = {
    enrollment_id: number;
    name: string;
    class_number: number | null;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    band: Band;
    domains: DomainCell[];
    self_assessment: Level;
    classification: { status: string; is_published: boolean; final: Level; proposed: Level } | null;
};

type Statistics = {
    periods: { id: number; ulid: string; label: string; sequence: number }[];
    selected_period: { id: number; ulid: string; label: string; sequence: number } | null;
    previous_period: { id: number; ulid: string; label: string; sequence: number } | null;
    domains: { id: number; name: string }[];
    scale: { name: string; kind: string; bands: { label: string; sequence: number; is_negative: boolean }[] } | null;
    summary: {
        students_total: number;
        students_with_result: number;
        students_without_result: number;
        class_average: string | null;
        accumulated_average: string | null;
        partial_coverage_count: number;
        most_common_band: (NonNullable<Band> & { count: number }) | null;
    };
    evolution: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
        percentages: { progressed: string | null; stable: string | null; regressed: string | null; no_comparison: string | null };
    };
    distribution: (NonNullable<Band> & { count: number; percentage: string | null })[];
    domain_statistics: {
        domain_id: number; label: string;
        period_average: string | null; accumulated_average: string | null; evolution_average: string | null;
        students_with_result: number; students_without_result: number; partial_coverage_count: number;
        qualitative_band: Band;
    }[];
    period_series: {
        period_id: number; label: string; sequence: number;
        class_average: string | null; students_with_result: number;
        domains: { domain_id: number; average: string | null; students_with_result: number }[];
    }[];
    students: Student[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    decision: { label: string; classifies_by_level: boolean };
    statistics: Statistics;
}>();

const stats = computed(() => props.statistics);
const bands = computed(() => props.statistics.scale?.bands ?? []);

/** The tone of a band, placed by its structure — never by reading its label. */
function toneOf(band: { sequence: number; is_negative: boolean }): keyof typeof TONE_COLOURS {
    return qualitativeToneFor(band, bands.value);
}

function toneClass(band: Band | Level): string {
    return band === null ? 'bg-muted text-muted-foreground' : qualitativeToneClasses[qualitativeToneFor(band, bands.value)];
}

/** Whether there is anything at all to draw. */
const hasAnyResult = computed(() => stats.value.summary.students_with_result > 0);
const hasComparison = computed(() => stats.value.previous_period !== null);
const hasSeveralPeriods = computed(() => stats.value.period_series.filter((row) => row.class_average !== null).length > 1);

function goToPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/results/estatistica/${ulid}`, {}, { preserveScroll: true });
}

// ------------------------------------------------------------------ gráficos

/** 1 · Distribuição — como a turma se reparte pelas bandas da própria escala. */
const distributionChart = computed<ChartConfiguration>(() => {
    const rows = stats.value.distribution;

    return {
        type: 'bar',
        data: {
            labels: rows.map((row) => row.label),
            datasets: [{
                data: rows.map((row) => row.count),
                backgroundColor: rows.map((row) => TONE_COLOURS[toneOf(row)].fill),
                borderColor: rows.map((row) => TONE_COLOURS[toneOf(row)].border),
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 72,
            }],
        },
        options: {
            scales: {
                x: { grid: { display: false }, ticks: { color: chromeColours().muted } },
                y: {
                    beginAtZero: true,
                    ticks: { precision: 0, color: chromeColours().muted },
                    grid: { color: chromeColours().grid },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        title: (items) => {
                            const row = rows[items[0].dataIndex];

                            return `${row.code} — ${row.label}`;
                        },
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            const students = row.count === 1 ? '1 aluno' : `${row.count} alunos`;

                            return [students, `${formatShare(row.percentage)} dos alunos com menção`];
                        },
                    },
                },
            },
        },
    };
});

/** 2 · Médias por domínio — barras horizontais, na ordem do perfil. */
const domainChart = computed<ChartConfiguration>(() => {
    const rows = stats.value.domain_statistics;

    return {
        type: 'bar',
        data: {
            labels: rows.map((row) => row.label),
            datasets: [{
                data: rows.map((row) => (row.period_average === null ? null : Number(row.period_average))),
                backgroundColor: rows.map((row) => (row.qualitative_band === null
                    ? TONE_COLOURS.neutral.fill
                    : TONE_COLOURS[toneOf(row.qualitative_band)].fill)),
                borderColor: rows.map((row) => (row.qualitative_band === null
                    ? TONE_COLOURS.neutral.border
                    : TONE_COLOURS[toneOf(row.qualitative_band)].border)),
                borderWidth: 1,
                borderRadius: 6,
                maxBarThickness: 28,
            }],
        },
        options: {
            indexAxis: 'y',
            scales: {
                x: {
                    beginAtZero: true, max: 100, suggestedMax: 100,
                    ticks: { color: chromeColours().muted, callback: (value) => `${value}%` },
                    grid: { color: chromeColours().grid },
                },
                y: { grid: { display: false }, ticks: { color: chromeColours().text } },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        title: (items) => rows[items[0].dataIndex].label,
                        label: (item) => {
                            const row = rows[item.dataIndex];
                            const lines = [`Média do período: ${formatPercent(row.period_average)}`];

                            if (row.accumulated_average !== null) {
                                lines.push(`Média acumulada: ${formatPercent(row.accumulated_average)}`);
                            }

                            if (row.qualitative_band !== null) {
                                lines.push(`Menção: ${row.qualitative_band.label}`);
                            }

                            lines.push(row.students_with_result === 1
                                ? '1 aluno com resultado'
                                : `${row.students_with_result} alunos com resultado`);

                            if (row.partial_coverage_count > 0) {
                                lines.push(row.partial_coverage_count === 1
                                    ? '1 com informação parcial'
                                    : `${row.partial_coverage_count} com informação parcial`);
                            }

                            if (row.evolution_average !== null) {
                                lines.push(`Evolução: ${formatPoints(row.evolution_average)} p.p.`);
                            }

                            return lines;
                        },
                    },
                },
            },
        },
    };
});

/** 3 · Evolução dos domínios — uma linha por domínio, sempre em standalone. */
const domainSeriesChart = computed<ChartConfiguration>(() => {
    const series = stats.value.period_series;

    return {
        type: 'line',
        data: {
            labels: series.map((row) => row.label),
            datasets: stats.value.domains.map((domain, index) => ({
                label: domain.name,
                data: series.map((row) => {
                    const cell = row.domains.find((candidate) => candidate.domain_id === domain.id);

                    return cell?.average === null || cell?.average === undefined ? null : Number(cell.average);
                }),
                borderColor: seriesColour(index),
                backgroundColor: seriesColour(index),
                borderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.35,
                spanGaps: false,
            })),
        },
        options: {
            interaction: { mode: 'nearest', intersect: false },
            scales: {
                x: { grid: { display: false }, ticks: { color: chromeColours().muted } },
                y: {
                    beginAtZero: true, max: 100,
                    ticks: { color: chromeColours().muted, callback: (value) => `${value}%` },
                    grid: { color: chromeColours().grid },
                },
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { color: chromeColours().text, boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 16 },
                },
                tooltip: {
                    callbacks: {
                        title: (items) => `${items[0].dataset.label} · ${items[0].label}`,
                        label: (item) => {
                            const row = series[item.dataIndex];
                            const domain = stats.value.domains[item.datasetIndex];
                            const cell = row.domains.find((candidate) => candidate.domain_id === domain.id);
                            const previous = item.dataIndex === 0 ? null : series[item.dataIndex - 1]
                                .domains.find((candidate) => candidate.domain_id === domain.id)?.average ?? null;

                            const lines = [`Média: ${formatPercent(cell?.average ?? null)}`];

                            // The difference between two points that both exist.
                            // Never against a period with nothing in it.
                            if (previous !== null && cell?.average != null) {
                                lines.push(`Variação: ${formatPoints(Number(cell.average) - Number(previous))} p.p.`);
                            }

                            lines.push((cell?.students_with_result ?? 0) === 1
                                ? '1 aluno com resultado'
                                : `${cell?.students_with_result ?? 0} alunos com resultado`);

                            return lines;
                        },
                    },
                },
            },
        },
    };
});

/** 4 · Evolução global — a Média Ponderada da turma ao longo do ano. */
const classTrendChart = computed<ChartConfiguration>(() => {
    const series = stats.value.period_series;

    return {
        type: 'line',
        data: {
            labels: series.map((row) => row.label),
            datasets: [{
                label: 'Média Ponderada da turma',
                data: series.map((row) => (row.class_average === null ? null : Number(row.class_average))),
                borderColor: 'rgb(37, 99, 235)',
                backgroundColor: 'rgba(37, 99, 235, 0.12)',
                borderWidth: 2.5,
                pointRadius: 5,
                pointHoverRadius: 7,
                tension: 0.35,
                fill: true,
                spanGaps: false,
            }],
        },
        options: {
            scales: {
                x: { grid: { display: false }, ticks: { color: chromeColours().muted } },
                y: {
                    beginAtZero: true, max: 100,
                    ticks: { color: chromeColours().muted, callback: (value) => `${value}%` },
                    grid: { color: chromeColours().grid },
                },
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        title: (items) => items[0].label,
                        label: (item) => {
                            const row = series[item.dataIndex];
                            const previous = item.dataIndex === 0 ? null : series[item.dataIndex - 1].class_average;
                            const lines = [`Média Ponderada: ${formatPercent(row.class_average)}`];

                            if (previous !== null && row.class_average !== null) {
                                lines.push(`Variação: ${formatPoints(Number(row.class_average) - Number(previous))} p.p.`);
                            }

                            lines.push(row.students_with_result === 1
                                ? '1 aluno com resultado'
                                : `${row.students_with_result} alunos com resultado`);

                            return lines;
                        },
                    },
                },
            },
        },
    };
});

/** 5 · Evolução da turma — o único donut da página (§18). */
const evolutionChart = computed<ChartConfiguration>(() => {
    const evolution = stats.value.evolution;
    const slices = [
        { key: 'progressed', label: 'Progrediram', count: evolution.progressed, colour: TREND_COLOURS.up },
        { key: 'stable', label: 'Mantiveram-se', count: evolution.stable, colour: TREND_COLOURS.flat },
        { key: 'regressed', label: 'Regrediram', count: evolution.regressed, colour: TREND_COLOURS.down },
        { key: 'no_comparison', label: 'Sem comparação', count: evolution.no_comparison, colour: TREND_COLOURS.none },
    ];

    return {
        type: 'doughnut',
        data: {
            labels: slices.map((slice) => slice.label),
            datasets: [{
                data: slices.map((slice) => slice.count),
                backgroundColor: slices.map((slice) => slice.colour.fill),
                borderColor: slices.map((slice) => slice.colour.border),
                borderWidth: 1,
            }],
        },
        options: {
            cutout: '62%',
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: { color: chromeColours().text, boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 14 },
                },
                tooltip: {
                    callbacks: {
                        title: (items) => items[0].label,
                        label: (item) => {
                            const slice = slices[item.dataIndex];
                            const total = stats.value.summary.students_total;
                            const students = slice.count === 1 ? '1 aluno' : `${slice.count} alunos`;
                            const share = total === 0 ? null : (slice.count / total) * 100;

                            return [students, `${formatShare(share)} da turma`];
                        },
                    },
                },
            },
        },
    };
});

// ------------------------------------------------------- equivalentes textuais

const distributionRows = computed(() => stats.value.distribution.map((row) => [
    `${row.code} — ${row.label}`, row.count, formatShare(row.percentage),
]));

const domainRows = computed(() => stats.value.domain_statistics.map((row) => [
    row.label,
    formatPercent(row.period_average),
    formatPercent(row.accumulated_average),
    row.qualitative_band?.label ?? '—',
    row.students_with_result,
    row.evolution_average === null ? '—' : `${formatPoints(row.evolution_average)} p.p.`,
]));

const seriesRows = computed(() => stats.value.period_series.map((row) => [
    row.label,
    formatPercent(row.class_average),
    ...stats.value.domains.map((domain) => formatPercent(
        row.domains.find((candidate) => candidate.domain_id === domain.id)?.average ?? null,
    )),
]));

const evolutionRows = computed(() => {
    const evolution = stats.value.evolution;

    return [
        ['Progrediram', evolution.progressed, formatShare(evolution.percentages.progressed)],
        ['Mantiveram-se', evolution.stable, formatShare(evolution.percentages.stable)],
        ['Regrediram', evolution.regressed, formatShare(evolution.percentages.regressed)],
        ['Sem comparação', evolution.no_comparison, formatShare(evolution.percentages.no_comparison)],
    ];
});

// ------------------------------------------------------------- leitura discreta

/**
 * The domains with the highest and lowest averages, and only when there are two
 * different ones to name. Never «ponto forte» / «ponto fraco» — that is a
 * pedagogical judgement, and this is a number (§23).
 */
const extremes = computed(() => {
    const comparable = stats.value.domain_statistics.filter((row) => row.period_average !== null);

    if (comparable.length < 2) {
        return null;
    }

    const sorted = [...comparable].sort((a, b) => Number(b.period_average) - Number(a.period_average));
    const highest = sorted[0];
    const lowest = sorted[sorted.length - 1];

    // All equal: there is no highest and no lowest, only one number.
    return highest.period_average === lowest.period_average ? null : { highest, lowest };
});

// ------------------------------------------------------------------ heatmap

/** The cell's own value — the standalone figure of that domain, as calculated. */
function heatCell(student: Student, domainId: number): DomainCell | undefined {
    return student.domains.find((domain) => domain.domain_id === domainId);
}

const selected = ref<Student | null>(null);

function openStudent(student: Student): void {
    selected.value = student;
}

/** The chosen student's own line through the year, period by period. */
const studentSeries = computed<ChartConfiguration | null>(() => {
    const student = selected.value;

    if (student === null) {
        return null;
    }

    // Read from the series the read model already built for the class, and the
    // student's own domain cells for the selected period.
    const labels = stats.value.domains.map((domain) => domain.name);
    const values = labels.map((_, index) => {
        const cell = heatCell(student, stats.value.domains[index].id);

        return cell?.weighted_average === null || cell?.weighted_average === undefined
            ? null
            : Number(cell.weighted_average);
    });
    const accumulated = labels.map((_, index) => {
        const cell = heatCell(student, stats.value.domains[index].id);

        return cell?.accumulated_average === null || cell?.accumulated_average === undefined
            ? null
            : Number(cell.accumulated_average);
    });

    return {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Média do período',
                    data: values,
                    backgroundColor: 'rgba(37, 99, 235, 0.75)',
                    borderColor: 'rgb(37, 99, 235)',
                    borderWidth: 1,
                    borderRadius: 5,
                },
                {
                    label: 'Média acumulada',
                    data: accumulated,
                    backgroundColor: 'rgba(148, 163, 184, 0.5)',
                    borderColor: 'rgb(100, 116, 139)',
                    borderWidth: 1,
                    borderRadius: 5,
                },
            ],
        },
        options: {
            scales: {
                x: { grid: { display: false }, ticks: { color: chromeColours().muted } },
                y: {
                    beginAtZero: true, max: 100,
                    ticks: { color: chromeColours().muted, callback: (value) => `${value}%` },
                    grid: { color: chromeColours().grid },
                },
            },
            plugins: {
                legend: {
                    display: true, position: 'bottom',
                    labels: { color: chromeColours().text, boxWidth: 10, boxHeight: 10, usePointStyle: true, padding: 14 },
                },
                tooltip: {
                    callbacks: {
                        label: (item) => `${item.dataset.label}: ${formatPercent(item.parsed.y)}`,
                    },
                },
            },
        },
    };
});

const studentRows = computed(() => {
    const student = selected.value;

    if (student === null) {
        return [];
    }

    return stats.value.domains.map((domain) => {
        const cell = heatCell(student, domain.id);

        return [
            domain.name,
            formatPercent(cell?.weighted_average ?? null),
            formatPercent(cell?.accumulated_average ?? null),
            cell?.mention?.label ?? '—',
            cell?.self_assessment === null || cell?.self_assessment === undefined ? '—' : cell.self_assessment.code,
        ];
    });
});
</script>

<template>
    <Head :title="`Estatística — ${schoolClass.label}`" />

    <div class="space-y-6 p-4">
        <!-- ================================================== cabeçalho -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :title="`Estatística — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex flex-wrap gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <Link :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`" class="text-primary hover:underline">
                        Quadro Síntese →
                    </Link>
                </div>
            </div>

            <!-- The real periods of the year, then the two whole-year views.
                 Neither their names nor their number is known here (§4). -->
            <div v-if="stats.periods.length" class="flex flex-wrap gap-1">
                <Link
                    v-for="period in stats.periods"
                    :key="period.ulid"
                    :href="`/classes/${schoolClass.ulid}/results/${period.ulid}`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    {{ period.label }}
                </Link>
                <Link
                    :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    Quadro Síntese
                </Link>
                <span class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm text-primary-foreground">Estatística</span>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            Esta turma não tem perfil de avaliação associado, por isso não há resultados a analisar.
        </p>

        <!-- ============================================== estado vazio -->
        <div v-else-if="!hasAnyResult" class="rounded-lg border border-dashed border-border p-12 text-center">
            <p class="text-sm font-medium">Ainda não existem resultados suficientes para apresentar estatísticas.</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Assim que houver avaliações registadas neste período, esta página passa a mostrar a distribuição,
                a evolução e as médias por domínio.
            </p>
            <Link :href="`/classes/${schoolClass.ulid}/results`" class="mt-4 inline-block text-sm text-primary hover:underline">
                Ir para Resultados →
            </Link>
        </div>

        <template v-else>
            <!-- Which period these numbers are about, said once and plainly. -->
            <div v-if="stats.periods.length > 1" class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-muted-foreground">Período em análise:</span>
                <button
                    v-for="period in stats.periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition-colors"
                    :class="period.id === stats.selected_period?.id
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border hover:bg-muted/40'"
                    :aria-pressed="period.id === stats.selected_period?.id"
                    @click="goToPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>

            <!-- ============================================ cards de resumo -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Média Ponderada da turma</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums">{{ formatPercent(stats.summary.class_average) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="stats.selected_period">{{ stats.selected_period.label }}, só com este período</template>
                    </p>
                    <!-- Movement only where there is something to compare (§6). -->
                    <p
                        v-if="hasComparison && stats.evolution.average_change !== null"
                        class="mt-2 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium"
                        :class="Number(stats.evolution.average_change) > 0
                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300'
                            : Number(stats.evolution.average_change) < 0
                                ? 'bg-rose-50 text-rose-700 dark:bg-rose-950/40 dark:text-rose-300'
                                : 'bg-muted text-muted-foreground'"
                    >
                        <TrendingUp v-if="Number(stats.evolution.average_change) > 0" class="size-3" />
                        <TrendingDown v-else-if="Number(stats.evolution.average_change) < 0" class="size-3" />
                        <Minus v-else class="size-3" />
                        {{ formatPoints(stats.evolution.average_change) }} p.p. face a {{ stats.previous_period?.label }}
                    </p>
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Média acumulada</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums">{{ formatPercent(stats.summary.accumulated_average) }}</p>
                    <p class="mt-1 text-xs text-muted-foreground">Tudo o que conta até este período</p>
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Alunos com resultado</p>
                    <p class="mt-1 text-3xl font-semibold tabular-nums">
                        {{ stats.summary.students_with_result }}<span class="text-lg text-muted-foreground">/{{ stats.summary.students_total }}</span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        <template v-if="stats.summary.students_without_result > 0">
                            {{ stats.summary.students_without_result }} sem resultado neste período
                        </template>
                        <template v-else>Toda a turma tem resultado</template>
                    </p>
                </div>

                <div class="rounded-xl border border-border bg-card p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                        {{ stats.summary.most_common_band ? 'Menção mais frequente' : 'Informação parcial' }}
                    </p>
                    <template v-if="stats.summary.most_common_band">
                        <p class="mt-1 flex items-center gap-2">
                            <span class="text-3xl font-semibold tabular-nums">{{ stats.summary.most_common_band.code }}</span>
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="toneClass(stats.summary.most_common_band)">
                                {{ stats.summary.most_common_band.label }}
                            </span>
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ stats.summary.most_common_band.count }} de {{ stats.summary.students_total }} alunos
                        </p>
                    </template>
                    <template v-else>
                        <p class="mt-1 text-3xl font-semibold tabular-nums">{{ stats.summary.partial_coverage_count }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">resultados com informação parcial</p>
                    </template>
                </div>
            </div>

            <!-- Cobertura, nunca escondida (§24). -->
            <p
                v-if="stats.summary.partial_coverage_count > 0 && stats.summary.most_common_band"
                class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <span>
                    <template v-if="stats.summary.partial_coverage_count === 1">
                        1 de {{ stats.summary.students_total }} alunos tem o resultado deste período calculado com informação parcial.
                    </template>
                    <template v-else>
                        {{ stats.summary.partial_coverage_count }} de {{ stats.summary.students_total }} alunos têm o resultado
                        deste período calculado com informação parcial.
                    </template>
                    O detalhe de cada caso está em Resultados, junto ao aviso do próprio aluno.
                </span>
            </p>

            <!-- ==================================== distribuição + evolução -->
            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-xl border border-border bg-card p-5 lg:col-span-2">
                    <h2 class="text-sm font-semibold">Distribuição pela escala</h2>
                    <p class="mb-4 text-xs text-muted-foreground">
                        Menção de cada aluno, colocada pela Média Ponderada Acumulada na escala
                        <template v-if="schoolClass.scale_name">«{{ schoolClass.scale_name }}»</template>.
                    </p>

                    <StatChart
                        v-if="stats.distribution.length"
                        :config="distributionChart"
                        summary="Número de alunos em cada banda da escala de classificação."
                        :headers="['Banda', 'Alunos', 'Percentagem']"
                        :rows="distributionRows"
                        height-class="h-72"
                    />
                    <p v-else class="py-10 text-center text-sm text-muted-foreground">
                        A escala desta turma não tem bandas configuradas, por isso não há menções para distribuir.
                    </p>
                </section>

                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-sm font-semibold">Evolução da turma</h2>
                    <p class="mb-4 text-xs text-muted-foreground">
                        <template v-if="hasComparison">
                            Este período comparado com {{ stats.previous_period?.label }}.
                        </template>
                        <template v-else>Ainda não há período anterior para comparar.</template>
                    </p>

                    <StatChart
                        v-if="hasComparison"
                        :config="evolutionChart"
                        summary="Quantos alunos progrediram, se mantiveram, regrediram ou não têm comparação possível."
                        :headers="['Movimento', 'Alunos', 'Percentagem']"
                        :rows="evolutionRows"
                        height-class="h-72"
                    />
                    <p v-else class="py-10 text-center text-sm text-muted-foreground">
                        A evolução fica disponível assim que existir um segundo período com resultados.
                    </p>
                </section>
            </div>

            <!-- ======================================== médias por domínio -->
            <section class="rounded-xl border border-border bg-card p-5">
                <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold">Médias por domínio</h2>
                        <p class="text-xs text-muted-foreground">Na ordem do perfil de avaliação, e não por resultado.</p>
                    </div>
                    <!-- Uma leitura, não um juízo (§23). -->
                    <div v-if="extremes" class="flex gap-4 text-xs">
                        <p>
                            <span class="text-muted-foreground">Média mais elevada:</span><br />
                            <span class="font-medium">{{ extremes.highest.label }} · {{ formatPercent(extremes.highest.period_average) }}</span>
                        </p>
                        <p>
                            <span class="text-muted-foreground">Média mais baixa:</span><br />
                            <span class="font-medium">{{ extremes.lowest.label }} · {{ formatPercent(extremes.lowest.period_average) }}</span>
                        </p>
                    </div>
                </div>

                <StatChart
                    :config="domainChart"
                    summary="Média da turma em cada domínio, neste período."
                    :headers="['Domínio', 'Média do período', 'Média acumulada', 'Menção', 'Alunos com resultado', 'Evolução']"
                    :rows="domainRows"
                    :height-class="stats.domains.length > 5 ? 'h-96' : 'h-72'"
                />
            </section>

            <!-- ==================================== evolução ao longo do ano -->
            <div v-if="hasSeveralPeriods" class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-sm font-semibold">Evolução global da turma</h2>
                    <p class="mb-4 text-xs text-muted-foreground">Média Ponderada de cada período, isoladamente.</p>
                    <StatChart
                        :config="classTrendChart"
                        summary="Média Ponderada da turma em cada período do ano letivo."
                        :headers="['Período', 'Média Ponderada']"
                        :rows="stats.period_series.map((row) => [row.label, formatPercent(row.class_average)])"
                        height-class="h-72"
                    />
                </section>

                <section class="rounded-xl border border-border bg-card p-5">
                    <h2 class="text-sm font-semibold">Evolução dos domínios</h2>
                    <p class="mb-4 text-xs text-muted-foreground">Cada linha é um domínio, período a período.</p>
                    <StatChart
                        :config="domainSeriesChart"
                        summary="Média da turma em cada domínio, ao longo dos períodos do ano letivo."
                        :headers="['Período', 'Turma', ...stats.domains.map((domain) => domain.name)]"
                        :rows="seriesRows"
                        height-class="h-72"
                    />
                </section>
            </div>

            <!-- ================================================== heatmap -->
            <section class="rounded-xl border border-border bg-card p-5">
                <h2 class="text-sm font-semibold">Alunos e domínios</h2>
                <p class="mb-4 text-xs text-muted-foreground">
                    Média de cada aluno em cada domínio, neste período. O valor está sempre escrito — a cor só o reforça.
                    Escolha um aluno para ver a leitura individual.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-max min-w-full text-sm">
                        <thead>
                            <tr class="border-b border-border text-left">
                                <th scope="col" class="sticky left-0 z-10 bg-card px-3 py-2 font-medium">Aluno</th>
                                <th v-for="domain in stats.domains" :key="domain.id" scope="col" class="px-3 py-2 text-center font-medium">
                                    {{ domain.name }}
                                </th>
                                <th scope="col" class="px-3 py-2 text-center font-medium">Menção</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="student in stats.students"
                                :key="student.enrollment_id"
                                class="border-b border-border/60 last:border-0 hover:bg-muted/30"
                            >
                                <th scope="row" class="sticky left-0 z-10 bg-card px-3 py-1.5 text-left font-normal">
                                    <button
                                        type="button"
                                        class="rounded text-left hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        @click="openStudent(student)"
                                    >
                                        <span class="tabular-nums text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                        {{ student.name }}
                                    </button>
                                </th>
                                <td v-for="domain in stats.domains" :key="domain.id" class="px-3 py-1.5 text-center">
                                    <span
                                        class="inline-block min-w-14 rounded px-1.5 py-0.5 tabular-nums"
                                        :class="heatCell(student, domain.id)?.mention
                                            ? toneClass(heatCell(student, domain.id)!.mention)
                                            : 'text-muted-foreground'"
                                        :title="heatCell(student, domain.id)?.mention?.label ?? undefined"
                                    >
                                        {{ formatPercent(heatCell(student, domain.id)?.weighted_average ?? null) }}
                                    </span>
                                </td>
                                <td class="px-3 py-1.5 text-center">
                                    <span v-if="student.band" class="rounded px-2 py-0.5 text-xs font-medium" :class="toneClass(student.band)">
                                        {{ student.band.code }} · {{ student.band.label }}
                                    </span>
                                    <span v-else class="text-muted-foreground">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>

        <!-- ======================================== leitura individual -->
        <div
            v-if="selected"
            class="fixed inset-0 z-50 flex justify-end bg-black/30"
            role="dialog"
            aria-modal="true"
            :aria-label="`Leitura individual de ${selected.name}`"
            @click.self="selected = null"
        >
            <div class="h-full w-full max-w-xl overflow-y-auto border-l border-border bg-background p-5 shadow-xl">
                <div class="mb-4 flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold">{{ selected.name }}</h2>
                        <p class="text-sm text-muted-foreground">
                            {{ schoolClass.label }} · {{ stats.selected_period?.label }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md border border-border p-1.5 hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        aria-label="Fechar"
                        @click="selected = null"
                    >
                        <X class="size-4" />
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">Média do período</p>
                        <p class="text-xl font-semibold tabular-nums">{{ formatPercent(selected.weighted_average) }}</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">Acumulada</p>
                        <p class="text-xl font-semibold tabular-nums">{{ formatPercent(selected.accumulated_average) }}</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">Evolução</p>
                        <p class="text-xl font-semibold tabular-nums">
                            {{ selected.evolution ? `${formatPoints(selected.evolution.points)}` : '—' }}
                        </p>
                        <p class="text-xs text-muted-foreground">p.p.</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">Menção</p>
                        <p v-if="selected.band" class="mt-1 inline-block rounded px-2 py-0.5 text-sm font-medium" :class="toneClass(selected.band)">
                            {{ selected.band.label }}
                        </p>
                        <p v-else class="text-xl font-semibold">—</p>
                    </div>
                </div>

                <!-- O que o professor decidiu, ao lado do que o aluno disse.
                     Nunca no mesmo número: são duas afirmações diferentes (§29). -->
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">{{ decision.label }}</p>
                        <p v-if="selected.classification?.final" class="mt-1 text-sm font-medium">
                            {{ selected.classification.final.code }} — {{ selected.classification.final.label }}
                        </p>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Ainda sem decisão registada</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <p class="text-xs text-muted-foreground">Autoavaliação do aluno</p>
                        <p v-if="selected.self_assessment" class="mt-1 text-sm font-medium">
                            {{ selected.self_assessment.code }} — {{ selected.self_assessment.label }}
                        </p>
                        <p v-else class="mt-1 text-sm text-muted-foreground">Sem autoavaliação submetida</p>
                    </div>
                </div>

                <!-- The same distinction the ⚠ on Resultados makes: a value
                     built on part of the evidence is «parcial»; no value at all
                     is the absence of evidence, which is a different sentence. -->
                <p v-if="selected.coverage_warning" class="mt-3 flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50/60 px-3 py-2 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                    <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                    <template v-if="selected.weighted_average !== null">
                        O resultado deste período foi calculado com informação parcial.
                        O detalhe está em Resultados, no aviso do próprio aluno.
                    </template>
                    <template v-else>
                        Ainda não há elementos avaliados que produzam um resultado neste período.
                    </template>
                </p>

                <section class="mt-5">
                    <h3 class="mb-3 text-sm font-semibold">Por domínio</h3>
                    <StatChart
                        v-if="studentSeries"
                        :config="studentSeries"
                        :summary="`Média de ${selected.name} em cada domínio, no período e acumulada.`"
                        :headers="['Domínio', 'Média do período', 'Acumulada', 'Menção', 'Autoavaliação']"
                        :rows="studentRows"
                        height-class="h-64"
                    />
                </section>

                <p class="mt-4 text-xs text-muted-foreground">
                    Estes números são os mesmos de Resultados e do Quadro Síntese — esta página apenas os agrega.
                </p>
            </div>
        </div>
    </div>
</template>
