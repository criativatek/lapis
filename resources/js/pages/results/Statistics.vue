<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDownRight, ArrowUpRight, ChartNoAxesCombined, ChevronRight, CircleAlert,
    Minus, TrendingDown, TrendingUp, Trophy, Users, X,
} from '@lucide/vue';
import type { ChartConfiguration } from 'chart.js';
import { computed, defineAsyncComponent, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import DistributionBands from '@/components/infographic/DistributionBands.vue';
import type { DistributionBand } from '@/components/infographic/DistributionBands.vue';
import DomainBars from '@/components/infographic/DomainBars.vue';
import type { DomainBar } from '@/components/infographic/DomainBars.vue';
import DumbbellRows from '@/components/infographic/DumbbellRows.vue';
import type { Dumbbell } from '@/components/infographic/DumbbellRows.vue';
import KpiCard from '@/components/infographic/KpiCard.vue';
import MovementBoard from '@/components/infographic/MovementBoard.vue';
import type { CrossingCard, CrossingKey, HeldCard, HeldKey, MovementCard } from '@/components/infographic/MovementBoard.vue';
import OutcomeDonut from '@/components/infographic/OutcomeDonut.vue';
import SectionHeading from '@/components/infographic/SectionHeading.vue';
import StudentSpectrum from '@/components/infographic/StudentSpectrum.vue';
import type { SpectrumPoint } from '@/components/infographic/StudentSpectrum.vue';
import {
    areaGradient,
    categoryAxis,
    chromeColours,
    domainColours,
    formatPoints,
    formatShare,
    muted,
    pct,
    percentAxis,
    prefersReducedMotion,
    students as studentsWord,
    TONE_COLOURS,
} from '@/lib/chartTheme';
import type { TooltipContent } from '@/lib/chartTheme';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import type { Evolution } from '@/lib/results';
import { card, INSET, PAGE } from '@/lib/surfaces';

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
    primary_average: string | null;
    supplementary_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    /** Movement of the result of the moment — a different fact from `evolution`. */
    continuous_evolution: { direction: string; points: string } | null;
    band: Band;
    /** The grade, as a mention — what the teacher assigned. */
    assigned: Band;
    /** Which group of the assigned distribution they are in. */
    assigned_key: string | null;
    domains: DomainCell[];
    self_assessment: Level;
    classification: { status: string; is_published: boolean; final: Level; proposed: Level } | null;
    /** The student's own line through the year, from the canonical read model. */
    series: {
        period_id: number; period_label: string;
        weighted_average: string | null; accumulated_average: string | null;
        primary_average: string | null;
        coverage_warning: boolean; evolution: Evolution;
        domains: { domain_id: number; weighted_average: string | null; mention: Band }[];
    }[];
    /** Which side of the scale they were on, and are on now. */
    transition: TransitionKey;
};

/** The official rate, counted on the grades the teacher assigned. */
type SuccessFigures = {
    succeeded: number;
    failed: number;
    /** Graded, on a scale that says nothing about which side that grade is. */
    unplaced: number;
    without_classification: number;
    /** succeeded + failed — the denominator, and nothing else. */
    placed: number;
    rate: string | null;
    failure_rate: string | null;
};

type TransitionKey = CrossingKey | HeldKey | 'unclassified' | 'no_assigned_classification';

type Transitions = Record<CrossingKey | HeldKey | 'unclassified' | 'no_assigned_classification' | 'comparable', number> & {
    percentages: Record<CrossingKey | HeldKey, string | null>;
    share_of_class: { unclassified: string | null; no_assigned_classification: string | null };
};

type DomainStatistic = {
    domain_id: number; label: string;
    period_average: string | null; accumulated_average: string | null; evolution_average: string | null;
    students_with_result: number; students_without_result: number; partial_coverage_count: number;
    qualitative_band: Band;
    succeeded: number; placed: number; success_rate: string | null;
};

type Statistics = {
    periods: { id: number; ulid: string; label: string; sequence: number }[];
    selected_period: { id: number; ulid: string; label: string; sequence: number } | null;
    previous_period: { id: number; ulid: string; label: string; sequence: number } | null;
    domains: { id: number; name: string }[];
    scale: {
        name: string; kind: string;
        bands: { label: string; sequence: number; is_negative: boolean }[];
        /** Where this scale puts its passing line, and the words for it. */
        threshold: {
            noun: string; value: string | null; label: string | null;
            at_or_above: string; below: string;
        };
    } | null;
    primary: {
        kind: 'period' | 'accumulated';
        label: string; short_label: string; caption: string | null;
        supplementary_label: string | null; has_supplementary: boolean;
        scopes: Record<string, 'period' | 'accumulated'>;
    };
    summary: {
        students_total: number; students_with_result: number; students_without_result: number;
        class_average: string | null; accumulated_average: string | null;
        primary_average: string | null; supplementary_average: string | null;
        partial_coverage_count: number;
        most_common_band: (NonNullable<Band> & { count: number }) | null;
        success: SuccessFigures;
    };
    evolution: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
        percentages: { progressed: string | null; stable: string | null; regressed: string | null; no_comparison: string | null };
        transitions: Transitions;
    };
    /** The same shape, read on the result of each moment rather than on the period's own. */
    continuous_evolution: {
        progressed: number; stable: number; regressed: number; no_comparison: number;
        comparable: number; average_change: string | null;
        percentages: { progressed: string | null; stable: string | null; regressed: string | null; no_comparison: string | null };
    };
    assigned_distribution: {
        bands: {
            key: string; scale_level_id: number | null; code: string; label: string | null;
            sequence: number; is_negative: boolean | null;
            count: number; percentage: string | null; outside_scale?: boolean;
        }[];
        /** «levels» groups by ScaleLevel; «values» by the number assigned. */
        mode: 'levels' | 'values';
        classified: number; unplaced: number; without_classification: number;
    };
    distribution: (NonNullable<Band> & { key?: string; count: number; percentage: string | null })[];
    domain_statistics: DomainStatistic[];
    period_series: {
        period_id: number; label: string; sequence: number;
        class_average: string | null; accumulated_average: string | null;
        primary_average: string | null; primary_kind: 'period' | 'accumulated';
        students_with_result: number;
        domains: { domain_id: number; average: string | null; accumulated_average: string | null; students_with_result: number }[];
    }[];
    students: Student[];
};

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string; has_profile: boolean; scale_name: string | null };
    decision: { label: string; classifies_by_level: boolean };
    cutoff: { date: string | null; label: string | null; is_open: boolean };
    suggestedInterimName: string | null;
    interimAssessments: {
        ulid: string; name: string; reference_date: string; reference_date_label: string;
        period_label: string; academic_period_id: number;
    }[];
    statistics: Statistics;
}>();

// ------------------------------------------------------------- «Dados até»

/**
 * A free query over a date, and nothing more.
 *
 * Choosing a date changes what this page shows and records absolutely nothing —
 * keeping a moment is a separate, deliberate act. The URL carries it so the view
 * survives a refresh and can be handed to somebody else.
 */
const chosenDate = ref<string>(props.cutoff.date ?? '');

function applyCutoff(date: string): void {
    const query = date === '' ? {} : { ate: date };
    const period = props.statistics.selected_period?.ulid;

    router.get(
        `/classes/${props.schoolClass.ulid}/results/estatistica${period ? `/${period}` : ''}`,
        query,
        { preserveScroll: true, preserveState: false },
    );
}

// ------------------------------------------------- guardar uma intercalar

/**
 * Keeping the moment is a SEPARATE, DELIBERATE ACT.
 *
 * Looking at a date records nothing; this is what records it. The name is
 * suggested by the server and stays editable, because what a school calls this
 * moment is theirs to decide.
 */
const savingInterim = ref(false);

const interimForm = useForm<{ academic_period_id: number | null; reference_date: string; name: string; note: string }>({
    academic_period_id: null,
    reference_date: '',
    name: '',
    note: '',
});

function openInterimForm(): void {
    interimForm.clearErrors();
    interimForm.academic_period_id = props.statistics.selected_period?.id ?? null;
    interimForm.reference_date = props.cutoff.date ?? '';
    // Pre-filled with the suggestion, not merely hinted at: the teacher confirms
    // a real name rather than accepting a blank and being given one.
    interimForm.name = props.suggestedInterimName ?? '';
    interimForm.note = '';
    savingInterim.value = true;
}

/**
 * A name already in use in this period. Worth saying, never worth refusing —
 * identity is the ULID, and two «Antes do Natal» in the same semester is the
 * teacher's business.
 */
const duplicateName = computed<boolean>(() => {
    const name = interimForm.name.trim().toLocaleLowerCase('pt-PT');

    if (name === '') {
        return false;
    }

    return props.interimAssessments.some(
        (interim) => interim.academic_period_id === interimForm.academic_period_id
            && interim.name.trim().toLocaleLowerCase('pt-PT') === name,
    );
});

function submitInterim(): void {
    interimForm.post(`/classes/${props.schoolClass.ulid}/avaliacoes-intercalares`, {
        preserveScroll: true,
        onSuccess: () => (savingInterim.value = false),
    });
}

/**
 * Chart.js arrives only if a canvas is actually going to be drawn.
 *
 * After this iteration the page's own language covers most of it — plates,
 * ribbons, arrows and an SVG slopegraph are all plain DOM. The library is left
 * for the two places that genuinely need axes and series: a year with three or
 * more periods, and a student's panel. Both are conditional, so most visits
 * never download it at all.
 */
const StatChart = defineAsyncComponent(() => import('@/components/charts/StatChart.vue'));

/**
 * The page's surfaces.
 *
 * White cards on a white page is why «before» and «after» kept looking alike
 * however the contents were rearranged: nothing about the FIELD had changed.
 * Each card now sits on a tinted ground, and the page under them is warm rather
 * than white, so the cards read as objects placed on it.
 *
 * Height still follows content — nothing forces a card taller than what it
 * holds, which is what left the old grid with half-empty boxes (§21).
 */
const CARD = `${card('plain')} p-5`;

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
/**
 * A band of the ASSIGNED distribution, and one of the calculated one — kept as
 * two refs rather than one, because they mean different things about a student
 * and a selection must never silently swap which (§14).
 */
const selectedLevelKey = ref<string | null>(null);
const selectedCalculatedLevelKey = ref<string | null>(null);
/** «progressed», «failure_to_success» — a group from the movement board. */
const selectedGroup = ref<string | null>(null);

// ------------------------------------------------- a leitura em curso

/**
 * WHICH READING THE RESULT-BASED VISUALS SHOW.
 *
 * One page, one scope switch — not two dashboards. «Avaliação contínua» draws
 * the figure that answers for each moment (the accumulated one, once the year
 * has accumulated); «Só este período» draws the period's own work. The default
 * is the continuous one, because that is what the teacher classifies against.
 *
 * IT MOVES AVERAGES AND NOTHING ELSE. The success rate, the assigned
 * distribution and the crossings are statements about decisions the teacher
 * took, and no choice of average may edit them (§34).
 */
const continuousView = ref<boolean>(true);

/** There is only a choice to make once the two readings differ. */
const canSwitchReading = computed<boolean>(() => stats.value.primary.has_supplementary);

const usingAccumulated = computed<boolean>(() => (
    stats.value.primary.kind === 'accumulated' && continuousView.value
));

/** The class figure the page is currently showing. */
const readingAverage = computed<string | null>(() => (
    usingAccumulated.value ? stats.value.summary.accumulated_average : stats.value.summary.class_average
));

const supplementaryAverage = computed<string | null>(() => (
    usingAccumulated.value ? stats.value.summary.class_average : stats.value.summary.accumulated_average
));

/**
 * WHAT THE BIG NUMBER IS, said by the label rather than assumed.
 *
 * The figure follows the switch, so the words have to follow it too — a «66,8%»
 * under «Média acumulada da turma» is a contradiction the reader has to resolve
 * on the application's behalf, and they should never have to.
 *
 * The period's own name comes from the AcademicPeriod, so «2.º Semestre», «3.º
 * Período» and «1.º Trimestre» all read naturally without a branch per school
 * calendar.
 */
const periodName = computed<string>(() => stats.value.selected_period?.label ?? 'período');

const readingLabel = computed<string>(() => (
    usingAccumulated.value ? 'Média acumulada da turma' : `Média ponderada do ${periodName.value}`
));

const readingCaption = computed<string>(() => (
    usingAccumulated.value
        ? 'Considera a avaliação realizada ao longo do ano letivo até este momento.'
        : `Considera apenas os elementos realizados no ${periodName.value}.`
));

const readingHelp = computed<string>(() => (
    usingAccumulated.value
        ? 'Considera os períodos que contribuem para a avaliação contínua até este momento.'
        : 'Mostra apenas os resultados dos elementos realizados neste período, sem o efeito dos períodos anteriores.'
));

/** The other perspective — named in full, never as a bare «acumulado». */
const supplementaryLabel = computed<string>(() => (
    usingAccumulated.value
        ? `Só no ${periodName.value}`
        : 'Média acumulada ao longo do ano letivo'
));

/**
 * How far apart the two readings are, in percentage points.
 *
 * Both numbers are already on the page; this only subtracts them, at the
 * precision they are shown at, so «igual» on screen is «igual» here.
 */
const readingGap = computed<number | null>(() => {
    const primary = readingAverage.value;
    const other = supplementaryAverage.value;

    if (primary === null || other === null) {
        return null;
    }

    return Number(Number(primary).toFixed(1)) - Number(Number(other).toFixed(1));
});

/**
 * The gap, named by what it is measured AGAINST.
 *
 * «+6,5 p.p.» on its own would leave the reader to work out which of the two
 * numbers is above the other; naming the far side settles it in one phrase and
 * keeps the block from turning into a paragraph (§4).
 */
const readingGapCaption = computed<string | null>(() => {
    if (readingGap.value === null) {
        return null;
    }

    return usingAccumulated.value
        ? `${formatPoints(readingGap.value)} p.p. face ao desempenho só deste período`
        : `${formatPoints(readingGap.value)} p.p. face ao acumulado`;
});

/** The movement that belongs to the reading on screen. Two datasets, one shape. */
const readingEvolution = computed(() => (
    usingAccumulated.value ? stats.value.continuous_evolution : stats.value.evolution
));

const readingEvolutionLabel = computed<string>(() => (
    usingAccumulated.value ? 'Evolução na avaliação contínua' : 'Evolução do desempenho'
));

const evolutionTrend = computed(() => {
    const change = readingEvolution.value.average_change;

    if (!hasComparison.value || change === null) {
        return null;
    }

    return {
        display: `${formatPoints(change)} p.p. face a ${stats.value.previous_period?.label ?? 'o período anterior'}`,
        direction: Number(change) > 0 ? 'up' as const : Number(change) < 0 ? 'down' as const : 'flat' as const,
    };
});

const evolutionTrendIcon = computed(() => {
    const direction = evolutionTrend.value?.direction;

    return direction === 'up' ? TrendingUp : direction === 'down' ? TrendingDown : Minus;
});

/** One student's figure, under the reading on screen. */
function readingValueOf(student: Student): string | null {
    return usingAccumulated.value ? student.accumulated_average : student.weighted_average;
}

/**
 * The calculated distribution starts folded.
 *
 * Not hidden — one click away, and its heading names what is inside. It answers
 * a real question («onde está a turma antes de eu classificar?») but it is not
 * the one this page opens on, and two grids of five tiles side by side read as
 * one contradictory grid of ten (§7).
 */
const showCalculatedDistribution = ref<boolean>(false);

function toggleDomain(domainId: number): void {
    const previous = selectedDomainId.value;

    clearSelection();
    selectedDomainId.value = previous === domainId ? null : domainId;
}

function toggleLevel(key: string): void {
    const previous = selectedLevelKey.value;

    clearSelection();
    selectedLevelKey.value = previous === key ? null : key;
}

function toggleCalculatedLevel(key: string): void {
    const previous = selectedCalculatedLevelKey.value;

    clearSelection();
    selectedCalculatedLevelKey.value = previous === key ? null : key;
}

function toggleGroup(key: string): void {
    const previous = selectedGroup.value;

    clearSelection();
    selectedGroup.value = previous === key ? null : key;
}

function clearSelection(): void {
    selectedDomainId.value = null;
    selectedLevelKey.value = null;
    selectedCalculatedLevelKey.value = null;
    selectedGroup.value = null;
}

/** The words the chip and the map caption use for a chosen group. */
const GROUP_LABELS: Record<string, string> = {
    progressed: 'Progrediram',
    stable: 'Mantiveram-se',
    regressed: 'Regrediram',
};

/** The chip names the group in the same words its card does. */
const CROSSING_LABELS = computed<Record<string, string>>(() => ({
    failure_to_success: `Passaram para ${threshold.value.at_or_above}`,
    success_to_failure: `Passaram para ${threshold.value.below}`,
}));

const selectedGroupLabel = computed<string | null>(() => (
    selectedGroup.value === null
        ? null
        : GROUP_LABELS[selectedGroup.value] ?? CROSSING_LABELS.value[selectedGroup.value] ?? null
));

const selectedDomain = computed(() => stats.value.domains.find((domain) => domain.id === selectedDomainId.value) ?? null);

const selectedLevel = computed(() => (
    stats.value.assigned_distribution.bands.find((band) => band.key === selectedLevelKey.value) ?? null
));

const selectedCalculatedLevel = computed(() => (
    stats.value.distribution.find((band) => String(band.scale_level_id) === selectedCalculatedLevelKey.value) ?? null
));

/** Whether a domain should be drawn at full strength. */
function isLit(domainId: number): boolean {
    return selectedDomainId.value === null || selectedDomainId.value === domainId;
}

/**
 * The students the current selection points at — highlighted, never hidden.
 *
 * A band and a movement group are asked of the same student in the same way, so
 * the map and the spectrum both dim by one rule instead of two.
 */
function matchesLevel(student: Student): boolean {
    if (selectedGroup.value !== null) {
        return student.transition === selectedGroup.value
            || (selectedGroup.value === 'progressed' && student.evolution?.direction === 'up')
            || (selectedGroup.value === 'stable' && student.evolution?.direction === 'flat')
            || (selectedGroup.value === 'regressed' && student.evolution?.direction === 'down');
    }

    // A band chosen on the ASSIGNED distribution points at the students who
    // were GRADED there — never at those whose average happens to land there.
    if (selectedLevelKey.value !== null) {
        return student.assigned_key === selectedLevelKey.value;
    }

    return selectedCalculatedLevelKey.value === null
        || String(student.band?.scale_level_id) === selectedCalculatedLevelKey.value;
}

const highlightedStudents = computed(() => stats.value.students.filter((student) => matchesLevel(student)).length);

// ------------------------------------------------------------------ gráficos



// ---------------------------------------------- visualizações adaptativas

/**
 * How many moments there actually are to show.
 *
 * TWO POINTS DO NOT JUSTIFY A LINE CHART. The shape of the visualisation is
 * chosen from the data rather than fixed in advance: nothing for one period, a
 * slopegraph for two, and a proper trend line once there is a trend to draw.
 */
const periodsWithResults = computed(() => stats.value.period_series.filter((row) => row.class_average !== null));

const trendShape = computed<'single' | 'slope' | 'line'>(() => {
    if (periodsWithResults.value.length <= 1) {
        return 'single';
    }

    return periodsWithResults.value.length === 2 ? 'slope' : 'line';
});

/** The two ends of a slopegraph: the first and last periods that have results. */
const slopeEnds = computed(() => {
    const rows = periodsWithResults.value;

    return rows.length < 2 ? null : { from: rows[0], to: rows[rows.length - 1] };
});


/** The class's own change between the two ends, in percentage points. */
const classPeriodChange = computed<number | null>(() => {
    const ends = slopeEnds.value;

    if (ends === null || ends.from.class_average === null || ends.to.class_average === null) {
        return null;
    }

    return Number(ends.to.class_average) - Number(ends.from.class_average);
});

/** The same two ends per domain, as one compact row each. */
const domainDumbbells = computed<Dumbbell[]>(() => {
    const ends = slopeEnds.value;

    if (ends === null) {
        return [];
    }

    return stats.value.domains.map((domain) => {
        const from = ends.from.domains.find((cell) => cell.domain_id === domain.id)?.average ?? null;
        const to = ends.to.domains.find((cell) => cell.domain_id === domain.id)?.average ?? null;

        return {
            id: domain.id,
            label: domain.name,
            from: from === null ? null : Number(from),
            to: to === null ? null : Number(to),
            colour: inks.value[domain.id],
        };
    });
});


// ------------------------------ movimento e evolução do que foi atribuído

/**
 * TWO READINGS, KEPT APART ON PURPOSE.
 *
 * `movements` counts who went up, held or came down — the standalone result of
 * this period against the standalone result of the last. `crossings` counts who
 * changed SIDE of the scale, read on the accumulated figure because that is
 * what carries a mention. A student can appear in «progrediram» and in
 * «mantiveram positivo» at once, and that is not a contradiction: they are
 * answers to different questions and the board says which is which.
 *
 * Everything here is relabelling. The counts, the shares and the grouping were
 * all decided by the read model, which decided them from the scale.
 */
const movements = computed<MovementCard[]>(() => {
    const evolution = readingEvolution.value;

    return [
        { key: 'progressed', label: 'Progrediram', count: evolution.progressed, share: formatShare(evolution.percentages.progressed) },
        { key: 'stable', label: 'Mantiveram-se', count: evolution.stable, share: formatShare(evolution.percentages.stable) },
        { key: 'regressed', label: 'Regrediram', count: evolution.regressed, share: formatShare(evolution.percentages.regressed) },
    ];
});

/**
 * WHERE THE SCALE PUTS ITS LINE, in words the school can check.
 *
 * «Passaram a resultado positivo» was generic and says nothing verifiable;
 * «Passaram para nível igual ou superior a 3» is the same fact stated against
 * the configuration. The phrasing arrives from the read model, built from the
 * first level the scale does not call negative — never from a 3, a 10 or a 50
 * written here (§1.6, §1.9).
 */
const threshold = computed(() => stats.value.scale?.threshold ?? {
    noun: 'classificação',
    value: null,
    label: null,
    at_or_above: 'classificação não negativa',
    below: 'classificação negativa',
});

/** «1 passou» / «2 passaram» — said properly, both ways (§1.11). */
function crossed(count: number): string {
    return count === 1 ? 'Passou' : 'Passaram';
}

const crossings = computed<CrossingCard[]>(() => {
    const transitions = stats.value.evolution.transitions;

    return [
        {
            key: 'failure_to_success',
            label: `${crossed(transitions.failure_to_success)} para ${threshold.value.at_or_above}`,
            count: transitions.failure_to_success,
            share: formatShare(transitions.percentages.failure_to_success),
        },
        {
            key: 'success_to_failure',
            label: `${crossed(transitions.success_to_failure)} para ${threshold.value.below}`,
            count: transitions.success_to_failure,
            share: formatShare(transitions.percentages.success_to_failure),
        },
    ];
});

const held = computed<HeldCard[]>(() => {
    const transitions = stats.value.evolution.transitions;

    return [
        {
            key: 'success_to_success',
            label: `Mantiveram ${threshold.value.at_or_above}`,
            count: transitions.success_to_success,
        },
        {
            key: 'failure_to_failure',
            label: `Mantiveram ${threshold.value.below}`,
            count: transitions.failure_to_failure,
        },
    ];
});

/** «Evolução dos níveis atribuídos» — the scale decides the noun (§1.4). */
const crossingTitle = computed<string>(() => (
    threshold.value.noun === 'nível'
        ? 'Evolução dos níveis atribuídos'
        : 'Evolução das classificações atribuídas'
));

const crossingCaption = computed<string>(() => {
    const plural = threshold.value.noun === 'nível' ? 'os níveis' : 'as classificações';

    return `Esta leitura compara ${plural} que atribuiu nos dois momentos, tendo em conta o limiar`
        + ' definido pela escala.';

});
/**
 * The class's own line through the year, drawn on the figure that answered at
 * each moment — never a line that silently changes meaning halfway (§22).
 */
const primaryTrend = computed(() => stats.value.period_series
    .map((row) => ({
        label: row.label,
        // The line draws the reading in force. Labelling it «ao longo do ano»
        // while it plots something else is the same contradiction the headline
        // had (§5).
        value: usingAccumulated.value ? row.primary_average : row.class_average,
    }))
    .filter((row) => row.value !== null)
    .map((row) => ({ label: row.label, percent: Number(row.value) })));

const trendLabel = computed<string>(() => (
    usingAccumulated.value ? 'Evolução da média acumulada' : 'Média de cada período'
));

const trendSummary = computed<string>(() => (
    `${trendLabel.value}: ${primaryTrend.value
        .map((point) => `${point.label}, ${pct(String(point.percent))}`)
        .join('; ')}.`
));

/** A polyline over 0–100, for the band at the foot of the page. */
const trendPoints = computed<string>(() => {
    const points = primaryTrend.value;

    if (points.length < 2) {
        return '';
    }

    return points
        .map((point, index) => {
            const x = (index / (points.length - 1)) * 100;

            return `${x},${40 - (point.percent / 100) * 36}`;
        })
        .join(' ');
});


const placedOnScale = computed(() => stats.value.distribution.reduce((total, band) => total + band.count, 0));

// ------------------------------------------------- a nova composição

/**
 * A band, dressed for the tiles. Used for both distributions, which is the
 * point: the same shape, so the difference between them is only the source and
 * the words above them.
 */
function toBand(band: {
    key?: string; scale_level_id: number | null; code: string; label: string | null;
    sequence: number; is_negative: boolean | null; count: number; percentage: string | null;
}): DistributionBand {
    const tone = toneOf({ sequence: band.sequence, is_negative: band.is_negative ?? false });

    return {
        key: band.key ?? String(band.scale_level_id),
        scale_level_id: band.scale_level_id,
        code: band.code,
        label: band.label,
        count: band.count,
        percent: band.percentage === null ? null : Number(band.percentage),
        share: formatShare(band.percentage),
        tone,
        colour: TONE_COLOURS[tone].border,
    };
}

/** The grades the teacher gave, counted per level. The primary reading. */
const assignedBands = computed<DistributionBand[]>(() => stats.value.assigned_distribution.bands.map(toBand));

/** Where the calculated averages land. Kept, and labelled as secondary. */
const distributionBands = computed<DistributionBand[]>(() => stats.value.distribution.map(toBand));

/** The domains, as wide bars: domain ink, scale mention, trend change. */
const domainBars = computed<DomainBar[]>(() => stats.value.domain_statistics.map((row) => {
    // A domain has no assigned classification — the decision is taken for the
    // period — so this stays a calculated reading, and follows the same figure
    // the rest of the page is showing (§25).
    const value = usingAccumulated.value ? row.accumulated_average : row.period_average;

    return {
        id: row.domain_id,
        label: row.label,
        percent: value === null ? null : Number(value),
        display: pct(value),
        colour: inks.value[row.domain_id],
        mention: row.qualitative_band?.label ?? null,
        mentionClass: toneClass(row.qualitative_band),
        change: row.evolution_average === null ? null : formatPoints(row.evolution_average),
        direction: row.evolution_average === null
            ? null
            : Number(row.evolution_average) > 0 ? 'up' : Number(row.evolution_average) < 0 ? 'down' : 'flat',
        students: `${row.students_with_result} de ${stats.value.summary.students_total} com resultado`
            + (row.partial_coverage_count > 0 ? ` · ${row.partial_coverage_count} com informação parcial` : ''),
    };
}));

/**
 * Where each student sits on the scale.
 *
 * Only those who HAVE a result: an axis of results has no place for an absence,
 * and putting one at zero would be inventing the very thing the whole engine
 * refuses to invent.
 */
const spectrum = computed<SpectrumPoint[]>(() => stats.value.students
    .filter((student) => readingValueOf(student) !== null)
    .map((student) => ({
        enrollment_id: student.enrollment_id,
        name: student.name,
        // The axis carries ONE figure at a time. Mixing an accumulated result
        // and a period one on the same line would place two students side by
        // side who are not comparable at all (§26).
        percent: Number(readingValueOf(student)),
        display: pct(readingValueOf(student)),
        mention: student.band?.label ?? null,
        mentionClass: toneClass(student.band),
        classNumber: student.class_number,
    })));

// -------------------------------------------- a evolução de um aluno

/**
 * The student's movement in the reading that answers for them — the continuous
 * one when there is one, the period-against-period one otherwise.
 */
const studentMovement = computed(() => (
    stats.value.primary.has_supplementary
        ? selected.value?.continuous_evolution ?? null
        : selected.value?.evolution ?? null
));

/**
 * The moments this student actually has a figure in, drawn on the result that
 * answered at each one. Never invented, and never a line that changes meaning
 * halfway through the year (§29).
 */
const studentMoments = computed(() => (selected.value?.series ?? [])
    .filter((entry) => entry.primary_average !== null));

/**
 * The shape of the individual reading follows how many moments there are, for
 * the same reason the class one does: two points do not justify a line chart,
 * and one point is a number rather than a trend (§3).
 */
const studentShape = computed<'single' | 'slope' | 'line'>(() => {
    if (studentMoments.value.length <= 1) {
        return 'single';
    }

    return studentMoments.value.length === 2 ? 'slope' : 'line';
});

/** The student's own ends, for the dumbbells. */
const studentEnds = computed(() => {
    const moments = studentMoments.value;

    return moments.length < 2 ? null : { from: moments[0], to: moments[moments.length - 1] };
});

const studentChange = computed<number | null>(() => {
    const ends = studentEnds.value;

    return ends === null ? null : Number(ends.to.primary_average) - Number(ends.from.primary_average);
});

/** One row per domain: where this student started and where they ended. */
const studentDomainDumbbells = computed<Dumbbell[]>(() => {
    const ends = studentEnds.value;

    if (ends === null) {
        return [];
    }

    return stats.value.domains.map((domain) => {
        const from = ends.from.domains.find((cell) => cell.domain_id === domain.id)?.weighted_average ?? null;
        const to = ends.to.domains.find((cell) => cell.domain_id === domain.id)?.weighted_average ?? null;

        return {
            id: domain.id,
            label: domain.name,
            from: from === null ? null : Number(from),
            to: to === null ? null : Number(to),
            // The SAME ink the domain carries everywhere else on the page.
            colour: inks.value[domain.id],
        };
    });
});

function openStudentById(enrollmentId: number): void {
    selected.value = stats.value.students.find((student) => student.enrollment_id === enrollmentId) ?? null;
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


// ------------------------------------------------------- equivalentes textuais


// The domain figures need no sr-only table of their own: DomainBars is real DOM
// and every value in it is already text a screen reader reads directly.

const seriesRows = computed(() => stats.value.period_series.map((row) => [
    row.label, pct(row.class_average),
    ...stats.value.domains.map((domain) => pct(
        row.domains.find((candidate) => candidate.domain_id === domain.id)?.average ?? null,
    )),
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

/**
 * The domain figure the map is showing, under the reading in force.
 *
 * The cell holds both; which one is drawn follows the same switch as the rest
 * of the page, so a «60,3%» in the map never means something different from a
 * «60,3%» in the header (§27).
 */
function heatValue(student: Student, domainId: number): string | null {
    const cell = heatCell(student, domainId);

    if (cell === undefined) {
        return null;
    }

    return usingAccumulated.value ? cell.accumulated_average : cell.weighted_average;
}

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

    <div :class="PAGE" class="min-h-full space-y-5 p-4 sm:p-5">
        <!-- ================================================== cabeçalho -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading
                    title="Visão geral de desempenho"
                    :description="`${schoolClass.label} · ${schoolClass.subject} — o desempenho e a evolução da turma nas avaliações realizadas.`"
                />
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
            <!-- ========================================== «Dados até» (§3, §4) -->
            <div
                class="flex flex-wrap items-center gap-3 rounded-xl px-4 py-3"
                :class="cutoff.is_open ? 'bg-muted/25' : 'border border-amber-300/70 bg-amber-50/70 dark:border-amber-900/60 dark:bg-amber-950/30'"
            >
                <label for="cutoff-date" class="text-sm text-muted-foreground">Dados até:</label>
                <input
                    id="cutoff-date"
                    v-model="chosenDate"
                    type="date"
                    class="rounded-md border border-border bg-background px-2.5 py-1 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @change="applyCutoff(chosenDate)"
                />

                <!-- The reader must never forget they are looking at a slice of
                     time. Said in words, beside a way out of it (§4). -->
                <template v-if="!cutoff.is_open">
                    <p class="text-sm font-medium text-amber-900 dark:text-amber-200" role="status">
                        A visualizar dados até {{ cutoff.label }}
                    </p>
                    <button
                        type="button"
                        class="ml-auto rounded-md border border-border bg-background px-3 py-1 text-sm transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="chosenDate = ''; applyCutoff('')"
                    >
                        Voltar a hoje
                    </button>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    A visualizar tudo o que existe hoje.
                </p>

                <button
                    v-if="!cutoff.is_open"
                    type="button"
                    class="rounded-md border border-primary bg-primary px-3 py-1 text-sm font-medium text-primary-foreground transition-opacity hover:opacity-90 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @click="openInterimForm"
                >
                    Guardar como avaliação intercalar
                </button>
            </div>

            <!-- O formulário. Só aparece quando o professor o pede. -->
            <form
                v-if="savingInterim"
                class="space-y-3 rounded-xl border border-border bg-card p-5"
                @submit.prevent="submitInterim"
            >
                <h2 class="text-sm font-semibold">Guardar como avaliação intercalar</h2>
                <p class="text-xs text-muted-foreground">
                    Fica uma fotografia do estado nesta data. Corrigir notas mais tarde não a altera.
                </p>

                <div class="grid gap-3 sm:grid-cols-2">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Nome</span>
                        <input
                            v-model="interimForm.name"
                            type="text"
                            required
                            maxlength="160"
                            class="w-full rounded-md border border-border bg-background px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <span v-if="interimForm.errors.name" class="mt-1 block text-xs text-red-600">
                            {{ interimForm.errors.name }}
                        </span>
                        <!-- Worth saying, never worth refusing (§6). -->
                        <span v-else-if="duplicateName" class="mt-1 block text-xs text-amber-700 dark:text-amber-400">
                            Já existe uma avaliação intercalar com este nome neste período. Pode continuar.
                        </span>
                    </label>

                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-muted-foreground">Data de referência</span>
                        <input
                            v-model="interimForm.reference_date"
                            type="date"
                            required
                            class="w-full rounded-md border border-border bg-background px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                        <span v-if="interimForm.errors.reference_date" class="mt-1 block text-xs text-red-600">
                            {{ interimForm.errors.reference_date }}
                        </span>
                    </label>
                </div>

                <label class="block text-sm">
                    <span class="mb-1 block text-xs text-muted-foreground">Observação (opcional)</span>
                    <textarea
                        v-model="interimForm.note"
                        rows="2"
                        class="w-full rounded-md border border-border bg-background px-2.5 py-1.5 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    ></textarea>
                </label>

                <p class="text-xs text-muted-foreground">
                    Período: <strong>{{ stats.selected_period?.label }}</strong>
                </p>

                <div class="flex gap-2">
                    <button
                        type="submit"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50"
                        :disabled="interimForm.processing"
                    >
                        {{ interimForm.processing ? 'A guardar…' : 'Guardar' }}
                    </button>
                    <button
                        type="button"
                        class="rounded-md border border-border px-4 py-2 text-sm transition-colors hover:bg-muted/40"
                        @click="savingInterim = false"
                    >
                        Cancelar
                    </button>
                </div>
            </form>

            <!-- A lista do que já foi guardado (§21). -->
            <section v-if="interimAssessments.length" class="rounded-xl bg-muted/25 p-5">
                <h2 class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                    Avaliações intercalares
                </h2>
                <ul class="divide-y divide-border/60">
                    <li v-for="interim in interimAssessments" :key="interim.ulid" class="flex flex-wrap items-center gap-3 py-2">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ interim.name }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ interim.period_label }} · {{ interim.reference_date_label }}
                            </p>
                        </div>
                        <Link
                            :href="`/classes/${schoolClass.ulid}/avaliacoes-intercalares/${interim.ulid}`"
                            class="ml-auto rounded-md border border-border px-3 py-1 text-sm transition-colors hover:bg-background"
                        >
                            Ver
                        </Link>
                    </li>
                </ul>
            </section>

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
                    v-if="selectedDomain || selectedLevel || selectedCalculatedLevel || selectedGroupLabel"
                    class="flex flex-wrap items-center gap-2 rounded-xl border border-primary/30 bg-primary/5 px-4 py-2.5 text-sm"
                    role="status"
                >
                    <span class="text-muted-foreground">A destacar:</span>

                    <span v-if="selectedDomain" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        <span class="size-2 rounded-full" :style="{ backgroundColor: inks[selectedDomain.id] }"></span>
                        {{ selectedDomain.name }}
                    </span>

                    <!-- The chip names WHICH reading the band came from, so a
                         selection is never ambiguous between the two (§14). -->
                    <span v-if="selectedLevel" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        {{ selectedLevel.code }} · {{ selectedLevel.label }}
                        <span class="text-muted-foreground">atribuído · {{ studentsWord(highlightedStudents) }}</span>
                    </span>

                    <span v-if="selectedCalculatedLevel" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        {{ selectedCalculatedLevel.code }} · {{ selectedCalculatedLevel.label }}
                        <span class="text-muted-foreground">calculado · {{ studentsWord(highlightedStudents) }}</span>
                    </span>

                    <span v-if="selectedGroupLabel" class="inline-flex items-center gap-2 rounded-full bg-background px-3 py-1 font-medium shadow-sm">
                        {{ selectedGroupLabel }}
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

            <!-- ONE SWITCH, NOT TWO DASHBOARDS. It changes which average the
                 result-based readings draw and nothing else — the grades and
                 everything counted from them stay put (§34, §35). -->
            <div v-if="canSwitchReading" class="flex flex-wrap items-center gap-3">
                <span class="text-xs font-medium text-muted-foreground">Ler os resultados como:</span>

                <div class="inline-flex rounded-full border border-border bg-card p-0.5 shadow-sm">
                    <button
                        v-for="option in [
                            { continuous: true, label: 'Avaliação contínua' },
                            { continuous: false, label: `Só no ${stats.selected_period?.label}` },
                        ]"
                        :key="option.label"
                        type="button"
                        class="rounded-full px-3.5 py-1 text-xs font-medium transition-colors"
                        :class="continuousView === option.continuous
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:text-foreground'"
                        :aria-pressed="continuousView === option.continuous"
                        @click="continuousView = option.continuous"
                    >
                        {{ option.label }}
                    </button>
                </div>

                <p class="text-[11px] text-muted-foreground" role="status">
                    <template v-if="continuousView">
                        O acumulado do ano até aqui — a leitura que a classificação acompanha.
                    </template>
                    <template v-else>
                        Só o trabalho deste período. Leitura suplementar: não muda classificações nem taxa de sucesso.
                    </template>
                </p>
            </div>

            <!-- ============================== 01 · A TURMA NUM OLHAR -->
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <!-- THE RESULT OF THE MOMENT. Which figure that is was decided
                     server-side from the profile's own continuity, and the
                     caption says which one arrived (§9). -->
                <KpiCard
                    tone="violet"
                    :icon="ChartNoAxesCombined"
                    :label="readingLabel"
                    :value="pct(readingAverage)"
                    :context="readingCaption"
                    :help="readingHelp"
                >
                    <div
                        v-if="stats.primary.has_supplementary"
                        class="mt-3 border-t border-border/50 pt-2.5 text-[11px]"
                    >
                        <p class="flex items-baseline gap-2">
                            <span class="min-w-0 text-muted-foreground">{{ supplementaryLabel }}</span>
                            <span class="ml-auto shrink-0 font-semibold tabular-nums">{{ pct(supplementaryAverage) }}</span>
                        </p>
                        <p v-if="readingGap !== null" class="mt-1 tabular-nums text-muted-foreground">
                            {{ readingGapCaption }}
                        </p>
                    </div>
                </KpiCard>

                <!-- «Quantos alunos tiveram classificação positiva?» — decided
                     by the scale's own is_negative, never by a threshold, and
                     counted on the grades rather than on the averages (§10). -->
                <KpiCard
                    tone="mint"
                    :icon="Trophy"
                    label="Taxa de sucesso"
                    :value="formatShare(stats.summary.success.rate)"
                    :context="stats.summary.success.placed > 0
                        ? `${stats.summary.success.succeeded} de ${stats.summary.success.placed} classificações positivas`
                        : 'Ainda não há classificações atribuídas'"
                    help="Conta as classificações que atribuiu, não as médias calculadas. Quem ainda não tem classificação fica fora."
                >
                    <p
                        v-if="stats.summary.success.without_classification > 0"
                        class="mt-3 flex items-baseline gap-2 border-t border-border/50 pt-2.5 text-[11px]"
                    >
                        <span class="text-muted-foreground">Por classificar</span>
                        <span class="ml-auto font-semibold tabular-nums">{{ stats.summary.success.without_classification }}</span>
                    </p>
                </KpiCard>

                <KpiCard
                    tone="sky"
                    :icon="Users"
                    label="Alunos com resultado"
                    :value="`${stats.summary.students_with_result}`"
                    :unit="`/ ${stats.summary.students_total}`"
                    :context="stats.summary.students_without_result > 0
                        ? `${stats.summary.students_without_result} ainda sem qualquer elemento avaliado`
                        : 'Toda a turma tem elementos avaliados'"
                >
                    <p
                        v-if="stats.summary.partial_coverage_count > 0"
                        class="mt-3 flex items-baseline gap-2 border-t border-border/50 pt-2.5 text-[11px]"
                    >
                        <span class="text-muted-foreground">Com informação parcial</span>
                        <span class="ml-auto font-semibold tabular-nums">{{ stats.summary.partial_coverage_count }}</span>
                    </p>
                </KpiCard>

                <!-- The evolution of the READING ON SCREEN. In «avaliação
                     contínua» that is the continuous one, which is a different
                     number from the period-against-period figure (§12, §13). -->
                <KpiCard
                    tone="amber"
                    :icon="TrendingUp"
                    :label="readingEvolutionLabel"
                    :value="hasComparison ? `${formatPoints(readingEvolution.average_change)}` : '—'"
                    :unit="hasComparison && readingEvolution.average_change !== null ? 'p.p.' : null"
                    :trend="evolutionTrend"
                    :trend-icon="evolutionTrendIcon"
                    :context="hasComparison
                        ? `Sobre ${studentsWord(readingEvolution.comparable)} com dois momentos comparáveis`
                        : 'Ainda não há um momento anterior para comparar'"
                    :help="continuousView
                        ? 'Compara o resultado que respondia em cada momento — a partir do segundo, o acumulado.'
                        : 'Compara o trabalho realizado neste período com o do período anterior.'"
                />
            </div>

            <!-- ============================ 02 · COMO EVOLUIU A TURMA -->
            <section :class="[card('plain'), 'rounded-[22px] p-5 sm:p-6']">
                <SectionHeading
                    index="02"
                    title="Como evoluiu a turma"
                    :description="hasComparison
                        ? `Face a ${stats.previous_period?.label}. Quantos alunos progrediram, mantiveram-se ou regrediram nos resultados calculados, e como evoluíram as classificações que atribuiu.`
                        : 'Ainda não há período anterior para comparar.'"
                />

                <MovementBoard
                    v-if="hasComparison"
                    :comparable="readingEvolution.comparable"
                    :movement-caption="usingAccumulated
                        ? 'com dois momentos comparáveis, resultado acumulado contra o resultado anterior'
                        : 'com dois períodos comparáveis, resultado isolado contra resultado isolado'"
                    :movements="movements"
                    :crossings="crossings"
                    :held="held"
                    :crossing-title="crossingTitle"
                    :crossing-caption="crossingCaption"
                    :crossing-comparable="stats.evolution.transitions.comparable"
                    :unclassified="stats.evolution.transitions.unclassified"
                    :no-comparison="stats.evolution.transitions.no_assigned_classification"
                    :selected="selectedGroup"
                    @select="toggleGroup"
                />

                <div v-else class="flex flex-col items-center justify-center rounded-xl bg-muted/25 px-6 py-12 text-center">
                    <Minus class="size-5 text-muted-foreground/60" />
                    <p class="mt-3 text-sm font-medium">Sem comparação possível</p>
                    <p class="mt-1 text-xs text-muted-foreground">
                        Aparece aqui quando existir um segundo momento de avaliação.
                    </p>
                </div>
            </section>

            <!-- ========== 03 · CLASSIFICAÇÕES POSITIVAS E NEGATIVAS -->
            <div class="grid gap-4 lg:grid-cols-5">
                <section :class="[card('mint'), 'rounded-[22px] p-5 lg:col-span-2']">
                    <SectionHeading
                        index="03"
                        title="Classificações positivas e negativas"
                        description="As classificações que atribuiu, e de que lado da escala caem. Progredir e ser positivo são coisas diferentes."
                    />

                    <OutcomeDonut
                        :succeeded="stats.summary.success.succeeded"
                        :failed="stats.summary.success.failed"
                        :unplaced="stats.summary.success.unplaced"
                        :without-classification="stats.summary.success.without_classification"
                        :success-share="formatShare(stats.summary.success.rate)"
                        :failure-share="formatShare(stats.summary.success.failure_rate)"
                    />
                </section>

                <!-- ================= 04 · DISTRIBUIÇÃO DAS CLASSIFICAÇÕES -->
                <section :class="[card('sky'), 'rounded-[22px] p-5 lg:col-span-3']">
                    <SectionHeading
                        index="04"
                        title="Como se distribuem as classificações"
                        :description="`Valores efetivamente atribuídos aos alunos${schoolClass.scale_name ? `, na escala «${schoolClass.scale_name}»` : ''}. As menções da escala aparecem como referência. Escolha uma classificação para seguir esses alunos no mapa.`"
                    />

                    <DistributionBands
                        v-if="assignedBands.length"
                        :bands="assignedBands"
                        :placed="stats.assigned_distribution.classified"
                        :selected-key="selectedLevelKey"
                        lead-with="value"
                        @select="toggleLevel"
                    />
                    <p v-else class="rounded-xl bg-muted/25 py-10 text-center text-sm text-muted-foreground">
                        A escala desta turma não tem bandas configuradas, por isso não há níveis para distribuir.
                    </p>

                    <!-- OUTSIDE THE BANDS, AND SAID SO. A student nobody has
                         graded is not a zero anywhere on this row. -->
                    <p
                        v-if="stats.assigned_distribution.without_classification > 0 || stats.assigned_distribution.unplaced > 0"
                        class="mt-3 flex flex-wrap items-center gap-x-5 gap-y-1.5 rounded-xl bg-background/50 px-3.5 py-2.5 text-xs dark:bg-background/25"
                    >
                        <span v-if="stats.assigned_distribution.without_classification > 0" class="flex items-center gap-1.5">
                            <Minus aria-hidden="true" class="size-3.5 shrink-0 text-muted-foreground/60" />
                            <span class="text-muted-foreground">Sem classificação atribuída</span>
                            <strong class="font-semibold tabular-nums">{{ stats.assigned_distribution.without_classification }}</strong>
                        </span>

                        <span v-if="stats.assigned_distribution.unplaced > 0" class="flex items-center gap-1.5">
                            <Minus aria-hidden="true" class="size-3.5 shrink-0 text-muted-foreground/60" />
                            <span class="text-muted-foreground">Classificados sem banda na escala</span>
                            <strong class="font-semibold tabular-nums">{{ stats.assigned_distribution.unplaced }}</strong>
                        </span>
                    </p>

                    <!-- ---- a leitura secundária: onde caem as médias ---- -->
                    <div class="mt-5 border-t border-border/60 pt-4">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2 rounded-lg text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-expanded="showCalculatedDistribution"
                            aria-controls="distribuicao-calculada"
                            @click="showCalculatedDistribution = !showCalculatedDistribution"
                        >
                            <ChevronRight
                                aria-hidden="true"
                                class="size-3.5 shrink-0 text-muted-foreground transition-transform"
                                :class="showCalculatedDistribution ? 'rotate-90' : ''"
                            />
                            <span class="text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                                Leitura secundária · onde caem os resultados calculados
                            </span>
                            <span aria-hidden="true" class="ml-2 h-px flex-1 bg-border/70"></span>
                        </button>

                        <div v-show="showCalculatedDistribution" id="distribuicao-calculada" class="mt-3.5">
                            <!-- A DIFFERENT QUESTION, AND IT SAYS SO. This bands
                                 the Média Ponderada Acumulada; the row above
                                 counts the grades. They can disagree, and when
                                 they do that is information, not an error. -->
                            <p class="mb-3 text-xs leading-relaxed text-muted-foreground">
                                A menção onde cai a Média Ponderada Acumulada de cada aluno — o que o cálculo
                                diz, não o que foi atribuído.
                            </p>

                            <DistributionBands
                                v-if="distributionBands.length"
                                :bands="distributionBands"
                                :placed="placedOnScale"
                                :selected-key="selectedCalculatedLevelKey"
                                @select="toggleCalculatedLevel"
                            />
                            <p v-else class="rounded-xl bg-muted/25 py-8 text-center text-sm text-muted-foreground">
                                Sem bandas configuradas na escala.
                            </p>
                        </div>
                    </div>
                </section>
            </div>

            <!-- ============================= O RESULTADO, EM DESTAQUE -->
            <section
                class="relative overflow-hidden rounded-[22px] border border-emerald-200/60 bg-gradient-to-r from-emerald-50 via-emerald-50/60 to-teal-50/40 p-5 shadow-sm sm:p-6 dark:border-emerald-900/50 dark:from-emerald-950/40 dark:via-emerald-950/20 dark:to-teal-950/20"
            >
                <div class="flex flex-wrap items-center gap-x-8 gap-y-4">
                    <span
                        aria-hidden="true"
                        class="inline-flex size-12 shrink-0 items-center justify-center rounded-2xl bg-emerald-100/80 text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300"
                    >
                        <ChartNoAxesCombined class="size-6" />
                    </span>

                    <div class="min-w-0">
                        <p class="text-[3rem] font-semibold leading-none tabular-nums tracking-tight">
                            {{ pct(readingAverage) }}
                        </p>
                        <p class="mt-1.5 text-sm font-medium">{{ readingLabel }}</p>
                    </div>

                    <p class="max-w-sm text-xs leading-relaxed text-muted-foreground">
                        <template v-if="usingAccumulated">
                            É este o resultado que traduz a avaliação contínua até ao momento, e é sobre ele
                            que a classificação se decide.
                        </template>
                        <template v-else>
                            {{ readingCaption }} Leitura suplementar — a classificação acompanha o acumulado.
                        </template>
                    </p>

                    <!-- The class's own line through the year, on the figure
                         that answered at each moment. -->
                    <div v-if="trendPoints" class="ml-auto shrink-0">
                        <svg viewBox="0 0 100 44" class="h-12 w-40" role="img" :aria-label="trendSummary">
                            <polyline
                                :points="trendPoints"
                                fill="none"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                class="stroke-emerald-600 dark:stroke-emerald-400"
                            />
                            <circle
                                v-for="(point, index) in primaryTrend"
                                :key="point.label"
                                :cx="(index / (primaryTrend.length - 1)) * 100"
                                :cy="40 - (point.percent / 100) * 36"
                                r="2.5"
                                class="fill-emerald-600 dark:fill-emerald-400"
                            />
                        </svg>
                        <p class="mt-1 text-center text-[10px] text-muted-foreground">{{ trendLabel }}</p>
                    </div>

                    <!-- The other reading, deliberately small (§23) — and named
                         in full, so a number is never left to be guessed at. -->
                    <div
                        v-if="stats.primary.has_supplementary"
                        class="rounded-2xl bg-background/70 px-4 py-3 dark:bg-background/30"
                    >
                        <p class="text-[10px] font-semibold uppercase leading-tight tracking-[0.1em] text-muted-foreground">
                            {{ supplementaryLabel }}
                        </p>
                        <p class="mt-1 text-xl font-semibold tabular-nums">{{ pct(supplementaryAverage) }}</p>
                        <p v-if="readingGap !== null" class="mt-0.5 text-[10px] tabular-nums text-muted-foreground">
                            {{ readingGapCaption }}
                        </p>
                    </div>
                </div>
            </section>

            <!-- ==================== 05 · ONDE A TURMA SE ESPALHA -->
            <section v-if="spectrum.length" :class="CARD">
                <SectionHeading
                    index="05"
                    title="Onde a turma se espalha"
                    description="Cada aluno na sua Média Ponderada. Diz se estão juntos ou dispersos, e se a média descreve alguém. Não é uma ordenação."
                />
                <StudentSpectrum
                    :points="spectrum"
                    :average-percent="stats.summary.class_average === null ? null : Number(stats.summary.class_average)"
                    :average-display="pct(stats.summary.class_average)"
                    :without-result="stats.summary.students_without_result"
                    @select="openStudentById"
                />
            </section>

            <!-- ============================ 06 · DIFERENÇAS ENTRE DOMÍNIOS -->
            <section :class="CARD">
                <SectionHeading
                    index="06"
                    title="Onde estão as diferenças entre domínios"
                    description="Na ordem do perfil de avaliação, e não por resultado. Escolha um domínio para o seguir na página."
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

                <!-- Wide bars, in real DOM: every value is text, so this needs
                     no parallel table. Domain ink, scale mention, trend change
                     — three statements that never borrow each other's colour. -->
                <DomainBars :bars="domainBars" :selected-id="selectedDomainId" @select="toggleDomain" />
            </section>

            <!-- =========================== 07 · COMO MUDARAM AO LONGO DO ANO -->
            <section v-if="trendShape !== 'single'" :class="CARD">
                <SectionHeading
                    index="07"
                    title="Como mudaram ao longo do ano"
                    :description="trendShape === 'slope'
                        ? `Do ${slopeEnds?.from.label} ao ${slopeEnds?.to.label}, cada período por si.`
                        : 'Cada período por si, sem o acumulado — um acumulado inclina-se para a sua própria história.'"
                />

                <!-- TWO MOMENTS GET DUMBBELLS, not a tall slopegraph spending
                     most of its height on air. One line per series: where it
                     started, where it ended, and the distance between — which
                     is the whole question. Three or more periods get the line
                     chart, because then there is a trend to draw. -->
                <div v-if="trendShape === 'slope'" class="grid gap-5 lg:grid-cols-5">
                    <!-- The class itself, at full size: it is the headline. -->
                    <div :class="[INSET, 'flex flex-col justify-center p-4 lg:col-span-2']">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ slopeEnds!.from.label }}
                        </p>
                        <p class="mt-0.5 text-xl font-semibold tabular-nums text-muted-foreground">
                            {{ pct(slopeEnds!.from.class_average) }}
                        </p>

                        <p
                            class="my-2 flex items-center gap-2 text-2xl font-semibold tabular-nums"
                            :class="Number(stats.evolution.average_change) > 0 ? 'text-emerald-700 dark:text-emerald-400'
                                : Number(stats.evolution.average_change) < 0 ? 'text-rose-700 dark:text-rose-400' : ''"
                        >
                            <span aria-hidden="true" class="text-muted-foreground/50">↓</span>
                            {{ formatPoints(classPeriodChange) }}
                            <span class="text-sm font-normal text-muted-foreground">p.p.</span>
                        </p>

                        <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                            {{ slopeEnds!.to.label }}
                        </p>
                        <p class="mt-0.5 text-[2rem] font-semibold leading-none tabular-nums tracking-tight">
                            {{ pct(slopeEnds!.to.class_average) }}
                        </p>
                    </div>

                    <div class="lg:col-span-3">
                        <DumbbellRows
                            :rows="domainDumbbells"
                            :from-label="slopeEnds!.from.label"
                            :to-label="slopeEnds!.to.label"
                            :selected-id="selectedDomainId"
                            @select="toggleDomain"
                        />
                    </div>
                </div>

                <div v-else class="grid gap-6 lg:grid-cols-2">
                    <div>
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">A turma</p>
                        <StatChart
                            :config="classTrendChart"
                            :tooltip="classTrendTooltip"
                            summary="Média Ponderada da turma em cada período do ano letivo."
                            :headers="['Período', 'Média Ponderada']"
                            :rows="stats.period_series.map((row) => [row.label, pct(row.class_average)])"
                            height-class="h-72"
                        />
                    </div>

                    <div>
                        <p class="mb-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Cada domínio</p>
                        <StatChart
                            :config="domainSeriesChart"
                            :tooltip="domainSeriesTooltip"
                            summary="Média da turma em cada domínio, ao longo dos períodos do ano letivo."
                            :headers="['Período', 'Turma', ...stats.domains.map((domain) => domain.name)]"
                            :rows="seriesRows"
                            height-class="h-72"
                        />

                        <!-- The legend IS the domain selector — one control, in
                             the same inks the lines are drawn in. -->
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
                    </div>
                </div>
            </section>

            <!-- ================================== 08 · O MAPA DA TURMA -->
            <section :class="CARD">
                <SectionHeading
                    index="08"
                    title="Mapa da turma"
                    :description="`${usingAccumulated ? 'Resultado acumulado' : 'Só este período'} de cada aluno em cada domínio. O valor está sempre escrito — a cor só o reforça.`"
                >
                    <template #aside>
                        <p v-if="selectedLevel" class="text-xs text-muted-foreground">
                            A realçar {{ studentsWord(highlightedStudents) }} com «{{ selectedLevel.label }}» atribuído
                        </p>
                        <p v-else-if="selectedCalculatedLevel" class="text-xs text-muted-foreground">
                            A realçar {{ studentsWord(highlightedStudents) }} cuja média cai em «{{ selectedCalculatedLevel.label }}»
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
                                        {{ pct(heatValue(student, domain.id)) }}
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
                        <!-- THE RESULT OF THE MOMENT FIRST, at full size, and
                             the period's own figure beside it in a quieter box
                             — so the difference between the two is the first
                             thing a teacher sees rather than something to work
                             out (§28). -->
                        <div
                            class="rounded-xl border border-violet-200/70 bg-violet-50/60 p-3 dark:border-violet-900/50 dark:bg-violet-950/25"
                        >
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">
                                {{ stats.primary.kind === 'accumulated' ? 'Avaliação contínua' : 'Resultado' }}
                            </p>
                            <p class="mt-0.5 text-2xl font-semibold tabular-nums">{{ pct(selected.primary_average) }}</p>
                        </div>
                        <div v-if="stats.primary.has_supplementary" class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">
                                {{ stats.primary.supplementary_label }}
                            </p>
                            <p class="mt-0.5 text-xl font-semibold tabular-nums text-muted-foreground">
                                {{ pct(selected.supplementary_average) }}
                            </p>
                        </div>
                        <div class="rounded-xl border border-border p-3">
                            <p class="text-[11px] uppercase tracking-wider text-muted-foreground">
                                {{ stats.primary.has_supplementary ? 'Evolução contínua' : 'Evolução' }}
                            </p>
                            <p
                                class="mt-0.5 text-xl font-semibold tabular-nums"
                                :class="studentMovement?.direction === 'up' ? 'text-emerald-600 dark:text-emerald-400'
                                    : studentMovement?.direction === 'down' ? 'text-rose-600 dark:text-rose-400' : ''"
                            >
                                {{ studentMovement ? formatPoints(studentMovement.points) : '—' }}
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

                    <!-- CROSSING THE LINE IS SAID OUT LOUD, and only when it
                         happened. The same iconography and the same words the
                         class board uses, so «passou a positivo» means one thing
                         on this page (§22). -->
                    <p
                        v-if="selected.transition === 'failure_to_success' || selected.transition === 'success_to_failure'"
                        class="mt-3 flex items-center gap-2.5 rounded-xl border px-3.5 py-2.5 text-sm font-medium"
                        :class="selected.transition === 'failure_to_success'
                            ? 'border-emerald-200/70 bg-gradient-to-br from-emerald-50 via-teal-50 to-emerald-100/80 text-emerald-800 dark:border-emerald-800/50 dark:from-emerald-950/55 dark:via-teal-950/35 dark:to-emerald-900/30 dark:text-emerald-200'
                            : 'border-rose-200/70 bg-gradient-to-br from-rose-50 via-orange-50/70 to-rose-100/70 text-rose-800 dark:border-rose-800/50 dark:from-rose-950/55 dark:via-orange-950/30 dark:to-rose-900/30 dark:text-rose-200'"
                    >
                        <component
                            :is="selected.transition === 'failure_to_success' ? ArrowUpRight : ArrowDownRight"
                            aria-hidden="true"
                            class="size-4 shrink-0"
                        />
                        <span v-if="selected.transition === 'failure_to_success'">
                            Passou para {{ threshold.at_or_above }} face a {{ stats.previous_period?.label }}.
                        </span>
                        <span v-else>
                            Passou para {{ threshold.below }} face a {{ stats.previous_period?.label }}.
                        </span>
                    </p>

                    <section class="mt-6">
                        <h3 class="mb-1 text-sm font-semibold">Como evoluiu ao longo do ano</h3>
                        <p class="mb-3 text-[11px] text-muted-foreground">
                            O resultado que respondia em cada momento — a partir do segundo, o acumulado.
                        </p>

                        <!-- ONE MOMENT IS A NUMBER, NOT A TREND. Two get the
                             two ends stated plainly; three or more get the line
                             (§3). Nothing is drawn for a period with no result:
                             a blank is not a zero and never a fall (§8). -->
                        <div v-if="studentShape === 'single'" :class="[INSET, 'px-4 py-3 text-sm text-muted-foreground']">
                            <template v-if="studentMoments.length === 1">
                                Um único momento com resultado ({{ studentMoments[0].period_label }}) — ainda não há
                                evolução para mostrar.
                            </template>
                            <template v-else>Ainda não há resultados para este aluno.</template>
                        </div>

                        <div v-else-if="studentShape === 'slope'" :class="[INSET, 'flex items-center gap-4 px-4 py-3']">
                            <div>
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ studentEnds!.from.period_label }}
                                </p>
                                <p class="text-xl font-semibold tabular-nums text-muted-foreground">
                                    {{ pct(studentEnds!.from.primary_average) }}
                                </p>
                            </div>

                            <p
                                class="flex items-center gap-1 text-sm font-medium tabular-nums"
                                :class="Number(studentChange) > 0 ? 'text-emerald-700 dark:text-emerald-400'
                                    : Number(studentChange) < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                            >
                                <span aria-hidden="true">{{ Number(studentChange) > 0 ? '↗' : Number(studentChange) < 0 ? '↘' : '→' }}</span>
                                {{ formatPoints(studentChange) }} p.p.
                            </p>

                            <div class="ml-auto text-right">
                                <p class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                    {{ studentEnds!.to.period_label }}
                                </p>
                                <p class="text-[1.75rem] font-semibold leading-none tabular-nums tracking-tight">
                                    {{ pct(studentEnds!.to.primary_average) }}
                                </p>
                            </div>
                        </div>

                        <ul v-else class="space-y-1">
                            <li
                                v-for="moment in studentMoments"
                                :key="moment.period_id"
                                class="flex items-baseline gap-3 rounded-lg px-2 py-1.5 text-sm odd:bg-muted/25"
                            >
                                <span class="text-muted-foreground">{{ moment.period_label }}</span>
                                <span class="ml-auto font-semibold tabular-nums">{{ pct(moment.primary_average) }}</span>
                                <span
                                    v-if="moment.evolution"
                                    class="w-20 text-right text-xs tabular-nums"
                                    :class="moment.evolution.direction === 'up' ? 'text-emerald-700 dark:text-emerald-400'
                                        : moment.evolution.direction === 'down' ? 'text-rose-700 dark:text-rose-400' : 'text-muted-foreground'"
                                >
                                    {{ moment.evolution.direction === 'up' ? '↑' : moment.evolution.direction === 'down' ? '↓' : '→' }}
                                    {{ formatPoints(moment.evolution.points) }}
                                </span>
                                <span v-else class="w-20 text-right text-[11px] text-muted-foreground">—</span>
                            </li>
                        </ul>

                        <h3 class="mb-3 mt-5 text-sm font-semibold">Em que domínios melhorou ou regrediu</h3>

                        <DumbbellRows
                            v-if="studentEnds"
                            :rows="studentDomainDumbbells"
                            :from-label="studentEnds.from.period_label"
                            :to-label="studentEnds.to.period_label"
                        />

                        <!-- With only one moment there is nothing to join, so
                             the domains are read as they stand. -->
                        <StatChart
                            v-else-if="studentChart"
                            :config="studentChart"
                            :tooltip="studentTooltip"
                            :summary="`Média de ${selected.name} em cada domínio, no período e acumulada.`"
                            :headers="['Domínio', 'Média do período', 'Acumulada', 'Menção', 'Autoavaliação']"
                            :rows="studentRows"
                            height-class="h-64"
                            depth
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
