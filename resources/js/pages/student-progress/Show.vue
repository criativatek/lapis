<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarClock,
    ExternalLink,
    FileText,
    HeartHandshake,
    MessageCircle,
    NotebookPen,
    Plus,
    Printer,
    Sparkles,
    Star,
    UserMinus,
} from '@lucide/vue';
import type { ChartConfiguration } from 'chart.js';
import { computed, defineAsyncComponent, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    categoryAxis,
    chromeColours,
    formatPoints,
    pct,
    percentAxis,
} from '@/lib/chartTheme';
import type { TooltipContent } from '@/lib/chartTheme';
import { card, INSET } from '@/lib/surfaces';

const StatChart = defineAsyncComponent(() => import('@/components/charts/StatChart.vue'));

/**
 * Acompanhamento do Aluno — one student's year, told as a story rather than as
 * a dashboard (§45).
 *
 * The route, the controller and the read model are all still named
 * StudentProgress: only what the teacher reads changed. «Evolução» survives
 * inside the page, on the chart and the sentences about results moving over
 * time, because there it means the trajectory and not the page.
 *
 * ONDE ESTÁ → COMO EVOLUIU → EM QUÊ → O QUE ACONTECEU. The order of the sections
 * is the order of the questions a teacher actually asks, and every section
 * answers exactly one of them. That is why there are not ten identical cards:
 * the page has a shape, and a reader who scrolls it once knows the year.
 *
 * NOT A SECOND OPINION ANYWHERE. Every figure rendered here arrived decided.
 * Nothing on this page adds, subtracts, averages or rounds — the one arithmetic
 * in the whole file is turning a string into a number so a canvas can draw it.
 *
 * THREE THINGS ARE KEPT APART, ON PURPOSE AND VISUALLY (§15, §16, §29):
 * the calculated result, which is a percentage; the assigned classification,
 * which is a decision on the profile's own scale; and the self-assessment,
 * which is what the student said. They never share an axis, a series or a card,
 * because they are three different statements and drawing them as one would
 * invent a comparison the data does not support.
 */

type Level = {
    scale_level_id?: number;
    code: string | null;
    label: string | null;
    sequence?: number;
    is_negative?: boolean | null;
};

type Evolution = { direction: 'up' | 'down' | 'flat'; points: string } | null;

type Coverage = 'complete' | 'partial' | 'none';

type Moment = {
    key: string;
    kind: 'period' | 'interim';
    label: string;
    date: string | null;
    period_label?: string;
    value: string | null;
    reading: 'accumulated' | 'period';
    coverage: Coverage;
    before_enrolment: boolean;
    is_available?: boolean;
};

type DomainRow = {
    domain_id: number;
    name: string;
    weighted_average: string | null;
    accumulated_average: string | null;
    mention: Level | null;
    self_assessment: Level | null;
    evolution: Evolution;
    coverage: Coverage;
};

type ClassificationRow = {
    period_id: number;
    period_label: string;
    is_decided: boolean;
    assigned: Level | null;
    assigned_value: string | null;
    is_published: boolean;
    proposal: { label?: string | null; value?: string | null } | null;
    differs_from_proposal: boolean;
};

type SelfAssessmentRow = {
    period_id: number;
    period_label: string;
    self_assessment: Level | null;
    assigned: Level | null;
    comparison: { difference: number; direction: 'above' | 'below' | 'same' } | null;
};

type RecordRow = {
    ulid: string;
    kind: string;
    kind_label: string;
    group: string;
    occurred_at: string;
    description: string;
    domain: string | null;
    severity: string | null;
    homework_status: string | null;
    participation_level: string | null;
};

type InterventionRow = {
    ulid: string;
    title: string;
    type: string | null;
    motive: string | null;
    objective: string | null;
    effectiveness: string | null;
    last_followup_on: string | null;
    followup_count: number;
    status: string;
    is_concluded: boolean;
    started_on: string;
    concluded_on: string | null;
    domain: string | null;
    is_individual: boolean;
    needs_review: boolean;
    /** Recuperação / Consolidação / Melhoria — null on a row recorded before this existed (§8). */
    purpose: string | null;
    purpose_label: string | null;
    frequency: string | null;
    tracking_indicator: string | null;
};

/** A fact, never an interpretation — Base (§1 of the Acompanhamento do Aluno brief). */
type FactualNote = { key: string; sentence: string; count?: number };

type RecentInstrument = {
    ulid: string;
    title: string;
    type: string | null;
    applied_on: string;
    status: string;
    counts_toward_classification: boolean;
    result: string | null;
    scale_label: string | null;
    evolution: Evolution;
};

/** Estado 360º — a handful of dimensions, each with an explicit neutral state (Pro, §1). */
type Dimension = { key: string; label: string; state: string; state_label: string; detail: string };

type EvolutionAfterStrategy = { ulid: string; title: string | null; state: 'positive' | 'negative' | 'stable' | 'insufficient'; sentence: string };

type ProPayload = {
    estado360: { dimensions: Dimension[] };
    analyticalAlerts: FactualNote[];
    positiveSignals: FactualNote[];
    potentialities: {
        narrative: string | null;
        strengths: { domain_id: number; name: string; value: string; detail: string }[];
        progressing: { domain_id: number; name: string; value: string; detail: string }[];
        next_step: string | null;
    };
    whatChanged: { items: FactualNote[]; empty_sentence: string | null };
    evolutionAfterStrategy: EvolutionAfterStrategy[];
    conversationPrep: {
        situation: { value: string | null; coverage: Coverage; reading_label: string | null };
        difficulties: string[];
        strengths: string[];
        changes: string[];
        potentialities: string | null;
        ongoingStrategies: { title: string | null; status: string; purpose_label: string | null; effectiveness: string | null }[];
        selfAssessment: SelfAssessmentRow[];
        evolutionAfterStrategy: EvolutionAfterStrategy[];
        positiveSignals: string[];
        topicsToDiscuss: string[];
    };
};

type AiSuggestionRow = {
    name: string | null;
    purpose: string;
    objective: string;
    strategy: string;
    frequency: string | null;
    duration: string | null;
    tracking_indicator: string | null;
    review_suggestion: string | null;
};

type AiPurposeSuggestion = {
    purpose: string;
    purpose_label: string;
    domain_id: number;
    domain: string;
    value: string;
    justification: string;
};

const props = defineProps<{
    student: {
        ulid: string;
        name: string;
        class_number: number | null;
        is_late_entry: boolean;
        enrolled_on: string;
        left_on: string | null;
        status_label: string;
        is_current: boolean;
        status_reason: string | null;
    };
    // `schoolClass`, never `class`: a prop called `class` cannot be read in a
    // template expression, because `class.label` parses as a class declaration.
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        has_profile: boolean;
        scale_name: string | null;
    };
    reading: {
        kind: 'accumulated' | 'period';
        canonical: 'accumulated' | 'period';
        has_toggle: boolean;
        label: string;
        caption: string;
    };
    headline: {
        value: string | null;
        supplementary_value: string | null;
        band: Level | null;
        coverage: Coverage;
        classification: { status?: string; final?: Level | null; final_value?: string | null } | null;
        self_assessment: Level | null;
        evolution: Evolution;
        continuous_evolution: Evolution;
    };
    moments: Moment[];
    classifications: ClassificationRow[];
    selfAssessments: SelfAssessmentRow[];
    domains: {
        rows: DomainRow[];
        highlights: {
            highest: { domain_id: number; name: string; value: string } | null;
            lowest: { domain_id: number; name: string; value: string } | null;
            largest_rise: { domain_id: number; name: string; value: string } | null;
            largest_fall: { domain_id: number; name: string; value: string } | null;
        };
    };
    sinceLast: {
        from_label: string;
        to_label: string;
        from_date: string | null;
        to_date: string | null;
        from: string | null;
        to: string | null;
        classification_from: Level | null;
        classification_to: Level | null;
        domains: { domain_id: number; evolution: Evolution }[];
    } | null;
    classComparison: {
        student: string;
        class: string;
        students_with_result: number;
        difference: string;
    } | null;
    records: { total: number; kinds: { value: string; label: string; count: number }[]; rows: RecordRow[] };
    interventions: { total: number; individual: number; needing_review: number; rows: InterventionRow[] };
    recentInstruments: RecentInstrument[];
    narrative: string | null;
    // Base — facts, never interpretation (§1).
    factualAlerts: FactualNote[];
    strengths: FactualNote[];
    // Pro — absent entirely for a Base organization, never merely hidden
    // (§8.2 of CLAUDE.md): gating happened on the server, before this ever
    // left it.
    pro?: ProPayload;
    ai: { available: boolean; reason: string | null };
    aiPurposeOptions: { value: string; label: string; description: string }[];
    aiPurposeSuggestions: Record<string, AiPurposeSuggestion | null>;
    aiSuggestion: {
        domain_id: number;
        domain: string;
        purpose: string;
        purpose_label: string;
        enrollment_ulid: string;
        suggestions: AiSuggestionRow[];
    } | null;
    aiSuggestionError: { message: string } | null;
    links: {
        records: string;
        interventions: string;
        // Absent for a student who has left: a new intervention is about the
        // class as it stands (§20).
        newIntervention: string | null;
        reports: string;
        statistics: string;
        suggestStrategy: string;
    };
}>();

const CARD = `${card('plain')} p-5`;

// --------------------------------------------------------------- impressão

/**
 * The label names what the printed document WILL contain — never a plan
 * name. Read through the same established pattern the rest of the app
 * already uses for a client-side label decision (assessments/Index.vue's
 * `canImportGrids`): the effective module list `HandleInertiaRequests`
 * already shares, never a second entitlements read (§5, §19 of the print
 * brief).
 */
const canPrintSynthesis = computed(() => usePage().props.modules.includes('advanced_analytics'));
const printLabel = computed(() => (canPrintSynthesis.value ? 'Imprimir síntese' : 'Imprimir ficha'));
const printHref = computed(() => `/classes/${props.schoolClass.ulid}/evolucao/${props.student.ulid}/imprimir`);

// ------------------------------------------------------------------- leitura

/**
 * The toggle only exists where the two readings are different figures.
 *
 * At the first contributing moment of a year the accumulated result and the
 * period's own work are the same number, and offering a choice between them
 * would invent a distinction the data does not have (§9, §10).
 */
function setReading(kind: 'accumulated' | 'period') {
    router.get(
        `/classes/${props.schoolClass.ulid}/evolucao/${props.student.ulid}`,
        { leitura: kind === 'accumulated' ? 'continua' : 'periodo' },
        { preserveScroll: true, preserveState: false },
    );
}

// -------------------------------------------------------------- formatação

const dateFormatter = new Intl.DateTimeFormat('pt-PT', { day: 'numeric', month: 'long', year: 'numeric' });
const shortDateFormatter = new Intl.DateTimeFormat('pt-PT', { day: '2-digit', month: 'short' });

function longDate(value: string): string {
    return dateFormatter.format(new Date(`${value}T00:00:00`));
}

function shortDate(value: string): string {
    return shortDateFormatter.format(new Date(`${value}T00:00:00`)).replace('.', '');
}

/** The arrow is never the only channel — the sign travels with it (§48). */
function arrow(direction: 'up' | 'down' | 'flat' | 'above' | 'below' | 'same'): string {
    return direction === 'up' || direction === 'above' ? '↑' : direction === 'down' || direction === 'below' ? '↓' : '→';
}

function trendClass(direction: 'up' | 'down' | 'flat'): string {
    return direction === 'up'
        ? 'text-emerald-700 dark:text-emerald-400'
        : direction === 'down'
          ? 'text-rose-700 dark:text-rose-400'
          : 'text-muted-foreground';
}

const COVERAGE_LABEL: Record<Coverage, string> = {
    complete: 'Completa',
    partial: 'Cobertura parcial',
    none: 'Sem elementos avaliados',
};

// «Completa» significa apenas que nenhuma lacuna foi sinalizada pelo cálculo
// canónico — nunca que este ecrã sabe listar, elemento a elemento, os "X de X"
// considerados. Essa contagem não sobrevive ao cálculo até aqui (ver
// CoverageExplanation, que a reconstrói só para a turma inteira, a partir de
// dados que este payload não carrega), por isso o texto fica deliberadamente
// modesto em vez de sugerir uma verificação que não pode de facto ser feita
// nesta página.
const COVERAGE_HINT: Record<Coverage, string> = {
    complete: 'Não foram detetados elementos de avaliação previstos em falta para o período analisado.',
    partial: 'O resultado é calculado com parte dos elementos previstos.',
    none: 'Ainda não existem elementos avaliados. A ausência de resultado não é um resultado negativo.',
};

/** A decision, or the sentence that says there is none. Never the proposal (§17). */
const assignedLabel = computed(() => {
    const classification = props.headline.classification;
    const decided = classification?.status === 'confirmed' || classification?.status === 'published';

    if (!decided) {
        return null;
    }

    return classification?.final?.code ?? classification?.final_value ?? null;
});

// ------------------------------------------------------- gráfico principal

const withValue = computed(() => props.moments.filter((moment) => moment.value !== null));

const hasChart = computed(() => withValue.value.length >= 2);

const evolutionChart = computed<ChartConfiguration>(() => {
    const chrome = chromeColours();

    return {
        type: 'line',
        data: {
            labels: props.moments.map((moment) => moment.label),
            datasets: [
                {
                    data: props.moments.map((moment) => (moment.value === null ? null : Number(moment.value))),
                    borderColor: '#4f46e5',
                    backgroundColor: '#4f46e5',
                    borderWidth: 2.5,
                    // A photograph is drawn as a square and a period as a
                    // circle: two kinds of moment, told apart without colour.
                    pointStyle: props.moments.map((moment) => (moment.kind === 'interim' ? 'rect' : 'circle')),
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: chrome.surface,
                    pointBorderColor: '#4f46e5',
                    pointBorderWidth: 2.5,
                    tension: 0.36,
                    // NEVER spanGaps. A period with no result is a gap in the
                    // line, and joining across it would draw a value nobody has
                    // (§24, §50).
                    spanGaps: false,
                },
            ],
        },
        options: { scales: { x: categoryAxis(chrome), y: percentAxis(chrome) } },
    } as ChartConfiguration;
});

const evolutionTooltip = (index: number): TooltipContent | null => {
    const moment = props.moments[index];

    if (moment === undefined) {
        return null;
    }

    const rows: TooltipContent['rows'] = [
        {
            label: moment.reading === 'accumulated' ? 'Média Ponderada Acumulada' : 'Média Ponderada',
            value: moment.value === null ? '—' : pct(moment.value),
            strong: true,
        },
        { label: 'Cobertura', value: COVERAGE_LABEL[moment.coverage] },
    ];

    return {
        title: moment.label,
        subtitle: moment.kind === 'interim'
            ? `Avaliação intercalar${moment.date ? ` · ${longDate(moment.date)}` : ''}`
            : 'Fim do período',
        rows,
        footer: moment.before_enrolment
            ? 'O aluno ainda não integrava a turma neste momento.'
            : moment.value === null
              ? 'Sem resultado apurado neste momento.'
              : undefined,
    };
};

const chartRows = computed(() =>
    props.moments.map((moment) => [
        moment.label,
        moment.kind === 'interim' ? 'Avaliação intercalar' : 'Fim do período',
        moment.value === null ? '—' : pct(moment.value),
        COVERAGE_LABEL[moment.coverage],
    ]),
);

// ------------------------------------------------------------------ domínios

/**
 * The widest bar sets the scale, so the row lengths are comparable to each
 * other rather than to an invisible 100 that nobody reaches.
 */
const domainMax = computed(() => {
    const values = props.domains.rows
        .map((row) => (row.accumulated_average === null ? 0 : Number(row.accumulated_average)))
        .filter((value) => value > 0);

    return values.length === 0 ? 100 : Math.max(...values, 1);
});

function barWidth(row: DomainRow): string {
    if (row.accumulated_average === null) {
        return '0%';
    }

    return `${Math.max(2, (Number(row.accumulated_average) / domainMax.value) * 100)}%`;
}

const highlighted = computed(() => {
    const { highest, lowest, largest_rise: rise, largest_fall: fall } = props.domains.highlights;

    return [
        highest ? { label: 'Resultado mais elevado', name: highest.name, value: highest.value, kind: 'result' as const, direction: null } : null,
        lowest ? { label: 'Resultado mais baixo', name: lowest.name, value: lowest.value, kind: 'result' as const, direction: null } : null,
        rise ? { label: 'Maior subida', name: rise.name, value: rise.value, kind: 'evolution' as const, direction: 'up' as const } : null,
        fall ? { label: 'Maior descida', name: fall.name, value: fall.value, kind: 'evolution' as const, direction: 'down' as const } : null,
    ].filter((entry): entry is NonNullable<typeof entry> => entry !== null);
});

const currentStrengths = computed(() => props.strengths.filter((row) => row.key === 'highest_domain'));
const progressingStrengths = computed(() => props.strengths.filter((row) => row.key === 'largest_rise'));
const otherStrengths = computed(() => props.strengths.filter((row) => !['highest_domain', 'largest_rise'].includes(row.key)));

// ------------------------------------------------------------------ registos

const kindFilter = ref<string | null>(null);

const visibleRecords = computed(() =>
    kindFilter.value === null
        ? props.records.rows
        : props.records.rows.filter((row) => row.kind === kindFilter.value),
);

const hasAnything = computed(
    () =>
        withValue.value.length > 0
        || props.classifications.some((row) => row.is_decided)
        || props.selfAssessments.some((row) => row.self_assessment !== null)
        || props.records.total > 0
        || props.interventions.total > 0,
);

// -------------------------------------------------- estado 360º (Pro)

/**
 * A small, calm state model — no árvore de Natal. Attention states use the
 * amber the rest of this page already reserves for it; positive states use
 * the same emerald `trendClass()` uses; everything else, including every
 * neutral "sem evidência", stays in the muted default (§1 of the Pro brief).
 */
const ATTENTION_STATES = new Set([
    'abaixo_da_turma', 'descida', 'consistente_a_descer', 'a_reforcar', 'a_acompanhar',
    'revisao_pendente', 'foco_na_consolidacao',
]);
const POSITIVE_STATES = new Set([
    'acima_da_turma', 'subida', 'consistente_a_subir', 'consistente', 'positivo',
    'identificados', 'em_progressao', 'consolidado_com_margem', 'em_curso',
]);

function evolutionAfterStrategy(ulid: string): EvolutionAfterStrategy | null {
    return props.pro?.evolutionAfterStrategy.find((row) => row.ulid === ulid) ?? null;
}

function dimensionClass(state: string): string {
    if (ATTENTION_STATES.has(state)) {
        return 'border-amber-500/30 bg-amber-500/5';
    }

    if (POSITIVE_STATES.has(state)) {
        return 'border-emerald-500/30 bg-emerald-500/5';
    }

    return 'border-border';
}

/** «a, b e c» — no comma before the last item, as Portuguese does not use one. */
function joinPortuguese(items: string[]): string {
    if (items.length <= 1) {
        return items[0] ?? '';
    }

    return `${items.slice(0, -1).join(', ')} e ${items[items.length - 1]}`;
}

/**
 * The Estado 360º intro names the eight dimensions actually rendered below
 * it, pulled from their own `label` — the source of truth — rather than
 * retyped by hand, so the sentence and the cards can never drift apart.
 */
function estado360Intro(dimensions: Dimension[]): string {
    const names = dimensions.map((dimension) => dimension.label.charAt(0).toLowerCase() + dimension.label.slice(1));

    return `Síntese integrada de ${joinPortuguese(names)}. Quando não existem dados suficientes para uma dimensão, o Lapispro assinala essa limitação.`;
}

// -------------------------------------------------------- preparar conversa

const showConversationPrep = ref(false);

// ------------------------------------------------------- sugestão de estratégia (IA)

const suggestion = ref(props.aiSuggestion);
const suggestionError = ref(props.aiSuggestionError);
const requestingSuggestion = ref(false);
const firstPurposeSuggestion = props.aiPurposeOptions
    .map((option) => props.aiPurposeSuggestions[option.value])
    .find((entry): entry is AiPurposeSuggestion => entry !== null && entry !== undefined);
const selectedPurpose = ref(firstPurposeSuggestion?.purpose ?? props.aiPurposeOptions[0]?.value ?? 'recovery');
const selectedDomainId = ref<number | null>(firstPurposeSuggestion?.domain_id ?? props.domains.rows[0]?.domain_id ?? null);
const teacherObjective = ref('');

watch(() => props.aiSuggestion, (value) => {
    suggestion.value = value;

    if (value) {
        selectedPurpose.value = value.purpose;
        selectedDomainId.value = value.domain_id;
    }
});
watch(() => props.aiSuggestionError, (value) => {
    suggestionError.value = value;
});

function choosePurposeSuggestion(startingPoint: AiPurposeSuggestion | null | undefined): void {
    if (!startingPoint) {
        return;
    }

    selectedPurpose.value = startingPoint.purpose;
    selectedDomainId.value = startingPoint.domain_id;
}

function requestSuggestion() {
    if (selectedDomainId.value === null || selectedPurpose.value === '') {
        suggestionError.value = { message: 'Escolha uma finalidade e um domínio.' };

        return;
    }

    requestingSuggestion.value = true;
    suggestionError.value = null;

    router.post(props.links.suggestStrategy, {
        domain_id: selectedDomainId.value,
        purpose: selectedPurpose.value,
        teacher_objective: teacherObjective.value.trim() || null,
    }, {
        preserveScroll: true,
        onError: (errors) => {
            suggestionError.value = { message: String(Object.values(errors)[0] ?? 'Não foi possível gerar a sugestão.') };
        },
        onFinish: () => {
            requestingSuggestion.value = false;
        },
    });
}

function dismissSuggestion() {
    suggestion.value = null;
    suggestionError.value = null;
}

/**
 * «Adicionar estratégia» / «Adaptar sugestão» — either way, this pre-fills
 * the ordinary creation form and stops. The teacher still submits it, still
 * reads it first, and may change anything before doing so (§13 of the AI
 * brief): «adaptar» is not a different action, it is the same link, because
 * every field the form opens on is already editable.
 */
function addSuggestion(row: AiSuggestionRow): string {
    const params = new URLSearchParams({ aluno: suggestion.value?.enrollment_ulid ?? '' });

    if (suggestion.value?.domain_id) {
        params.set('dominio', String(suggestion.value.domain_id));
    }

    if (row.name) {
        params.set('estrategia', row.name);
    }

    if (row.strategy) {
        params.set('aplicacao', row.strategy);
    }

    if (row.objective) {
        params.set('objetivo', row.objective);
    }

    if (row.purpose) {
        params.set('finalidade', row.purpose);
    }

    const frequency = [row.frequency, row.duration].filter(Boolean).join(' · ');

    if (frequency) {
        params.set('frequencia', frequency);
    }

    if (row.tracking_indicator) {
        params.set('indicador', row.tracking_indicator);
    }

    if (row.review_suggestion) {
        params.set('revisao', row.review_suggestion);
    }

    return `/classes/${props.schoolClass.ulid}/interventions?${params.toString()}`;
}

const PURPOSE_LABEL: Record<string, string> = {
    recovery: 'Recuperação',
    consolidation: 'Consolidação',
    improvement: 'Melhoria',
};
</script>

<template>
    <Head :title="`Acompanhamento — ${student.name}`" />

    <div class="mx-auto w-full max-w-5xl space-y-6 p-4">
        <!-- ------------------------------------------------ 1. quem, e onde -->
        <header class="space-y-3">
            <Heading
                :title="student.name"
                :description="`${student.class_number ? `N.º ${student.class_number} · ` : ''}${schoolClass.label} · ${schoolClass.subject} · ${schoolClass.academic_year}`"
            />

            <div class="flex flex-wrap items-center gap-2 text-xs">
                <Link
                    :href="`/classes/${schoolClass.ulid}/evolucao`"
                    class="rounded-full border border-border px-3 py-1 hover:bg-muted/40"
                >
                    Outro aluno da turma
                </Link>

                <!-- A discreet action, never a document engine of its own —
                     opens the print route in a new tab, with no interactive
                     chrome expected to end up on the printed page (§5, §6). -->
                <a
                    :href="printHref"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-1.5 rounded-full border border-border px-3 py-1 hover:bg-muted/40"
                >
                    <Printer class="size-3.5" />
                    {{ printLabel }}
                </a>

                <!-- Pro: a synthesis of what is already computed below, never
                     a document and never a second round-trip (§13, §9 of the
                     Pro brief). -->
                <button
                    v-if="pro"
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-full border border-primary/40 px-3 py-1 text-primary hover:bg-primary/5"
                    @click="showConversationPrep = !showConversationPrep"
                >
                    <MessageCircle class="size-3.5" />
                    Preparar conversa
                </button>

                <span
                    v-if="student.is_late_entry"
                    class="inline-flex items-center gap-1.5 rounded-full bg-muted px-3 py-1 text-muted-foreground"
                >
                    <CalendarClock class="size-3.5" />
                    Integrou a turma após o início do ano letivo
                </span>

                <!-- Shown, never hidden. A student who left still has a year
                     and it is still true (§26). -->
                <span
                    v-if="!student.is_current"
                    class="inline-flex items-center gap-1.5 rounded-full bg-amber-500/10 px-3 py-1 text-amber-800 dark:text-amber-400"
                >
                    <UserMinus class="size-3.5" />
                    Já não integra a turma
                    <template v-if="student.status_reason"> — {{ student.status_reason }}</template>
                </span>
            </div>
        </header>

        <p
            v-if="!schoolClass.has_profile"
            class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm text-amber-800 dark:text-amber-400"
        >
            Esta turma ainda não tem perfil de avaliação, pelo que não existem resultados apurados.
        </p>

        <!-- ------------------------------------------------ a página vazia -->
        <div v-if="!hasAnything" :class="CARD">
            <p class="text-sm text-muted-foreground">
                Ainda não existem resultados suficientes para apresentar a evolução deste aluno.
            </p>
            <p class="mt-2 text-sm text-muted-foreground">
                Assim que houver elementos avaliados, classificações atribuídas, autoavaliações ou registos, o
                percurso aparece aqui.
            </p>
        </div>

        <template v-else>
            <!-- Pro: «Preparar conversa» — a synthesis, not a document. Closes
                 on its own trigger; nothing here is exported or saved. -->
            <section v-if="pro && showConversationPrep" :class="CARD" aria-labelledby="preparar-conversa">
                <div class="flex items-start justify-between gap-3">
                    <h2 id="preparar-conversa" class="flex items-center gap-2 text-sm font-semibold">
                        <MessageCircle class="size-4" />
                        Preparar conversa
                    </h2>
                    <button type="button" class="text-xs text-muted-foreground hover:underline" @click="showConversationPrep = false">
                        Fechar
                    </button>
                </div>

                <dl class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Situação atual</dt>
                        <dd class="mt-1 text-sm">
                            {{ pro.conversationPrep.situation.value === null ? 'Sem resultado apurado' : `${pro.conversationPrep.situation.reading_label}: ${pct(pro.conversationPrep.situation.value)}` }}
                            <span class="text-xs text-muted-foreground">({{ COVERAGE_LABEL[pro.conversationPrep.situation.coverage] }})</span>
                        </dd>
                    </div>

                    <div>
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Potencialidades</dt>
                        <dd class="mt-1 text-sm">{{ pro.conversationPrep.potentialities ?? 'Sem elementos suficientes.' }}</dd>
                    </div>

                    <div v-if="pro.conversationPrep.difficulties.length > 0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Dificuldades</dt>
                        <dd class="mt-1 space-y-1 text-sm">
                            <p v-for="(item, index) in pro.conversationPrep.difficulties" :key="index">{{ item }}</p>
                        </dd>
                    </div>

                    <div v-if="pro.conversationPrep.strengths.length > 0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Pontos fortes</dt>
                        <dd class="mt-1 space-y-1 text-sm">
                            <p v-for="(item, index) in pro.conversationPrep.strengths" :key="index">{{ item }}</p>
                        </dd>
                    </div>

                    <div v-if="pro.conversationPrep.changes.length > 0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">O que mudou</dt>
                        <dd class="mt-1 space-y-1 text-sm">
                            <p v-for="(item, index) in pro.conversationPrep.changes" :key="index">{{ item }}</p>
                        </dd>
                    </div>

                    <div v-if="pro.conversationPrep.ongoingStrategies.length > 0">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Estratégias em curso</dt>
                        <dd class="mt-1 space-y-1 text-sm">
                            <p v-for="(row, index) in pro.conversationPrep.ongoingStrategies" :key="index">
                                {{ row.title ?? 'Intervenção' }}
                                <span v-if="row.purpose_label" class="text-xs text-muted-foreground">— {{ row.purpose_label }}</span>
                            </p>
                        </dd>
                    </div>

                    <div v-if="pro.conversationPrep.topicsToDiscuss.length > 0" class="sm:col-span-2">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Tópicos a abordar</dt>
                        <dd class="mt-1 space-y-1 text-sm">
                            <p v-for="(item, index) in pro.conversationPrep.topicsToDiscuss" :key="index">{{ item }}</p>
                        </dd>
                    </div>
                </dl>
            </section>

            <!-- ------------------------------------------- 2. onde está -->
            <section :class="CARD" aria-labelledby="onde-esta">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 id="onde-esta" class="text-sm font-semibold">Onde está</h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">{{ reading.caption }}</p>
                    </div>

                    <!-- §9: the toggle changes the result, the chart and the
                         comparison — and nothing else on the page. -->
                    <div v-if="reading.has_toggle" class="flex rounded-lg border border-border p-0.5" role="group" aria-label="Ler os resultados como">
                        <button
                            type="button"
                            class="rounded-md px-3 py-1 text-xs"
                            :class="reading.kind === 'accumulated' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                            :aria-pressed="reading.kind === 'accumulated'"
                            @click="setReading('accumulated')"
                        >
                            Avaliação contínua
                        </button>
                        <button
                            type="button"
                            class="rounded-md px-3 py-1 text-xs"
                            :class="reading.kind === 'period' ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted/40'"
                            :aria-pressed="reading.kind === 'period'"
                            @click="setReading('period')"
                        >
                            Só neste período
                        </button>
                    </div>
                </div>

                <!-- §47: four figures, and not one more. -->
                <dl class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div :class="INSET" class="p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ reading.label }}
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ headline.value === null ? '—' : pct(headline.value) }}
                        </dd>
                        <dd v-if="headline.band?.label" class="mt-0.5 text-xs text-muted-foreground">
                            {{ headline.band.label }}
                        </dd>
                    </div>

                    <div :class="INSET" class="p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Classificação atribuída
                        </dt>
                        <dd v-if="assignedLabel" class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ assignedLabel }}
                        </dd>
                        <dd v-else class="mt-1 text-sm text-muted-foreground">Sem classificação atribuída</dd>
                    </div>

                    <div :class="INSET" class="p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Autoavaliação
                        </dt>
                        <dd v-if="headline.self_assessment?.code" class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ headline.self_assessment.code }}
                        </dd>
                        <dd v-else class="mt-1 text-sm text-muted-foreground">Sem autoavaliação</dd>
                    </div>

                    <div :class="INSET" class="p-4">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            Cobertura do cálculo
                        </dt>
                        <dd class="mt-1 text-sm font-medium">{{ COVERAGE_LABEL[headline.coverage] }}</dd>
                        <dd class="mt-0.5 text-xs text-muted-foreground">{{ COVERAGE_HINT[headline.coverage] }}</dd>
                    </div>
                </dl>

                <!-- §38: deterministic, and readable against the figures above. -->
                <p v-if="narrative" class="mt-4 border-t border-border/60 pt-4 text-sm leading-relaxed">
                    {{ narrative }}
                </p>
            </section>

            <!-- ------------------------- 2b. atenção e pontos fortes (nunca só a primeira) -->
            <div v-if="factualAlerts.length > 0 || strengths.length > 0 || (pro && (pro.analyticalAlerts.length > 0 || pro.positiveSignals.length > 0))" class="grid gap-6 lg:grid-cols-2">
                <section :class="CARD" aria-labelledby="alertas">
                    <h2 id="alertas" class="flex items-center gap-2 text-sm font-semibold">
                        <AlertTriangle class="size-4" />
                        Atenção
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">Factos, não juízos — cada frase remete para um registo já existente.</p>

                    <ul v-if="factualAlerts.length > 0" class="mt-3 space-y-2 text-sm">
                        <li v-for="alert in factualAlerts" :key="alert.key">{{ alert.sentence }}</li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Sem alertas factuais no período analisado.</p>

                    <!-- Pro: a interpretação, nunca no lugar do facto — ao lado dele. -->
                    <template v-if="pro && pro.analyticalAlerts.length > 0">
                        <h3 class="mt-4 flex items-center gap-1.5 border-t border-border/60 pt-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            <Sparkles class="size-3.5" />
                            Leitura analítica
                        </h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li v-for="alert in pro.analyticalAlerts" :key="alert.key">{{ alert.sentence }}</li>
                        </ul>
                    </template>
                </section>

                <section :class="CARD" aria-labelledby="pontos-fortes">
                    <h2 id="pontos-fortes" class="flex items-center gap-2 text-sm font-semibold">
                        <Star class="size-4" />
                        Pontos fortes
                    </h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">O que já está a resultar, e não apenas o que falta.</p>

                    <div v-if="strengths.length > 0" class="mt-3 space-y-3 text-sm">
                        <div v-if="currentStrengths.length > 0">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Ponto forte atual</h3>
                            <ul class="mt-1 space-y-1">
                                <li v-for="strength in currentStrengths" :key="strength.key">{{ strength.sentence }}</li>
                            </ul>
                        </div>
                        <div v-if="progressingStrengths.length > 0">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Domínio em progressão</h3>
                            <ul class="mt-1 space-y-1">
                                <li v-for="strength in progressingStrengths" :key="strength.key">{{ strength.sentence }}</li>
                            </ul>
                        </div>
                        <div v-if="otherStrengths.length > 0">
                            <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Outros sinais positivos</h3>
                            <ul class="mt-1 space-y-1">
                                <li v-for="strength in otherStrengths" :key="strength.key">{{ strength.sentence }}</li>
                            </ul>
                        </div>
                    </div>
                    <p v-else class="mt-3 text-sm text-muted-foreground">Ainda não existem elementos suficientes para identificar pontos fortes.</p>

                    <template v-if="pro && pro.positiveSignals.length > 0">
                        <h3 class="mt-4 flex items-center gap-1.5 border-t border-border/60 pt-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            <Sparkles class="size-3.5" />
                            Sinais positivos
                        </h3>
                        <ul class="mt-2 space-y-2 text-sm">
                            <li v-for="signal in pro.positiveSignals" :key="signal.key">{{ signal.sentence }}</li>
                        </ul>
                    </template>
                </section>
            </div>

            <!-- ------------------------------------------------ 2c. estado 360º (Pro) -->
            <section v-if="pro" :class="CARD" aria-labelledby="estado-360">
                <h2 id="estado-360" class="flex items-center gap-2 text-sm font-semibold">
                    <Sparkles class="size-4" />
                    Estado 360º
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    {{ estado360Intro(pro.estado360.dimensions) }}
                </p>

                <dl class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                    <div v-for="dimension in pro.estado360.dimensions" :key="dimension.key" class="rounded-lg border p-3" :class="dimensionClass(dimension.state)">
                        <dt class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ dimension.label }}</dt>
                        <dd class="mt-1 text-sm font-medium">{{ dimension.state_label }}</dd>
                        <dd class="mt-1 text-xs text-muted-foreground">{{ dimension.detail }}</dd>
                    </div>
                </dl>
            </section>

            <!-- ------------------------------------------ 3. como evoluiu -->
            <section :class="CARD" aria-labelledby="como-evoluiu">
                <h2 id="como-evoluiu" class="text-sm font-semibold">Como evoluiu</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Momentos reais do ano letivo. As avaliações intercalares mostram o que era verdade nesse dia.
                </p>

                <div v-if="hasChart" class="mt-4">
                    <StatChart
                        :config="evolutionChart"
                        :tooltip="evolutionTooltip"
                        :summary="`${reading.label} de ${student.name} em cada momento do ano letivo.`"
                        :headers="['Momento', 'Tipo', reading.label, 'Cobertura']"
                        :rows="chartRows"
                        height-class="h-72"
                    />
                </div>

                <p v-else class="mt-4 text-sm text-muted-foreground">
                    Ainda não existem dois momentos com resultado apurado, pelo que não há evolução para
                    representar.
                </p>

                <!-- §37: facts, and the list is allowed to be short. -->
                <div v-if="sinceLast" class="mt-5 border-t border-border/60 pt-4">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ sinceLast.from_label }} → {{ sinceLast.to_label }}
                    </h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        <li v-if="sinceLast.from !== null && sinceLast.to !== null">
                            {{ reading.label }}:
                            <span class="tabular-nums">{{ pct(sinceLast.from) }}</span>
                            <span class="mx-1 text-muted-foreground">→</span>
                            <span class="font-medium tabular-nums">{{ pct(sinceLast.to) }}</span>
                            <span class="ml-1 text-xs text-muted-foreground">
                                ({{ sinceLast.from_label }} → {{ sinceLast.to_label }})
                            </span>
                        </li>
                        <li v-if="sinceLast.classification_from?.code && sinceLast.classification_to?.code">
                            Classificação atribuída:
                            <span class="tabular-nums">{{ sinceLast.classification_from.code }}</span>
                            <span class="mx-1 text-muted-foreground">→</span>
                            <span class="font-medium tabular-nums">{{ sinceLast.classification_to.code }}</span>
                        </li>
                    </ul>

                    <details class="mt-3 rounded-lg border border-border/70 px-3 py-2">
                        <summary class="cursor-pointer text-xs font-medium">Base da comparação</summary>
                        <dl class="mt-2 grid gap-2 text-xs sm:grid-cols-2">
                            <div>
                                <dt class="font-medium">Momento anterior</dt>
                                <dd class="text-muted-foreground">
                                    {{ sinceLast.from_label }}
                                    <template v-if="sinceLast.from_date"> · terminou em {{ longDate(sinceLast.from_date) }}</template>
                                    <template v-if="sinceLast.from !== null"> · {{ pct(sinceLast.from) }}</template>
                                </dd>
                            </div>
                            <div>
                                <dt class="font-medium">Momento atual</dt>
                                <dd class="text-muted-foreground">
                                    {{ sinceLast.to_label }}
                                    <template v-if="sinceLast.to_date"> · terminou em {{ longDate(sinceLast.to_date) }}</template>
                                    <template v-if="sinceLast.to !== null"> · {{ pct(sinceLast.to) }}</template>
                                </dd>
                            </div>
                        </dl>
                    </details>
                </div>

                <!-- §40: two figures and the distance between them. No ranking. -->
                <div v-if="classComparison" class="mt-5 border-t border-border/60 pt-4">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Em relação à turma
                    </h3>
                    <p class="mt-2 text-sm">
                        Aluno <span class="font-medium tabular-nums">{{ pct(classComparison.student) }}</span>
                        <span class="mx-2 text-muted-foreground">·</span>
                        Turma <span class="font-medium tabular-nums">{{ pct(classComparison.class) }}</span>
                        <span class="ml-2 text-xs text-muted-foreground">
                            ({{ formatPoints(classComparison.difference) }} p.p.)
                        </span>
                    </p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Média dos {{ classComparison.students_with_result }} alunos com resultado apurado.
                    </p>
                </div>

                <!-- Pro: «O que mudou?» — não é uma segunda cronologia, é uma
                     lista curta em cima das mesmas figuras de sinceLast (§6
                     do brief Pro). Um estado vazio real, nunca um array vazio
                     deixado à interpretação da interface. -->
                <div v-if="pro" class="mt-5 border-t border-border/60 pt-4">
                    <h3 class="flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        <Sparkles class="size-3.5" />
                        O que mudou
                    </h3>
                    <ul v-if="pro.whatChanged.items.length > 0" class="mt-2 space-y-1 text-sm">
                        <li v-for="item in pro.whatChanged.items" :key="item.key">{{ item.sentence }}</li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-muted-foreground">{{ pro.whatChanged.empty_sentence }}</p>
                </div>

                <div v-if="recentInstruments.length > 0" class="mt-5 border-t border-border/60 pt-4">
                    <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        Últimas avaliações
                    </h3>
                    <ul class="mt-2 space-y-2 text-sm">
                        <li v-for="instrument in recentInstruments" :key="instrument.ulid" class="flex flex-wrap items-baseline justify-between gap-2">
                            <span>
                                <span class="text-xs tabular-nums text-muted-foreground">{{ shortDate(instrument.applied_on) }}</span>
                                <span class="ml-2">{{ instrument.title }}</span>
                                <span v-if="instrument.type" class="text-xs text-muted-foreground"> · {{ instrument.type }}</span>
                            </span>
                            <span class="flex flex-wrap items-baseline gap-2 text-xs">
                                <span v-if="instrument.result !== null" class="font-medium tabular-nums">
                                    {{ pct(instrument.result) }}
                                    <span v-if="instrument.scale_label" class="font-normal text-muted-foreground"> · {{ instrument.scale_label }}</span>
                                </span>
                                <span v-else class="text-muted-foreground">Sem resultado individual</span>
                                <span
                                    v-if="instrument.evolution"
                                    :class="trendClass(instrument.evolution.direction)"
                                    class="tabular-nums"
                                >
                                    {{ arrow(instrument.evolution.direction) }}
                                    {{ instrument.evolution.direction === 'up' ? 'Subida' : instrument.evolution.direction === 'down' ? 'Descida' : 'Estável' }}
                                    · {{ formatPoints(instrument.evolution.points) }} p.p.
                                </span>
                                <span v-else class="text-muted-foreground">→ Sem comparação</span>
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <!-- ------------------------------------------------- 4. em quê -->
            <section v-if="domains.rows.length > 0" :class="CARD" aria-labelledby="em-que">
                <h2 id="em-que" class="text-sm font-semibold">Em que domínios</h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Resultado acumulado por domínio
                    <template v-if="sinceLast">
                        e variação entre {{ sinceLast.from_label }} e {{ sinceLast.to_label }}.
                    </template>
                    <template v-else>, ainda sem dois momentos comparáveis.</template>
                </p>

                <table class="mt-4 w-full text-sm">
                    <caption class="sr-only">
                        Resultado por domínio, variação e cobertura de {{ student.name }}.
                    </caption>
                    <thead>
                        <tr class="text-left text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                            <th scope="col" class="pb-2">Domínio</th>
                            <th scope="col" class="pb-2 text-right">Resultado</th>
                            <th scope="col" class="pb-2 text-right">Variação</th>
                            <th scope="col" class="pb-2 text-right">Cobertura</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        <tr v-for="row in domains.rows" :key="row.domain_id">
                            <th scope="row" class="py-2 pr-3 text-left font-normal">
                                <span class="block">{{ row.name }}</span>
                                <span class="mt-1 block h-1.5 w-full max-w-40 overflow-hidden rounded-full bg-muted">
                                    <span
                                        class="block h-full rounded-full bg-primary/70"
                                        :style="{ width: barWidth(row) }"
                                    ></span>
                                </span>
                            </th>
                            <td class="py-2 text-right tabular-nums">
                                <template v-if="row.accumulated_average !== null">
                                    {{ pct(row.accumulated_average) }}
                                    <span v-if="row.mention?.label" class="block text-xs text-muted-foreground">
                                        {{ row.mention.label }}
                                    </span>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">Sem elementos avaliados</span>
                            </td>
                            <!-- §22: «não comparável» rather than a zero. -->
                            <td class="py-2 text-right tabular-nums">
                                <span v-if="row.evolution" :class="trendClass(row.evolution.direction)">
                                    {{ arrow(row.evolution.direction) }}
                                    {{ formatPoints(row.evolution.points) }} p.p.
                                </span>
                                <span v-else class="text-xs text-muted-foreground">Não comparável</span>
                            </td>
                            <td class="py-2 text-right text-xs text-muted-foreground">
                                {{ COVERAGE_LABEL[row.coverage] }}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- §23: names a cell, and stops. -->
                <ul v-if="highlighted.length > 0" class="mt-4 flex flex-wrap gap-2 border-t border-border/60 pt-4">
                    <li
                        v-for="entry in highlighted"
                        :key="entry.label"
                        class="rounded-full bg-muted px-3 py-1 text-xs text-muted-foreground"
                    >
                        {{ entry.label }}:
                        <span class="font-medium text-foreground">{{ entry.name }}</span>
                        <span v-if="entry.kind === 'result'" class="ml-1 font-medium tabular-nums text-foreground">
                            {{ pct(entry.value) }}
                        </span>
                        <span v-else-if="entry.direction" class="ml-1 font-medium tabular-nums" :class="trendClass(entry.direction)">
                            {{ arrow(entry.direction) }}
                            {{ entry.direction === 'up' ? 'Subida' : 'Descida' }}
                            · {{ formatPoints(entry.value) }} p.p.
                        </span>
                    </li>
                </ul>
            </section>

            <!-- ---------------------------------------- 4b. potencialidades (Pro) -->
            <section
                v-if="pro && (pro.potentialities.narrative || pro.potentialities.strengths.length > 0 || pro.potentialities.progressing.length > 0 || pro.potentialities.next_step)"
                :class="CARD"
                aria-labelledby="potencialidades"
            >
                <h2 id="potencialidades" class="flex items-center gap-2 text-sm font-semibold">
                    <Sparkles class="size-4" />
                    Potencialidades
                </h2>
                <p class="mt-0.5 text-xs text-muted-foreground">
                    Linguagem prudente, por opção — nunca uma nota ou uma percentagem prevista.
                </p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <div class="rounded-lg border border-emerald-500/30 bg-emerald-500/5 p-3">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Pontos fortes</h3>
                        <ul v-if="pro.potentialities.strengths.length > 0" class="mt-1 space-y-1 text-sm">
                            <li v-for="item in pro.potentialities.strengths" :key="item.domain_id">{{ item.detail }}</li>
                        </ul>
                        <p v-else class="mt-1 text-xs text-muted-foreground">Sem ponto forte atual isolado.</p>
                    </div>
                    <div class="rounded-lg border border-primary/30 bg-primary/5 p-3">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Em progressão</h3>
                        <ul v-if="pro.potentialities.progressing.length > 0" class="mt-1 space-y-1 text-sm">
                            <li v-for="item in pro.potentialities.progressing" :key="item.domain_id">{{ item.detail }}</li>
                        </ul>
                        <p v-else class="mt-1 text-xs text-muted-foreground">Sem evolução recente isolada.</p>
                    </div>
                    <div class="rounded-lg border border-border p-3">
                        <h3 class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Próximo passo</h3>
                        <p class="mt-1 text-sm">{{ pro.potentialities.next_step ?? 'Sem elementos suficientes para formular um próximo passo.' }}</p>
                    </div>
                </div>
                <p v-if="pro.potentialities.narrative" class="mt-3 text-sm leading-relaxed">{{ pro.potentialities.narrative }}</p>
            </section>

            <!-- --------------------------- 5. a decisão, e o que o aluno disse -->
            <div class="grid gap-6 lg:grid-cols-2">
                <section :class="CARD" aria-labelledby="classificacoes">
                    <h2 id="classificacoes" class="text-sm font-semibold">Classificações atribuídas</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        A decisão do professor. A proposta do sistema aparece ao lado, quando difere.
                    </p>

                    <ul class="mt-4 space-y-2">
                        <li
                            v-for="row in classifications"
                            :key="row.period_id"
                            class="flex flex-wrap items-baseline justify-between gap-2 border-b border-border/60 pb-2 last:border-0"
                        >
                            <span class="text-sm">{{ row.period_label }}</span>
                            <span class="flex items-baseline gap-2 text-sm">
                                <template v-if="row.is_decided">
                                    <span class="text-lg font-semibold tabular-nums">
                                        {{ row.assigned?.code ?? row.assigned_value }}
                                    </span>
                                    <span v-if="row.assigned?.label" class="text-xs text-muted-foreground">
                                        {{ row.assigned.label }}
                                    </span>
                                    <!-- §16: named as a proposal, always. -->
                                    <span
                                        v-if="row.proposal?.label && row.differs_from_proposal"
                                        class="text-xs text-muted-foreground"
                                    >
                                        (proposta: {{ row.proposal.label }})
                                    </span>
                                </template>
                                <span v-else class="text-xs text-muted-foreground">Sem classificação atribuída</span>
                            </span>
                        </li>
                    </ul>
                </section>

                <section :class="CARD" aria-labelledby="autoavaliacao">
                    <h2 id="autoavaliacao" class="text-sm font-semibold">Autoavaliação</h2>
                    <p class="mt-0.5 text-xs text-muted-foreground">
                        O que o aluno disse sobre si, ao lado do que o professor decidiu.
                    </p>

                    <ul class="mt-4 space-y-2">
                        <li
                            v-for="row in selfAssessments"
                            :key="row.period_id"
                            class="flex flex-wrap items-baseline justify-between gap-2 border-b border-border/60 pb-2 last:border-0"
                        >
                            <span class="text-sm">{{ row.period_label }}</span>
                            <span class="flex items-baseline gap-3 text-sm">
                                <span class="text-xs text-muted-foreground">
                                    Auto
                                    <span class="ml-1 text-base font-semibold tabular-nums text-foreground">
                                        {{ row.self_assessment?.code ?? '—' }}
                                    </span>
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    Atribuída
                                    <span class="ml-1 text-base font-semibold tabular-nums text-foreground">
                                        {{ row.assigned?.code ?? '—' }}
                                    </span>
                                </span>
                                <!-- §28: says which way, and nothing about why. -->
                                <span v-if="row.comparison && row.comparison.direction !== 'same'" class="text-xs text-muted-foreground">
                                    {{ arrow(row.comparison.direction) }}
                                    {{ row.comparison.direction === 'above' ? 'acima' : 'abaixo' }}
                                </span>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- ---------------------------------- 6. o que aconteceu ao longo do tempo -->
            <section :class="CARD" aria-labelledby="registos">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="registos" class="flex items-center gap-2 text-sm font-semibold">
                            <NotebookPen class="size-4" />
                            Registos
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ records.total }} {{ records.total === 1 ? 'registo' : 'registos' }} sobre este aluno.
                        </p>
                    </div>

                    <Button variant="ghost" size="sm" as-child>
                        <a :href="links.records">
                            Abrir Registos
                            <ExternalLink class="size-3.5" />
                        </a>
                    </Button>
                </div>

                <!-- The filters are the kinds this student actually has, from
                     the system's own enum (§30). -->
                <div v-if="records.kinds.length > 1" class="mt-3 flex flex-wrap gap-1.5" role="group" aria-label="Filtrar registos por tipo">
                    <button
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="kindFilter === null ? 'border-primary bg-primary/10' : 'border-border text-muted-foreground hover:bg-muted/40'"
                        :aria-pressed="kindFilter === null"
                        @click="kindFilter = null"
                    >
                        Todos ({{ records.total }})
                    </button>
                    <button
                        v-for="kind in records.kinds"
                        :key="kind.value"
                        type="button"
                        class="rounded-full border px-3 py-1 text-xs"
                        :class="kindFilter === kind.value ? 'border-primary bg-primary/10' : 'border-border text-muted-foreground hover:bg-muted/40'"
                        :aria-pressed="kindFilter === kind.value"
                        @click="kindFilter = kind.value"
                    >
                        {{ kind.label }} ({{ kind.count }})
                    </button>
                </div>

                <ol v-if="visibleRecords.length > 0" class="mt-4 space-y-3">
                    <li v-for="row in visibleRecords" :key="row.ulid" class="flex gap-3">
                        <span class="w-14 shrink-0 pt-0.5 text-xs tabular-nums text-muted-foreground">
                            {{ shortDate(row.occurred_at) }}
                        </span>
                        <span class="min-w-0 flex-1 border-l border-border pl-3">
                            <span class="block text-xs font-medium">
                                {{ row.kind_label }}
                                <span v-if="row.domain" class="ml-1 font-normal text-muted-foreground">· {{ row.domain }}</span>
                                <span v-if="row.severity" class="ml-1 font-normal text-muted-foreground">· {{ row.severity }}</span>
                                <span v-if="row.homework_status" class="ml-1 font-normal text-muted-foreground">· {{ row.homework_status }}</span>
                                <span v-if="row.participation_level" class="ml-1 font-normal text-muted-foreground">· {{ row.participation_level }}</span>
                            </span>
                            <span class="mt-0.5 block text-sm text-muted-foreground">{{ row.description }}</span>
                        </span>
                    </li>
                </ol>

                <p v-else class="mt-4 text-sm text-muted-foreground">
                    {{ records.total === 0 ? 'Ainda não existem registos sobre este aluno.' : 'Nenhum registo deste tipo.' }}
                </p>
            </section>

            <section :class="CARD" aria-labelledby="intervencoes">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="intervencoes" class="flex items-center gap-2 text-sm font-semibold">
                            <HeartHandshake class="size-4" />
                            Estratégias e Medidas
                            <span
                                v-if="interventions.needing_review > 0"
                                class="inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-400"
                            >
                                {{ interventions.needing_review === 1 ? '1 revisão pendente' : `${interventions.needing_review} revisões pendentes` }}
                            </span>
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Inclui as já concluídas — a evolução precisa da história.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-1">
                        <Button v-if="links.newIntervention" variant="outline" size="sm" as-child>
                            <a :href="links.newIntervention">
                                <Plus class="size-3.5" />
                                Registar intervenção
                            </a>
                        </Button>
                        <Button variant="ghost" size="sm" as-child>
                            <a :href="links.interventions">
                                Abrir Estratégias e Medidas
                                <ExternalLink class="size-3.5" />
                            </a>
                        </Button>
                    </div>
                </div>

                <ol v-if="interventions.rows.length > 0" class="mt-4 space-y-3">
                    <li v-for="row in interventions.rows" :key="row.ulid" class="flex gap-3">
                        <span class="w-14 shrink-0 pt-0.5 text-xs tabular-nums text-muted-foreground">
                            {{ shortDate(row.started_on) }}
                        </span>
                        <span class="min-w-0 flex-1 border-l border-border pl-3">
                            <span class="block text-sm font-medium">{{ row.title }}</span>

                            <!-- Why it was created and what for, when the teacher
                                 said. An older intervention has neither, and shows
                                 neither — never «objetivo geral» (§4). -->
                            <span v-if="row.motive" class="mt-0.5 block text-xs text-muted-foreground">
                                Situação: {{ row.motive }}
                            </span>
                            <span v-if="row.objective" class="block text-xs text-muted-foreground">
                                Objetivo: {{ row.objective }}
                            </span>
                            <span v-if="row.tracking_indicator" class="block text-xs text-muted-foreground">
                                Indicador: {{ row.tracking_indicator }}
                            </span>

                            <span class="mt-0.5 block text-xs text-muted-foreground">
                                <!-- An untyped row shows no type. Never `legacy`,
                                     never a code (§33). Null purpose shows
                                     nothing — «não especificada» is not printed
                                     on every older row (§8). -->
                                <template v-if="row.type">{{ row.type }} · </template>
                                <template v-if="row.purpose_label">{{ row.purpose_label }} · </template>
                                {{ row.status }}
                                <template v-if="row.domain"> · {{ row.domain }}</template>
                                <template v-if="row.frequency"> · {{ row.frequency }}</template>
                                · {{ row.is_individual ? 'dirigida a este aluno' : 'dirigida à turma' }}
                            </span>

                            <span
                                v-if="row.needs_review"
                                class="mt-1 inline-flex items-center rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-800 dark:text-amber-400"
                            >
                                Revisão pendente
                            </span>

                            <!-- What the TEACHER observed. Placed beside the
                                 intervention and never beside a result: a rise in
                                 April and an intervention in March are two facts,
                                 and this page joins them with a date and nothing
                                 else (§31, §35). -->
                            <span v-if="row.effectiveness || row.followup_count > 0" class="mt-1 block text-xs">
                                <span v-if="row.effectiveness" class="rounded-full bg-muted px-2 py-0.5 text-muted-foreground">
                                    {{ row.effectiveness }}
                                </span>
                                <span v-if="row.followup_count > 0" class="ml-1 text-muted-foreground">
                                    {{ row.followup_count }}
                                    {{ row.followup_count === 1 ? 'acompanhamento' : 'acompanhamentos' }}
                                </span>
                            </span>

                            <!-- Pro: correlação no tempo, nunca causalidade
                                 (§7, §35 de BuildStudentProgress). -->
                            <span v-if="pro && evolutionAfterStrategy(row.ulid)" class="mt-1 block text-xs text-muted-foreground">
                                <Sparkles class="inline size-3" />
                                {{ evolutionAfterStrategy(row.ulid)?.sentence }}
                            </span>
                        </span>
                    </li>
                </ol>

                <p v-else class="mt-4 text-sm text-muted-foreground">
                    Ainda não existem estratégias ou medidas registadas para este aluno.
                </p>
            </section>

            <!-- Sugestões pedagógicas (IA) — §13. Gated on the server: a
                 Base organization, or a Pro one with no engine configured,
                 sees the honest unavailable state, never a button that can
                 only fail (§41 of the AI brief). A proposal only — nothing
                 here writes an Intervention. -->
            <section :class="CARD" aria-labelledby="sugestao-ia">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 id="sugestao-ia" class="flex items-center gap-2 text-sm font-semibold">
                            <Sparkles class="size-4" />
                            Sugestões pedagógicas (IA)
                        </h2>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            Escolha a finalidade e o domínio. A decisão é sempre do professor e nunca cria um registo automático.
                        </p>
                    </div>
                </div>

                <p v-if="!ai.available" class="mt-3 rounded-lg bg-muted/40 p-3 text-xs text-muted-foreground">
                    {{
                        ai.reason === 'plan'
                            ? 'O apoio de IA não está incluído no plano desta organização.'
                            : 'O apoio de IA não está configurado nesta instalação.'
                    }}
                </p>

                <div class="mt-4 grid gap-2 sm:grid-cols-3">
                    <button
                        v-for="option in aiPurposeOptions"
                        :key="option.value"
                        type="button"
                        class="rounded-lg border p-3 text-left text-xs"
                        :class="selectedPurpose === option.value && selectedDomainId === aiPurposeSuggestions[option.value]?.domain_id
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:bg-muted/30'"
                        :disabled="!aiPurposeSuggestions[option.value]"
                        @click="choosePurposeSuggestion(aiPurposeSuggestions[option.value])"
                    >
                        <template v-if="aiPurposeSuggestions[option.value]">
                            <span class="block font-medium text-foreground">
                                {{ aiPurposeSuggestions[option.value]?.domain }} · {{ option.label }}
                            </span>
                            <span class="mt-0.5 block text-muted-foreground">— {{ aiPurposeSuggestions[option.value]?.justification }}</span>
                        </template>
                        <template v-else>
                            <span class="block font-medium">{{ option.label }}</span>
                            <span class="mt-1 block text-muted-foreground">Sem combinação inicial segura.</span>
                        </template>
                    </button>
                </div>

                <div v-if="ai.available" class="mt-4 space-y-3 rounded-lg border border-border p-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="text-xs">
                            <span class="mb-1 block text-muted-foreground">Finalidade</span>
                            <select v-model="selectedPurpose" class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm">
                                <option v-for="option in aiPurposeOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                            </select>
                        </label>
                        <label class="text-xs">
                            <span class="mb-1 block text-muted-foreground">Domínio</span>
                            <select v-model="selectedDomainId" class="w-full rounded-md border border-border bg-background px-2 py-1.5 text-sm">
                                <option :value="null" disabled>Escolher…</option>
                                <option v-for="domain in domains.rows" :key="domain.domain_id" :value="domain.domain_id">{{ domain.name }}</option>
                            </select>
                        </label>
                    </div>
                    <label class="block text-xs">
                        <span class="mb-1 block text-muted-foreground">Objetivo do professor (opcional)</span>
                        <textarea
                            v-model="teacherObjective"
                            rows="2"
                            maxlength="1000"
                            class="w-full rounded-md border border-border bg-background px-3 py-2 text-sm"
                            placeholder="Ex.: consolidar a organização e a coesão textual. Não inclua dados identificáveis."
                        ></textarea>
                    </label>
                    <Button variant="outline" size="sm" :disabled="requestingSuggestion" @click="requestSuggestion">
                        <Sparkles class="size-3.5" />
                        {{ requestingSuggestion ? 'A gerar…' : 'Gerar sugestões' }}
                    </Button>
                </div>

                <p v-if="suggestionError" class="mt-3 rounded-lg bg-amber-500/10 p-3 text-xs text-amber-800 dark:text-amber-400">
                    {{ suggestionError.message }}
                </p>

                <div v-if="suggestion" class="mt-4 space-y-3 border-t border-border/60 pt-4">
                    <p class="text-xs text-muted-foreground">
                        {{ suggestion.purpose_label }} · Domínio:
                        <span class="font-medium text-foreground">{{ suggestion.domain }}</span>
                    </p>

                    <div
                        v-for="(row, index) in suggestion.suggestions"
                        :key="index"
                        class="space-y-1.5 rounded-lg border border-border p-3 text-sm"
                    >
                        <p class="font-medium">{{ row.name ?? 'Estratégia sem nome sugerido' }}</p>
                        <p class="text-xs font-medium text-muted-foreground">{{ PURPOSE_LABEL[row.purpose] ?? row.purpose }}</p>
                        <p><span class="text-xs text-muted-foreground">Objetivo:</span> {{ row.objective }}</p>
                        <p><span class="text-xs text-muted-foreground">Aplicação:</span> {{ row.strategy }}</p>
                        <p class="text-xs text-muted-foreground">
                            <template v-if="row.frequency">{{ row.frequency }}</template>
                            <template v-if="row.duration"> · {{ row.duration }}</template>
                            <template v-if="row.review_suggestion"> · rever {{ row.review_suggestion }}</template>
                        </p>
                        <p v-if="row.tracking_indicator" class="text-xs text-muted-foreground">Indicador: {{ row.tracking_indicator }}</p>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <!-- «Adicionar» e «Adaptar» abrem o mesmo formulário
                                 pré-preenchido e editável — a diferença é só a
                                 intenção com que o professor o abre. -->
                            <Button size="sm" as-child>
                                <a :href="addSuggestion(row)">Adicionar como estratégia</a>
                            </Button>
                            <Button variant="outline" size="sm" as-child>
                                <a :href="addSuggestion(row)">Adaptar</a>
                            </Button>
                            <Button variant="outline" size="sm" :disabled="requestingSuggestion" @click="requestSuggestion">
                                Gerar outra sugestão
                            </Button>
                            <Button variant="ghost" size="sm" @click="dismissSuggestion">Ignorar</Button>
                        </div>
                    </div>
                </div>
            </section>

            <!-- §64: a link, never an automatic report. -->
            <div class="flex flex-wrap gap-2 pb-4">
                <Button variant="outline" size="sm" as-child>
                    <a :href="links.reports">
                        <FileText class="size-3.5" />
                        Relatórios individuais
                    </a>
                </Button>
                <Button variant="ghost" size="sm" as-child>
                    <a :href="links.statistics">
                        Estatística da turma
                        <ExternalLink class="size-3.5" />
                    </a>
                </Button>
            </div>
        </template>
    </div>
</template>
