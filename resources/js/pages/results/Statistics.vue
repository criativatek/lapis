<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, Minus, TrendingDown, TrendingUp, X } from '@lucide/vue';
import type { ChartConfiguration } from 'chart.js';
import { computed, ref } from 'vue';
import StatChart from '@/components/charts/StatChart.vue';
import Heading from '@/components/Heading.vue';
import InfographicMetric from '@/components/infographic/InfographicMetric.vue';
import RibbonBar from '@/components/infographic/RibbonBar.vue';
import type { RibbonRow } from '@/components/infographic/RibbonBar.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import {
    areaGradient,
    categoryAxis,
    chromeColours,
    countAxis,
    domainColours,
    formatPoints,
    formatShare,
    muted,
    pct,
    percentAxis,
    prefersReducedMotion,
    students as studentsWord,
    TONE_COLOURS,
    TREND_COLOURS,
} from '@/lib/chartTheme';
import type { TooltipContent } from '@/lib/chartTheme';
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

type DomainStatistic = {
    domain_id: number; label: string;
    period_average: string | null; accumulated_average: string | null; evolution_average: string | null;
    students_with_result: number; students_without_result: number; partial_coverage_count: number;
    qualitative_band: Band;
};

type Statistics = {
    periods: { id: number; ulid: string; label: string; sequence: number }[];
    selected_period: { id: number; ulid: string; label: string; sequence: number } | null;
    previous_period: { id: number; ulid: string; label: string; sequence: number } | null;
    domains: { id: number; name: string }[];
    scale: { name: string; kind: string; bands: { label: string; sequence: number; is_negative: boolean }[] } | null;
    summary: {
        students_total: number; students_with_result: number; students_without_result: number;
        class_average: string | null; accumulated_average: string | null;
        partial_coverage_count: number;
        most_common_band: (NonNullable<Band> & { count: number }) | null;
    };
    evolution: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
        percentages: { progressed: string | null; stable: string | null; regressed: string | null; no_comparison: string | null };
    };
    distribution: (NonNullable<Band> & { count: number; percentage: string | null })[];
    domain_statistics: DomainStatistic[];
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

/** The colour of each domain, by its OWN id — the same ink everywhere (§8). */
const inks = computed(() => domainColours(stats.value.domains.map((domain) => domain.id)));

function toneOf(band: { sequence: number; is_negative: boolean }): keyof typeof TONE_COLOURS {
    return qualitativeToneFor(band, bands.value);
}

function toneClass(band: Band | Level): string {
    return band === null ? 'bg-muted text-muted-foreground' : qualitativeToneClasses[qualitativeToneFor(band, bands.value)];
}

const hasAnyResult = computed(() => stats.value.summary.students_with_result > 0);
const hasComparison = computed(() => stats.value.previous_period !== null);
const hasSeveralPeriods = computed(() => stats.value.period_series.filter((row) => row.class_average !== null).length > 1);

function goToPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/results/estatistica/${ulid}`, {}, { preserveScroll: true });
}

// ------------------------------------------------------------------- seleção

/**
 * ONE selection at a time, and always visible.
 *
 * A filter the reader cannot see is a filter they forget, and then they wonder
 * why half the class disappeared. Both of these are echoed as a chip with a way
 * to clear them (§5).
 */
const selectedDomainId = ref<number | null>(null);
const selectedLevelId = ref<number | null>(null);

function toggleDomain(domainId: number): void {
    selectedLevelId.value = null;
    selectedDomainId.value = selectedDomainId.value === domainId ? null : domainId;
}

function toggleLevel(levelId: number): void {
    selectedDomainId.value = null;
    selectedLevelId.value = selectedLevelId.value === levelId ? null : levelId;
}

function clearSelection(): void {
    selectedDomainId.value = null;
    selectedLevelId.value = null;
}

const selectedDomain = computed(() => stats.value.domains.find((domain) => domain.id === selectedDomainId.value) ?? null);
const selectedLevel = computed(() => stats.value.distribution.find((band) => band.scale_level_id === selectedLevelId.value) ?? null);

/** Whether a domain should be drawn at full strength. */
function isLit(domainId: number): boolean {
    return selectedDomainId.value === null || selectedDomainId.value === domainId;
}

/** The students of the chosen band — highlighted, never hidden. */
function matchesLevel(student: Student): boolean {
    return selectedLevelId.value === null || student.band?.scale_level_id === selectedLevelId.value;
}

const highlightedStudents = computed(() => stats.value.students.filter((student) => matchesLevel(student)).length);

// ------------------------------------------------------------------ gráficos

/** 1 · Distribuição pela escala. */
const distributionChart = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const rows = stats.value.distribution;

    return {
        type: 'bar',
        data: {
            labels: rows.map((row) => row.label),
            datasets: [{
                data: rows.map((row) => row.count),
                backgroundColor: rows.map((row) => (selectedLevelId.value === null || selectedLevelId.value === row.scale_level_id
                    ? TONE_COLOURS[toneOf(row)].fill
                    : TONE_COLOURS[toneOf(row)].soft)),
                borderColor: rows.map((row) => (selectedLevelId.value === null || selectedLevelId.value === row.scale_level_id
                    ? TONE_COLOURS[toneOf(row)].border
                    : 'transparent')),
                borderWidth: 1.5,
                borderRadius: 8,
                maxBarThickness: 64,
                hoverBackgroundColor: rows.map((row) => TONE_COLOURS[toneOf(row)].border),
            }],
        },
        options: {
            scales: { x: categoryAxis(chrome, true), y: countAxis(chrome) },
        },
    } as ChartConfiguration;
});

const distributionTooltip = (index: number): TooltipContent | null => {
    const row = stats.value.distribution[index];

    if (row === undefined) {
        return null;
    }

    return {
        title: `${row.code} — ${row.label}`,
        rows: [
            { label: 'Alunos', value: String(row.count), strong: true },
            { label: 'Da turma com menção', value: formatShare(row.percentage) },
        ],
        footer: row.count === 0 ? 'Nenhum aluno nesta menção.' : 'Clique para realçar estes alunos.',
    };
};

/**
 * 2 · Médias por domínio, drawn as ribbons rather than as bars.
 *
 * The length IS the percentage; the pointed tip is clipped out of that width
 * rather than added to it, so the furthest point of each ribbon sits exactly
 * where a plain bar would have ended.
 */
const domainRibbons = computed<RibbonRow[]>(() => stats.value.domain_statistics.map((row) => {
    const tone = row.qualitative_band === null ? TONE_COLOURS.neutral : TONE_COLOURS[toneOf(row.qualitative_band)];

    return {
        id: row.domain_id,
        label: row.label,
        value: row.period_average === null ? null : Number(row.period_average),
        display: pct(row.period_average),
        colour: tone.border,
        tooltip: domainTooltipFor(row) ?? { title: row.label, rows: [] },
    };
}));

function domainTooltipFor(row: DomainStatistic): TooltipContent | null {
    const lines: TooltipContent['rows'] = [
        { label: 'Média da turma', value: pct(row.period_average), strong: true, swatch: inks.value[row.domain_id] },
    ];

    if (row.accumulated_average !== null) {
        lines.push({ label: 'Média acumulada', value: pct(row.accumulated_average) });
    }

    if (row.evolution_average !== null) {
        const direction = Number(row.evolution_average);

        lines.push({
            label: 'Evolução',
            value: `${direction > 0 ? '↑' : direction < 0 ? '↓' : '→'} ${formatPoints(row.evolution_average)} p.p.`,
            trend: direction > 0 ? 'up' : direction < 0 ? 'down' : 'flat',
        });
    }

    if (row.qualitative_band !== null) {
        lines.push({ label: 'Menção', value: row.qualitative_band.label });
    }

    lines.push({
        label: 'Resultados válidos',
        value: `${row.students_with_result} de ${stats.value.summary.students_total}`,
    });

    if (row.partial_coverage_count > 0) {
        lines.push({ label: 'Informação parcial', value: String(row.partial_coverage_count) });
    }

    return { title: row.label.toUpperCase(), rows: lines, footer: 'Clique para seguir este domínio na página.' };
}

/** 3 · Evolução dos domínios ao longo do ano — sempre standalone. */
const domainSeriesChart = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const series = stats.value.period_series;

    return {
        type: 'line',
        data: {
            labels: series.map((row) => row.label),
            datasets: stats.value.domains.map((domain) => {
                const lit = isLit(domain.id);
                const ink = inks.value[domain.id];

                return {
                    label: domain.name,
                    data: series.map((row) => {
                        const cell = row.domains.find((candidate) => candidate.domain_id === domain.id);

                        return cell?.average == null ? null : Number(cell.average);
                    }),
                    borderColor: lit ? ink : muted(ink, 0.18),
                    backgroundColor: lit ? ink : muted(ink, 0.18),
                    borderWidth: lit && selectedDomainId.value !== null ? 3 : 2,
                    pointRadius: lit ? 4 : 2,
                    pointHoverRadius: 6,
                    pointBackgroundColor: chrome.surface,
                    pointBorderColor: lit ? ink : muted(ink, 0.18),
                    pointBorderWidth: 2,
                    tension: 0.36,
                    spanGaps: false,
                    order: lit ? 0 : 1,
                };
            }),
        },
        options: {
            interaction: { mode: 'nearest', intersect: false },
            scales: { x: categoryAxis(chrome), y: percentAxis(chrome) },
        },
    } as ChartConfiguration;
});

const domainSeriesTooltip = (index: number, datasetIndex: number): TooltipContent | null => {
    const row = stats.value.period_series[index];
    const domain = stats.value.domains[datasetIndex];

    if (row === undefined || domain === undefined) {
        return null;
    }

    const cell = row.domains.find((candidate) => candidate.domain_id === domain.id);
    const previous = index === 0
        ? null
        : stats.value.period_series[index - 1].domains.find((candidate) => candidate.domain_id === domain.id)?.average ?? null;

    const lines: TooltipContent['rows'] = [
        { label: 'Média', value: pct(cell?.average ?? null), strong: true, swatch: inks.value[domain.id] },
    ];

    if (previous !== null && cell?.average != null) {
        const change = Number(cell.average) - Number(previous);

        lines.push({
            label: 'Variação',
            value: `${change > 0 ? '↑' : change < 0 ? '↓' : '→'} ${formatPoints(change)} p.p.`,
            trend: change > 0 ? 'up' : change < 0 ? 'down' : 'flat',
        });
    }

    lines.push({ label: 'Com resultado', value: studentsWord(cell?.students_with_result ?? 0) });

    return { title: domain.name.toUpperCase(), subtitle: row.label, rows: lines };
};

/** 4 · Evolução global da turma. */
const classTrendChart = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();
    const series = stats.value.period_series;

    return {
        type: 'line',
        data: {
            labels: series.map((row) => row.label),
            datasets: [{
                data: series.map((row) => (row.class_average === null ? null : Number(row.class_average))),
                borderColor: '#4f46e5',
                // A wash rather than a flat fill: it gives the line something to
                // sit on without ever reading as a second quantity.
                backgroundColor: (context: { chart: Parameters<typeof areaGradient>[0] }) => areaGradient(context.chart, '#4f46e5'),
                borderWidth: 2.5,
                pointRadius: 5,
                pointHoverRadius: 8,
                pointBackgroundColor: chrome.surface,
                pointBorderColor: '#4f46e5',
                pointBorderWidth: 2.5,
                tension: 0.36,
                fill: true,
                spanGaps: false,
            }],
        },
        options: { scales: { x: categoryAxis(chrome), y: percentAxis(chrome) } },
    } as ChartConfiguration;
});

const classTrendTooltip = (index: number): TooltipContent | null => {
    const row = stats.value.period_series[index];

    if (row === undefined) {
        return null;
    }

    const previous = index === 0 ? null : stats.value.period_series[index - 1].class_average;
    const lines: TooltipContent['rows'] = [
        { label: 'Média Ponderada', value: pct(row.class_average), strong: true },
    ];

    if (previous !== null && row.class_average !== null) {
        const change = Number(row.class_average) - Number(previous);

        lines.push({
            label: 'Variação',
            value: `${change > 0 ? '↑' : change < 0 ? '↓' : '→'} ${formatPoints(change)} p.p.`,
            trend: change > 0 ? 'up' : change < 0 ? 'down' : 'flat',
        });
    }

    lines.push({ label: 'Com resultado', value: studentsWord(row.students_with_result) });

    return { title: row.label.toUpperCase(), rows: lines, footer: 'Cada período isoladamente, sem o acumulado.' };
};

/** 5 · Evolução da turma — o único donut da página. */
const evolutionSlices = computed(() => {
    const evolution = stats.value.evolution;

    return [
        { label: 'Progrediram', count: evolution.progressed, share: evolution.percentages.progressed, colour: TREND_COLOURS.up },
        { label: 'Mantiveram-se', count: evolution.stable, share: evolution.percentages.stable, colour: TREND_COLOURS.flat },
        { label: 'Regrediram', count: evolution.regressed, share: evolution.percentages.regressed, colour: TREND_COLOURS.down },
        { label: 'Sem comparação', count: evolution.no_comparison, share: evolution.percentages.no_comparison, colour: TREND_COLOURS.none },
    ];
});

const evolutionChart = computed<ChartConfiguration>(() => ({
    type: 'doughnut',
    data: {
        labels: evolutionSlices.value.map((slice) => slice.label),
        datasets: [{
            data: evolutionSlices.value.map((slice) => slice.count),
            backgroundColor: evolutionSlices.value.map((slice) => slice.colour.fill),
            borderColor: chromeColours().surface,
            borderWidth: 3,
            hoverOffset: prefersReducedMotion() ? 0 : 6,
        }],
    },
    options: { cutout: '68%' },
} as ChartConfiguration));

const evolutionTooltip = (index: number): TooltipContent | null => {
    const slice = evolutionSlices.value[index];

    if (slice === undefined) {
        return null;
    }

    return {
        title: slice.label.toUpperCase(),
        rows: [
            { label: 'Alunos', value: String(slice.count), strong: true, swatch: slice.colour.border },
            { label: 'Da turma', value: formatShare(slice.share) },
        ],
        footer: slice.label === 'Sem comparação' ? 'Sem período anterior comparável — não é manutenção.' : undefined,
    };
};

// ------------------------------------------------------- equivalentes textuais

const distributionRows = computed(() => stats.value.distribution.map((row) => [
    `${row.code} — ${row.label}`, row.count, formatShare(row.percentage),
]));

// The domain figures need no sr-only table of their own: RibbonBar is real DOM
// and every value in it is already text a screen reader reads directly.

const seriesRows = computed(() => stats.value.period_series.map((row) => [
    row.label, pct(row.class_average),
    ...stats.value.domains.map((domain) => pct(
        row.domains.find((candidate) => candidate.domain_id === domain.id)?.average ?? null,
    )),
]));

const evolutionRows = computed(() => evolutionSlices.value.map((slice) => [
    slice.label, slice.count, formatShare(slice.share),
]));

// ------------------------------------------------------------- leitura discreta

const extremes = computed(() => {
    const comparable = stats.value.domain_statistics.filter((row) => row.period_average !== null);

    if (comparable.length < 2) {
        return null;
    }

    const sorted = [...comparable].sort((a, b) => Number(b.period_average) - Number(a.period_average));
    const highest = sorted[0];
    const lowest = sorted[sorted.length - 1];

    return highest.period_average === lowest.period_average ? null : { highest, lowest };
});

// ------------------------------------------------------------------ heatmap

function heatCell(student: Student, domainId: number): DomainCell | undefined {
    return student.domains.find((domain) => domain.domain_id === domainId);
}

const selected = ref<Student | null>(null);

/**
 * The crosshair. Purely a reading aid: with fifteen students and five domains,
 * following one row across is where a finger on the screen used to go.
 */
const hoveredStudentId = ref<number | null>(null);
const hoveredDomainId = ref<number | null>(null);

/** The chosen student, domain by domain. */
const studentChart = computed<ChartConfiguration | null>(() => {
    const student = selected.value;

    if (student === null) {
        return null;
    }

    const chrome = chromeColours();
    const domains = stats.value.domains;

    return {
        type: 'bar',
        data: {
            labels: domains.map((domain) => domain.name),
            datasets: [
                {
                    label: 'Média do período',
                    data: domains.map((domain) => {
                        const cell = heatCell(student, domain.id);

                        return cell?.weighted_average == null ? null : Number(cell.weighted_average);
                    }),
                    // Each bar in its own domain's ink — the same one the trend
                    // lines and the heatmap header use.
                    backgroundColor: domains.map((domain) => inks.value[domain.id]),
                    borderRadius: 7,
                    maxBarThickness: 30,
                },
                {
                    label: 'Média acumulada',
                    data: domains.map((domain) => {
                        const cell = heatCell(student, domain.id);

                        return cell?.accumulated_average == null ? null : Number(cell.accumulated_average);
                    }),
                    backgroundColor: domains.map((domain) => muted(inks.value[domain.id], 0.26)),
                    borderRadius: 7,
                    maxBarThickness: 30,
                },
            ],
        },
        options: { scales: { x: categoryAxis(chrome), y: percentAxis(chrome) } },
    } as ChartConfiguration;
});

const studentTooltip = (index: number, datasetIndex: number): TooltipContent | null => {
    const student = selected.value;
    const domain = stats.value.domains[index];

    if (student === null || domain === undefined) {
        return null;
    }

    const cell = heatCell(student, domain.id);
    const lines: TooltipContent['rows'] = [
        { label: 'Média do período', value: pct(cell?.weighted_average ?? null), strong: datasetIndex === 0, swatch: inks.value[domain.id] },
        { label: 'Média acumulada', value: pct(cell?.accumulated_average ?? null), strong: datasetIndex === 1 },
    ];

    if (cell?.mention) {
        lines.push({ label: 'Menção', value: cell.mention.label });
    }

    if (cell?.self_assessment) {
        lines.push({ label: 'Autoavaliação', value: `${cell.self_assessment.code} — ${cell.self_assessment.label}` });
    }

    return { title: domain.name.toUpperCase(), subtitle: student.name, rows: lines };
};

const studentRows = computed(() => {
    const student = selected.value;

    if (student === null) {
        return [];
    }

    return stats.value.domains.map((domain) => {
        const cell = heatCell(student, domain.id);

        return [
            domain.name, pct(cell?.weighted_average ?? null), pct(cell?.accumulated_average ?? null),
            cell?.mention?.label ?? '—',
            cell?.self_assessment == null ? '—' : cell.self_assessment.code,
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

            <div v-if="stats.periods.length" class="flex flex-wrap gap-1">
                <Link
                    v-for="period in stats.periods"
                    :key="period.ulid"
                    :href="`/classes/${schoolClass.ulid}/results/${period.ulid}`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm transition-colors hover:bg-muted/40"
                >
                    {{ period.label }}
                </Link>
                <Link
                    :href="`/classes/${schoolClass.ulid}/results/quadro-sintese`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm transition-colors hover:bg-muted/40"
                >
                    Quadro Síntese
                </Link>
                <span class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm text-primary-foreground">Estatística</span>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
            Esta turma não tem perfil de avaliação associado, por isso não há resultados a analisar.
        </p>

        <!-- ============================================== estado vazio -->
        <div v-else-if="!hasAnyResult" class="rounded-2xl border border-dashed border-border bg-gradient-to-b from-muted/30 to-transparent px-6 py-16 text-center">
            <div class="mx-auto flex size-12 items-center justify-center rounded-2xl bg-muted/60">
                <TrendingUp class="size-5 text-muted-foreground" />
            </div>
            <p class="mt-4 text-base font-semibold">Ainda não existem resultados suficientes</p>
            <p class="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                Assim que houver avaliações registadas neste período, esta página mostra a distribuição pela escala,
                a evolução da turma e as médias de cada domínio.
            </p>
            <Link
                :href="`/classes/${schoolClass.ulid}/results`"
                class="mt-5 inline-flex items-center gap-1 rounded-lg border border-border px-4 py-2 text-sm font-medium transition-colors hover:bg-muted/40"
            >
                Ir para Resultados →
            </Link>
        </div>

        <template v-else>
            <!-- =============================================== período -->
            <div v-if="stats.periods.length > 1" class="flex flex-wrap items-center gap-2">
                <span class="text-sm text-muted-foreground">Período em análise:</span>
                <button
                    v-for="period in stats.periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-full border px-3.5 py-1 text-sm transition-all duration-150"
                    :class="period.id === stats.selected_period?.id
                        ? 'border-primary bg-primary text-primary-foreground shadow-sm'
                        : 'border-border hover:border-foreground/25 hover:bg-muted/40'"
                    :aria-pressed="period.id === stats.selected_period?.id"
                    @click="goToPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>

            <!-- ==================================== estado de seleção (§5) -->
            <Transition
                :enter-active-class="prefersReducedMotion() ? '' : 'transition duration-150 ease-out'"
                enter-from-class="opacity-0 -translate-y-1"
                enter-to-class="opacity-100 translate-y-0"
            >
                <div
                    v-if="selectedDomain || selectedLevel"
                    class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-4 py-2.5 text-sm"
                    role="status"
                >
                    <span class="text-muted-foreground">A destacar:</span>

                    <span v-if="selectedDomain" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        <span class="size-2 rounded-full" :style="{ backgroundColor: inks[selectedDomain.id] }"></span>
                        {{ selectedDomain.name }}
                    </span>

                    <span v-if="selectedLevel" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        {{ selectedLevel.code }} · {{ selectedLevel.label }}
                        <span class="text-muted-foreground">{{ studentsWord(highlightedStudents) }}</span>
                    </span>

                    <button
                        type="button"
                        class="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs text-muted-foreground transition-colors hover:bg-background hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="clearSelection"
                    >
                        <X class="size-3.5" /> Limpar
                    </button>
                </div>
            </Transition>

            <!-- ============================================ 01 · o resumo -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <InfographicMetric
                    index="01"
                    label="Média Ponderada da turma"
                    :value="pct(stats.summary.class_average)"
                    :context="stats.selected_period ? `${stats.selected_period.label}, só com este período` : undefined"
                >
                    <p
                        v-if="hasComparison && stats.evolution.average_change !== null"
                        class="mt-2.5 inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium"
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
                </InfographicMetric>

                <InfographicMetric
                    index="02"
                    label="Média acumulada"
                    :value="pct(stats.summary.accumulated_average)"
                    context="Tudo o que conta até este período"
                />

                <InfographicMetric index="03" label="Alunos com resultado" value="">
                    <template #value>
                        {{ stats.summary.students_with_result }}<span class="text-lg font-normal text-muted-foreground">/{{ stats.summary.students_total }}</span>
                    </template>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        <template v-if="stats.summary.students_without_result > 0">
                            {{ stats.summary.students_without_result }} sem resultado neste período
                        </template>
                        <template v-else>Toda a turma tem resultado</template>
                    </p>
                </InfographicMetric>

                <InfographicMetric
                    index="04"
                    :label="stats.summary.most_common_band ? 'Menção mais frequente' : 'Informação parcial'"
                    :value="stats.summary.most_common_band ? '' : String(stats.summary.partial_coverage_count)"
                >
                    <template v-if="stats.summary.most_common_band" #value>
                        <span class="flex items-center gap-2">
                            {{ stats.summary.most_common_band.code }}
                            <span class="rounded-md px-2 py-0.5 text-xs font-medium" :class="toneClass(stats.summary.most_common_band)">
                                {{ stats.summary.most_common_band.label }}
                            </span>
                        </span>
                    </template>
                    <p class="mt-2 text-xs leading-relaxed text-muted-foreground">
                        <template v-if="stats.summary.most_common_band">
                            {{ stats.summary.most_common_band.count }} de {{ stats.summary.students_total }} alunos
                        </template>
                        <template v-else>resultados com informação parcial</template>
                    </p>
                </InfographicMetric>
            </div>

            <p
                v-if="stats.summary.partial_coverage_count > 0 && stats.summary.most_common_band"
                class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <CircleAlert class="mt-0.5 size-4 shrink-0" />
                <span>
                    {{ stats.summary.partial_coverage_count === 1
                        ? `1 de ${stats.summary.students_total} alunos tem`
                        : `${stats.summary.partial_coverage_count} de ${stats.summary.students_total} alunos têm` }}
                    o resultado deste período calculado com informação parcial.
                    O detalhe de cada caso está em Resultados, junto ao aviso do próprio aluno.
                </span>
            </p>

            <!-- ==================================== distribuição + evolução -->
            <div class="grid gap-4 lg:grid-cols-5">
                <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] lg:col-span-3 dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                    <SectionHeading
                        index="01"
                        title="Distribuição pela escala"
                        :description="`Menção de cada aluno, colocada pela Média Ponderada Acumulada${schoolClass.scale_name ? ` na escala «${schoolClass.scale_name}»` : ''}. Clique numa coluna para realçar esses alunos.`"
                    />

                    <StatChart
                        v-if="stats.distribution.length"
                        :config="distributionChart"
                        :tooltip="distributionTooltip"
                        summary="Número de alunos em cada banda da escala de classificação."
                        :headers="['Banda', 'Alunos', 'Percentagem']"
                        :rows="distributionRows"
                        height-class="h-80"
                        depth
                        @select="(index) => toggleLevel(stats.distribution[index].scale_level_id)"
                    />
                    <p v-else class="py-14 text-center text-sm text-muted-foreground">
                        A escala desta turma não tem bandas configuradas, por isso não há menções para distribuir.
                    </p>
                </section>

                <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] lg:col-span-2 dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                    <SectionHeading
                        index="02"
                        title="Evolução da turma"
                        :description="hasComparison
                            ? `Este período comparado com ${stats.previous_period?.label}.`
                            : 'Ainda não há período anterior para comparar.'"
                    />

                    <template v-if="hasComparison">
                        <StatChart
                            :config="evolutionChart"
                            :tooltip="evolutionTooltip"
                            summary="Quantos alunos progrediram, se mantiveram, regrediram ou não têm comparação possível."
                            :headers="['Movimento', 'Alunos', 'Percentagem']"
                            :rows="evolutionRows"
                            height-class="h-56"
                        />
                        <!-- A legend of our own: the library's cannot carry a
                             count and a share beside the label. -->
                        <ul class="mt-4 space-y-1.5">
                            <li v-for="slice in evolutionSlices" :key="slice.label" class="flex items-center gap-2 text-xs">
                                <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: slice.colour.border }"></span>
                                <span class="text-muted-foreground">{{ slice.label }}</span>
                                <span class="ml-auto tabular-nums font-medium">{{ slice.count }}</span>
                                <span class="w-14 text-right tabular-nums text-muted-foreground">{{ formatShare(slice.share) }}</span>
                            </li>
                        </ul>
                    </template>

                    <div v-else class="flex h-72 flex-col items-center justify-center rounded-xl border border-dashed border-border/70 px-6 text-center">
                        <Minus class="size-5 text-muted-foreground/60" />
                        <p class="mt-3 text-sm font-medium">Sem comparação possível</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            A evolução aparecerá aqui quando existir um segundo momento de avaliação.
                        </p>
                    </div>
                </section>
            </div>

            <!-- ==================================== 03 · médias por domínio -->
            <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                <SectionHeading
                    index="03"
                    title="Médias por domínio"
                    description="Na ordem do perfil de avaliação, e não por resultado. Clique num domínio para o seguir na página."
                >
                    <template #aside>
                        <div v-if="extremes" class="hidden gap-5 text-xs sm:flex">
                            <p>
                                <span class="text-muted-foreground">Média mais elevada</span><br />
                                <span class="font-medium">{{ extremes.highest.label }} · {{ pct(extremes.highest.period_average) }}</span>
                            </p>
                            <p>
                                <span class="text-muted-foreground">Média mais baixa</span><br />
                                <span class="font-medium">{{ extremes.lowest.label }} · {{ pct(extremes.lowest.period_average) }}</span>
                            </p>
                        </div>
                    </template>
                </SectionHeading>

                <!-- Ribbons rather than bars, and real DOM rather than a canvas:
                     every value is text, so this needs no parallel table. -->
                <RibbonBar
                    :rows="domainRibbons"
                    :selected-id="selectedDomainId"
                    summary="Média da turma em cada domínio, neste período."
                    @select="toggleDomain"
                />
            </section>

            <!-- ==================================== evolução ao longo do ano -->
            <div v-if="hasSeveralPeriods" class="grid gap-4 lg:grid-cols-2">
                <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                    <SectionHeading index="04" title="Evolução global da turma" description="Média Ponderada de cada período, isoladamente." />
                    <StatChart
                        :config="classTrendChart"
                        :tooltip="classTrendTooltip"
                        summary="Média Ponderada da turma em cada período do ano letivo."
                        :headers="['Período', 'Média Ponderada']"
                        :rows="stats.period_series.map((row) => [row.label, pct(row.class_average)])"
                        height-class="h-72"
                    />
                </section>

                <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                    <SectionHeading index="05" title="Evolução dos domínios" description="Cada linha é um domínio, período a período." />
                    <StatChart
                        :config="domainSeriesChart"
                        :tooltip="domainSeriesTooltip"
                        summary="Média da turma em cada domínio, ao longo dos períodos do ano letivo."
                        :headers="['Período', 'Turma', ...stats.domains.map((domain) => domain.name)]"
                        :rows="seriesRows"
                        height-class="h-72"
                    />

                    <!-- The legend IS the domain selector — one control, and the
                         same inks the lines are drawn in. -->
                    <ul class="mt-4 flex flex-wrap gap-1.5">
                        <li v-for="domain in stats.domains" :key="domain.id">
                            <button
                                type="button"
                                class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs transition-all duration-150 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                :class="isLit(domain.id)
                                    ? 'border-border bg-background hover:bg-muted/50'
                                    : 'border-transparent bg-muted/30 text-muted-foreground'"
                                :aria-pressed="selectedDomainId === domain.id"
                                @click="toggleDomain(domain.id)"
                            >
                                <span
                                    class="size-2 rounded-full transition-opacity"
                                    :style="{ backgroundColor: inks[domain.id], opacity: isLit(domain.id) ? 1 : 0.35 }"
                                ></span>
                                {{ domain.name }}
                            </button>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- ============================================ 06 · o mapa -->
            <section class="rounded-2xl border border-border bg-card p-6 shadow-[0_1px_0_0_var(--border),0_12px_28px_-22px_rgba(0,0,0,0.45)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.05),0_14px_32px_-24px_rgba(0,0,0,0.9)]">
                <SectionHeading
                    index="06"
                    title="Mapa da turma"
                    description="Média de cada aluno em cada domínio, neste período. O valor está sempre escrito — a cor só o reforça."
                >
                    <template #aside>
                        <p v-if="selectedLevel" class="text-xs text-muted-foreground">
                            A realçar {{ studentsWord(highlightedStudents) }} com menção «{{ selectedLevel.label }}»
                        </p>
                    </template>
                </SectionHeading>

                <div class="-mx-2 overflow-x-auto px-2">
                    <table class="w-max min-w-full border-separate border-spacing-y-1 text-sm">
                        <thead>
                            <tr class="text-left">
                                <th scope="col" class="sticky left-0 z-10 bg-card px-3 pb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    Aluno
                                </th>
                                <th
                                    v-for="domain in stats.domains"
                                    :key="domain.id"
                                    scope="col"
                                    class="px-2 pb-2 text-center"
                                >
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold uppercase tracking-wider transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        :class="selectedDomainId === domain.id ? 'bg-primary/10 text-foreground' : 'text-muted-foreground hover:bg-muted/50'"
                                        :aria-pressed="selectedDomainId === domain.id"
                                        @click="toggleDomain(domain.id)"
                                    >
                                        <span
                                            class="size-1.5 rounded-full transition-opacity"
                                            :style="{ backgroundColor: inks[domain.id], opacity: isLit(domain.id) ? 1 : 0.3 }"
                                        ></span>
                                        {{ domain.name }}
                                    </button>
                                </th>
                                <th scope="col" class="px-3 pb-2 text-center text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    Menção
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="student in stats.students"
                                :key="student.enrollment_id"
                                class="transition-opacity duration-200"
                                :class="[
                                    matchesLevel(student) ? '' : 'opacity-30',
                                    hoveredStudentId === student.enrollment_id ? 'bg-muted/25' : '',
                                ]"
                                @mouseleave="hoveredStudentId = null"
                            >
                                <th scope="row" class="sticky left-0 z-10 bg-card px-3 py-1 text-left font-normal">
                                    <button
                                        type="button"
                                        class="flex items-center gap-2 rounded-md px-1.5 py-1 text-left transition-colors hover:bg-muted/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        @click="selected = student"
                                        @mouseenter="hoveredStudentId = student.enrollment_id"
                                        @focus="hoveredStudentId = student.enrollment_id"
                                    >
                                        <span class="w-5 shrink-0 text-right tabular-nums text-xs text-muted-foreground">
                                            {{ student.class_number ?? '—' }}
                                        </span>
                                        <span class="truncate">{{ student.name }}</span>
                                        <CircleAlert v-if="student.coverage_warning && student.weighted_average !== null" class="size-3 shrink-0 text-amber-500" />
                                    </button>
                                </th>
                                <td
                                    v-for="domain in stats.domains"
                                    :key="domain.id"
                                    class="px-1.5 py-1 text-center transition-colors duration-150"
                                    :class="hoveredDomainId === domain.id ? 'bg-muted/25' : ''"
                                    @mouseenter="hoveredStudentId = student.enrollment_id; hoveredDomainId = domain.id"
                                >
                                    <!--
                                      A cell with a face: a solid bottom edge and
                                      a soft shadow, and a lift of 2px under the
                                      pointer. Decorative — the value is written
                                      in it and the tone only reinforces (§22).
                                    -->
                                    <span
                                        class="inline-flex min-w-16 items-center justify-center rounded-lg px-2 py-1.5 text-xs tabular-nums shadow-[0_1px_0_0_rgba(0,0,0,0.06)] dark:shadow-[0_1px_0_0_rgba(255,255,255,0.06)]"
                                        :class="[
                                            heatCell(student, domain.id)?.mention
                                                ? toneClass(heatCell(student, domain.id)!.mention)
                                                : 'bg-muted/40 text-muted-foreground',
                                            isLit(domain.id) ? '' : 'opacity-25',
                                            selectedDomainId === domain.id ? 'ring-1 ring-primary/40' : '',
                                            prefersReducedMotion() ? '' : 'transition-all duration-150 hover:-translate-y-0.5 hover:shadow-[0_3px_6px_-2px_rgba(0,0,0,0.25)]',
                                        ]"
                                        :title="heatCell(student, domain.id)?.mention?.label ?? 'Sem resultado'"
                                    >
                                        {{ pct(heatCell(student, domain.id)?.weighted_average ?? null) }}
                                    </span>
                                </td>
                                <td class="px-3 py-1 text-center">
                                    <span v-if="student.band" class="rounded-md px-2 py-1 text-xs font-medium" :class="toneClass(student.band)">
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
        <Transition
            :enter-active-class="prefersReducedMotion() ? '' : 'transition duration-200 ease-out'"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            :leave-active-class="prefersReducedMotion() ? '' : 'transition duration-150 ease-in'"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="selected"
                class="fixed inset-0 z-50 flex justify-end bg-black/40 backdrop-blur-[2px]"
                role="dialog"
                aria-modal="true"
                :aria-label="`Leitura individual de ${selected.name}`"
                @click.self="selected = null"
                @keydown.esc="selected = null"
            >
                <div class="h-full w-full max-w-xl overflow-y-auto border-l border-border bg-background p-6 shadow-2xl">
                    <div class="mb-5 flex items-start justify-between gap-3">
                        <div>
                            <h2 class="text-lg font-semibold tracking-tight">{{ selected.name }}</h2>
                            <p class="text-sm text-muted-foreground">{{ schoolClass.label }} · {{ stats.selected_period?.label }}</p>
                        </div>
                        <button
                            type="button"
                            class="rounded-lg border border-border p-2 transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            aria-label="Fechar"
                            @click="selected = null"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <div class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Período</p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ pct(selected.weighted_average) }}</p>
                        </div>
                        <div class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Acumulada</p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums">{{ pct(selected.accumulated_average) }}</p>
                        </div>
                        <div class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Evolução</p>
                            <p
                                class="mt-0.5 text-xl font-semibold tabular-nums"
                                :class="selected.evolution?.direction === 'up' ? 'text-emerald-600 dark:text-emerald-400'
                                    : selected.evolution?.direction === 'down' ? 'text-rose-600 dark:text-rose-400' : ''"
                            >
                                {{ selected.evolution ? formatPoints(selected.evolution.points) : '—' }}
                            </p>
                            <p class="text-[11px] text-muted-foreground">p.p.</p>
                        </div>
                        <div class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Menção</p>
                            <p v-if="selected.band" class="mt-1 inline-block rounded-md px-2 py-0.5 text-sm font-medium" :class="toneClass(selected.band)">
                                {{ selected.band.label }}
                            </p>
                            <p v-else class="mt-0.5 text-xl font-semibold">—</p>
                        </div>
                    </div>

                    <div class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-xl border border-border p-3.5">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">{{ decision.label }}</p>
                            <p v-if="selected.classification?.final" class="mt-1 text-sm font-medium">
                                {{ selected.classification.final.code }} — {{ selected.classification.final.label }}
                            </p>
                            <p v-else class="mt-1 text-sm text-muted-foreground">Ainda sem decisão registada</p>
                        </div>
                        <div class="rounded-xl border border-border p-3.5">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">Autoavaliação do aluno</p>
                            <p v-if="selected.self_assessment" class="mt-1 text-sm font-medium">
                                {{ selected.self_assessment.code }} — {{ selected.self_assessment.label }}
                            </p>
                            <p v-else class="mt-1 text-sm text-muted-foreground">Sem autoavaliação submetida</p>
                        </div>
                    </div>

                    <p v-if="selected.coverage_warning" class="mt-3 flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50/60 px-3.5 py-2.5 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/30 dark:text-amber-200">
                        <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                        <span v-if="selected.weighted_average !== null">
                            O resultado deste período foi calculado com informação parcial.
                            O detalhe está em Resultados, no aviso do próprio aluno.
                        </span>
                        <span v-else>Ainda não há elementos avaliados que produzam um resultado neste período.</span>
                    </p>

                    <section class="mt-6">
                        <h3 class="mb-4 text-sm font-semibold">Por domínio</h3>
                        <StatChart
                            v-if="studentChart"
                            :config="studentChart"
                            :tooltip="studentTooltip"
                            :summary="`Média de ${selected.name} em cada domínio, no período e acumulada.`"
                            :headers="['Domínio', 'Média do período', 'Acumulada', 'Menção', 'Autoavaliação']"
                            :rows="studentRows"
                            height-class="h-64"
                        />
                    </section>

                    <p class="mt-5 text-xs text-muted-foreground">
                        Estes números são os mesmos de Resultados e do Quadro Síntese — esta página apenas os agrega.
                    </p>
                </div>
            </div>
        </Transition>
    </div>
</template>
