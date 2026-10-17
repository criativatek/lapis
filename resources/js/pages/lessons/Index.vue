<script setup lang="ts">
/**
 * AULAS E SUMÁRIOS — preparar e rever os sumários da semana sem abrir aula a
 * aula (0.158.0).
 *
 * Três vistas sobre as MESMAS aulas: Semana (por dia, cartões largos, sumários
 * completos), Por turma (a sequência de uma turma, com o sumário anterior de
 * cada grupo) e Horário (a grelha pelas horas reais). O estado de leitura —
 * vista, filtros, densidade, turma, grupo, intervalo — vive no URL
 * (`lib/lessonWeekView`): abrir uma aula e voltar regressa ao mesmo sítio.
 *
 * O sumário edita-se no próprio cartão e grava SÓ o sumário
 * (`PATCH lessons/{lesson}/summary/content`, com a versão lida). Uma gravação
 * recusada por conflito com outra janela abre a comparação; nada se perde.
 *
 * PESQUISA NOS SUMÁRIOS (0.162.0): «Pesquisar nos sumários…» procura em todo o
 * ano letivo, no servidor (`GET lessons/search`), e mostra os resultados no
 * lugar do calendário. O estado da pesquisa vive no URL POR CIMA do do
 * calendário (`lib/lessonSummarySearch`) — sair dela é só retirar os `q*`.
 *
 * A página está arrumada por função, de cima para baixo: cabeçalho (título,
 * «Inserir aula», «Mais ações»), consulta (vista e pesquisa), filtros,
 * navegação temporal junto do calendário, e o conteúdo.
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BookOpen,
    CalendarDays,
    CheckCheck,
    CheckSquare,
    ChevronLeft,
    ChevronRight,
    Ellipsis,
    GitCommitVertical,
    LayoutGrid,
    ListOrdered,
    RefreshCw,
    Rows3,
    X,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import ActivitySessionCard from '@/components/activity-sessions/ActivitySessionCard.vue';
import ActivitySessionDialog from '@/components/activity-sessions/ActivitySessionDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import ActivityPostponeDialog from '@/components/lessons/ActivityPostponeDialog.vue';
import BatchTaughtDialog from '@/components/lessons/BatchTaughtDialog.vue';
import InsertLessonDialog from '@/components/lessons/InsertLessonDialog.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { SpecialLessonOutcome } from '@/components/lessons/LessonOutcomeDialog.vue';
import LessonQuickActions from '@/components/lessons/LessonQuickActions.vue';
import LessonTimetable from '@/components/lessons/LessonTimetable.vue';
import LessonSearchField from '@/components/lessons/search/LessonSearchField.vue';
import LessonSearchFilters from '@/components/lessons/search/LessonSearchFilters.vue';
import LessonSearchResults from '@/components/lessons/search/LessonSearchResults.vue';
import UnsavedChangesDialog from '@/components/lessons/UnsavedChangesDialog.vue';
import LessonClassView from '@/components/lessons/week/LessonClassView.vue';
import LessonDayNav from '@/components/lessons/week/LessonDayNav.vue';
import type { DayNavItem } from '@/components/lessons/week/LessonDayNav.vue';
import LessonProjection from '@/components/lessons/week/LessonProjection.vue';
import LessonSummaryEditor from '@/components/lessons/week/LessonSummaryEditor.vue';
import LessonWeekCard from '@/components/lessons/week/LessonWeekCard.vue';
import LessonWeekFilters from '@/components/lessons/week/LessonWeekFilters.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useSummarySearch } from '@/composables/useSummarySearch';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import { compareActivitySessions } from '@/lib/activitySessions';
import type { ActivitySessionItem } from '@/lib/activitySessions';
import {
    consumeLessonsStale,
    hasPendingConfirmations,
    hydrateConfirmedSummaries,
    pruneCaughtUp,
    recordConfirmedSummary,
    withConfirmedSummaries,
    withConfirmedSummary,
} from '@/lib/confirmedSummaries';
import {
    addDays,
    dayHeading,
    dayMonthLabel,
    dayNumber,
    weekdayAbbr,
    weekRangeLabel,
} from '@/lib/lessonDates';
import {
    canSetLessonNumber,
    NO_NUMBER_ERRORS,
    numberDraftDirty,
    numberDraftFromLesson,
    numberErrorsFrom,
    numberSavePayload,
    previewNeedsConfirmation,
    requestNumberPreview,
    validateNumberDraft,
} from '@/lib/lessonNumber';
import type {
    LessonNumberDraft,
    LessonNumberErrors,
    LessonNumberPreview,
} from '@/lib/lessonNumber';
import { projectionFor } from '@/lib/lessonProjection';
import {
    isQuickClosable,
    isTaughtLesson,
    lessonClock,
    lessonDate,
    lessonTimeRange,
} from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';
import {
    isSearchableTerm,
    needsSubject,
    parseSummarySearchState,
    rememberSummarySearchStates,
    storedSummarySearchStates,
    summarySearchScope,
    summarySearchUrl,
} from '@/lib/lessonSummarySearch';
import type { SummarySearchState } from '@/lib/lessonSummarySearch';
import {
    activeFilterCount,
    matchesSecondaryFilters,
    matchesWeekFilters,
    parseWeekViewState,
    weekFacts,
    weekViewUrl,
} from '@/lib/lessonWeekView';
import type {
    ClassViewData,
    TeacherClass,
    WeekView,
    WeekViewState,
} from '@/lib/lessonWeekView';
import { combineSummaries } from '@/lib/summaryMerge';
import { resolveTurmaTones } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

type InsertableClass = {
    ulid: string;
    label: string;
    subject: string;
    groups: { id: number; label: string }[];
};

const props = withDefaults(
    defineProps<{
        lessons: WeekLesson[];
        /**
         * As sessões das «outras atividades» desta semana (0.170.0) — PROP
         * PRÓPRIA. `lessons` é só aulas: as sessões não entram em contadores,
         * lote, filtros de estado, pesquisa, numeração nem «Aulas de hoje».
         */
        activitySessions?: ActivitySessionItem[];
        week: { start: string; end: string };
        academicYear: string | null;
        configuredClassesCount: number;
        today: string;
        insertableClasses: InsertableClass[];
        /** As categorias de «Professor ausente» — uma lista para a página, não uma por cartão. */
        absenceReasons: { value: string; label: string }[];
        /** As turmas do professor no ano letivo, com o tom guardado de cada uma. */
        classes?: TeacherClass[];
        /** Só na vista por turma — montada no servidor, só de leitura. */
        classView?: ClassViewData | null;
        /** Os limites do ano letivo selecionado — as datas do filtro da pesquisa. */
        academicYearBounds?: { start: string; end: string } | null;
    }>(),
    {
        activitySessions: () => [],
        classes: () => [],
        classView: null,
        academicYearBounds: null,
    },
);

// ------------------------------------------- aulas com as gravações confirmadas
//
// UM só sítio onde as props das aulas passam a ser lidas: todas as vistas
// (Semana, Por turma incl. «Últimos sumários», Horário, projeção, editor) usam
// `weekLessons` e `classViewData`, nunca `props.lessons` / `props.classView`.
// Uma gravação confirmada tem uma `summary_version`; props com versão menor
// (histórico do Inertia, resposta atrasada) não repõem o texto anterior.
hydrateConfirmedSummaries();

const weekLessons = computed(() => withConfirmedSummaries(props.lessons));
const classViewData = computed<ClassViewData | null>(() => {
    const view = props.classView;

    if (view === null) {
        return null;
    }

    return {
        ...view,
        lessons: withConfirmedSummaries(view.lessons),
        previous: view.previous.map((entry) => ({
            ...entry,
            lesson: entry.lesson ? withConfirmedSummary(entry.lesson) : null,
        })),
    };
});

/** Quando TODAS as ocorrências de uma aula nas props já alcançaram a gravação, esquece-a. */
watch(
    () => [props.lessons, props.classView] as const,
    () => {
        const oldest = new Map<string, number>();

        for (const lesson of [
            ...props.lessons,
            ...(props.classView?.lessons ?? []),
            ...(props.classView?.previous.flatMap((entry) =>
                entry.lesson ? [entry.lesson] : [],
            ) ?? []),
        ]) {
            oldest.set(
                lesson.ulid,
                Math.min(
                    oldest.get(lesson.ulid) ?? Number.POSITIVE_INFINITY,
                    lesson.summary_version,
                ),
            );
        }

        pruneCaughtUp(
            [...oldest].map(([ulid, summary_version]) => ({
                ulid,
                summary_version,
            })),
        );
    },
    { immediate: true },
);

const page = usePage();
const state = computed<WeekViewState>(() => parseWeekViewState(page.url));
const view = computed<WeekView>(() => state.value.view);

/**
 * A pesquisa nos sumários, quando há uma no URL (e um ano letivo onde
 * procurar). As turmas que esta página não conhece (uma ligação antiga, uma
 * turma de outro ano) saem logo aqui: o que se pede, o que se escreve no
 * âmbito e as turmas marcadas são sempre a mesma coisa.
 */
const searchState = computed<SummarySearchState | null>(() => {
    const parsed = props.academicYear
        ? parseSummarySearchState(page.url)
        : null;

    if (parsed === null) {
        return null;
    }

    const known = new Set(props.classes.map((schoolClass) => schoolClass.ulid));

    return {
        ...parsed,
        classes: parsed.classes.filter((ulid) => known.has(ulid)),
    };
});
const searching = computed(() => searchState.value !== null);

/**
 * «Agora», reativo (0.147.0). Atualizado a cada minuto para que uma aula que
 * termina com a página aberta passe a pedir confirmação sem recarregar. É só
 * apresentação: o servidor não lê este relógio para nada.
 */
const now = ref(new Date());
let clock: ReturnType<typeof setInterval> | null = null;

// --------------------------------------------------------------- turmas e tons

/** As turmas que esta página conhece: as do professor e, por segurança, as das aulas. */
const knownClasses = computed(() => {
    const byUlid = new Map<
        string,
        {
            ulid: string;
            label: string;
            identity_tone: string | null;
            archived: boolean;
        }
    >();

    for (const schoolClass of props.classes) {
        byUlid.set(schoolClass.ulid, {
            ulid: schoolClass.ulid,
            label: schoolClass.label,
            identity_tone: schoolClass.identity_tone,
            archived: schoolClass.archived,
        });
    }

    for (const lesson of [
        ...weekLessons.value,
        ...(classViewData.value?.lessons ?? []),
    ]) {
        if (!byUlid.has(lesson.school_class.ulid)) {
            byUlid.set(lesson.school_class.ulid, {
                ulid: lesson.school_class.ulid,
                label: lesson.school_class.label,
                identity_tone: lesson.identity_tone,
                archived: false,
            });
        }
    }

    return [...byUlid.values()];
});

const tones = computed<Map<string, TurmaTone>>(() =>
    resolveTurmaTones(knownClasses.value),
);

function toneOf(lesson: WeekLesson): TurmaTone {
    return tones.value.get(lesson.school_class.ulid) ?? 'blue';
}

/** Os filtros de turma: as ativas e, das arquivadas, só as que têm aulas nesta semana. */
const filterClasses = computed(() =>
    knownClasses.value
        .filter(
            (schoolClass) =>
                !schoolClass.archived ||
                weekLessons.value.some(
                    (lesson) => lesson.school_class.ulid === schoolClass.ulid,
                ),
        )
        .sort((a, b) =>
            a.label.localeCompare(b.label, 'pt-PT', { numeric: true }),
        )
        .map((schoolClass) => ({
            ...schoolClass,
            tone: tones.value.get(schoolClass.ulid) ?? ('blue' as TurmaTone),
        })),
);

/** As turmas da vista por turma — as que o servidor enviou (as do professor neste ano). */
const pickableClasses = computed<TeacherClass[]>(() =>
    props.classes.filter(
        (schoolClass) =>
            !schoolClass.archived ||
            schoolClass.ulid === classViewData.value?.class.ulid,
    ),
);

// ------------------------------------------------------------------- navegação

/** Uma aula que se pediu para ver (vinda do Horário) aparece mesmo fora dos filtros. */
const pinnedLesson = ref<string | null>(null);
const cameFromTimetable = ref(false);
let timetableScroll: number | null = null;

function go(
    patch: Partial<WeekViewState>,
    options: { server?: boolean; push?: boolean } = {},
): void {
    const url = weekViewUrl({ ...state.value, ...patch }, props.week.start);

    if (options.server) {
        router.get(
            url,
            {},
            {
                preserveState: true,
                preserveScroll: true,
                replace: !options.push,
                only: ['classView', 'classes'],
            },
        );

        return;
    }

    if (options.push) {
        router.push({ url, preserveState: true, preserveScroll: true });
    } else {
        router.replace({ url, preserveState: true, preserveScroll: true });
    }
}

function setView(next: WeekView): void {
    if (next === view.value) {
        return;
    }

    guard.request(() => {
        editor.value = null;
        cameFromTimetable.value = false;
        pinnedLesson.value = null;

        if (next === 'turma') {
            go(
                {
                    view: 'turma',
                    classUlid:
                        state.value.classUlid ??
                        classViewData.value?.class.ulid ??
                        null,
                },
                { server: true, push: true },
            );
        } else {
            go({ view: next }, { push: true });
        }

        rememberView(next);
    });
}

function updateFilters(patch: Partial<WeekViewState>): void {
    guard.request(() => {
        pinnedLesson.value = null;
        go(patch);
    });
}

function setDensity(density: WeekViewState['density']): void {
    expanded.value = [];
    go({ density });
}

function updateClassView(patch: {
    classUlid?: string;
    group?: string;
    range?: WeekViewState['range'];
}): void {
    guard.request(() => {
        editor.value = null;
        go(patch, { server: true });
    });
}

function goToWeek(week: string): void {
    guard.request(() => {
        editor.value = null;
        cameFromTimetable.value = false;
        router.get(
            weekViewUrl(state.value, week),
            {},
            { preserveState: true, preserveScroll: week !== props.today },
        );
    });
}

function shiftWeek(days: number): void {
    goToWeek(addDays(props.week.start, days));
}

/**
 * A vista escolhida fica lembrada neste browser, para quem prefere o Horário não
 * ter de o escolher a cada visita. O URL manda sempre que diz uma vista.
 */
const VIEW_KEY = 'lapis.lessons.view';

function rememberView(next: WeekView): void {
    try {
        window.localStorage.setItem(VIEW_KEY, next);
    } catch {
        // Sem persistência não se perde nada: a vista continua a funcionar.
    }
}

function storedView(): WeekView | null {
    try {
        const stored = window.localStorage.getItem(VIEW_KEY);

        if (stored === 'timetable' || stored === 'horario') {
            return 'horario';
        }

        if (stored === 'turma') {
            return 'turma';
        }

        return null;
    } catch {
        return null;
    }
}

// --------------------------------------------------------- regressar da aula

const RETURN_KEY = 'lapis.lessons.return';
const highlighted = ref<string | null>(null);

/**
 * Antes de abrir uma aula: de onde se saiu, para o «Voltar» da aula regressar
 * aqui — também aos resultados de uma pesquisa, cujo estado vai no URL.
 */
function rememberReturn(lesson: { ulid: string }): void {
    try {
        window.sessionStorage.setItem(
            RETURN_KEY,
            JSON.stringify({ url: page.url, lesson: lesson.ulid }),
        );
    } catch {
        // Sem sessionStorage o «Voltar» cai na semana da aula, como sempre.
    }
}

function restoreFromLesson(): void {
    let entry: { url?: string; lesson?: string } | null = null;

    try {
        entry = JSON.parse(window.sessionStorage.getItem(RETURN_KEY) ?? 'null');
    } catch {
        entry = null;
    }

    if (!entry?.lesson || entry.url !== page.url) {
        return;
    }

    try {
        window.sessionStorage.removeItem(RETURN_KEY);
    } catch {
        // nada a limpar
    }

    const ulid = entry.lesson;

    // O scroll é reposto pelo histórico do Inertia; aqui só se devolve o foco
    // ao cartão de onde se saiu, sem o deslocar. Na pesquisa, o cartão é o do
    // resultado (os últimos resultados estão na sessão e já foram desenhados).
    window.requestAnimationFrame(() => {
        document
            .querySelector<HTMLElement>(
                `#aula-${ulid} [data-testid="open-lesson"], #resultado-${ulid} [data-testid="open-lesson"]`,
            )
            ?.focus({ preventScroll: true });
        highlighted.value = ulid;
        window.setTimeout(() => (highlighted.value = null), 1600);
    });
}

// ------------------------------------------------------------ vista Semana

const visibleWeekLessons = computed(() =>
    weekLessons.value.filter(
        (lesson) =>
            lesson.ulid === pinnedLesson.value ||
            matchesWeekFilters(lesson, state.value, now.value),
    ),
);

// ------------------------------------- sessões das «outras atividades» (0.170.0)
//
// Vivem ao lado das aulas na vista Semana, e SÓ nela. Nunca entram em
// `weekLessons`, nos contadores (`facts`, cabeçalho do dia), no fecho em lote,
// nos filtros de estado/atenção/sumário nem na pesquisa. O único filtro que as
// toca é o da turma: com turmas escolhidas, só as sessões dessas turmas.

const weekSessions = computed(() =>
    [...props.activitySessions].sort(compareActivitySessions),
);

const visibleSessions = computed(() =>
    weekSessions.value.filter(
        (session) =>
            state.value.classes.length === 0 ||
            (session.school_class !== null &&
                state.value.classes.includes(session.school_class.ulid)),
    ),
);

type DayEntry =
    | { kind: 'lesson'; key: string; startsAt: number; lesson: WeekLesson }
    | {
          kind: 'session';
          key: string;
          startsAt: number;
          session: ActivitySessionItem;
      };

const days = computed(() => {
    const dates = [
        ...new Set([
            ...visibleWeekLessons.value.map(lessonDate),
            ...visibleSessions.value.map((session) => session.occurs_on),
        ]),
    ].sort();

    return dates.map((date) => {
        const lessons = visibleWeekLessons.value.filter(
            (lesson) => lessonDate(lesson) === date,
        );
        const sessions = visibleSessions.value.filter(
            (session) => session.occurs_on === date,
        );

        // ORDEM EXPLÍCITA POR INÍCIO: o servidor não junta nem desempata
        // aulas e sessões, e a ordem de `lessons` é a que `days` recebe.
        // Empate: as aulas primeiro, depois as sessões, cada grupo pela sua
        // ordem (o sort é estável).
        const entries: DayEntry[] = [
            ...lessons.map((lesson): DayEntry => ({
                kind: 'lesson',
                key: `lesson-${lesson.ulid}`,
                startsAt: Date.parse(lesson.starts_at),
                lesson,
            })),
            ...sessions.map((session): DayEntry => ({
                kind: 'session',
                key: session.key,
                startsAt: Date.parse(session.starts_at),
                session,
            })),
        ].sort((a, b) => a.startsAt - b.startsAt);

        return {
            date,
            lessons,
            sessions,
            entries,
            all: weekLessons.value.filter(
                (lesson) => lessonDate(lesson) === date,
            ),
        };
    });
});

const sessionDialogOpen = ref(false);
const openedSession = ref<ActivitySessionItem | null>(null);

function openSession(session: ActivitySessionItem): void {
    openedSession.value = session;
    sessionDialogOpen.value = true;
}

const facts = computed(() => weekFacts(weekLessons.value, now.value));

// ---------------------------------------------------------- vista Por turma

const classViewLessons = computed(() =>
    (classViewData.value?.lessons ?? []).filter(
        (lesson) =>
            lesson.ulid === pinnedLesson.value ||
            matchesSecondaryFilters(lesson, state.value, now.value),
    ),
);

// ------------------------------------------------------------ atalhos de dias

const dayNavItems = computed<DayNavItem[]>(() => {
    if (view.value === 'semana') {
        return days.value.map((day) => ({
            target: `dia-${day.date}`,
            label: weekdayAbbr(day.date),
            sub: dayNumber(day.date),
            today: day.date === props.today,
            description: `${dayHeading(day.date)}${day.date === props.today ? ' (hoje)' : ''}`,
        }));
    }

    if (view.value === 'turma' && classViewData.value) {
        const lessons = classViewLessons.value;

        if (classViewData.value.range.key === '1') {
            return [...new Set(lessons.map(lessonDate))].map((date) => ({
                target: `dia-turma-${date}`,
                label: weekdayAbbr(date),
                sub: dayNumber(date),
                today: date === props.today,
                description: `${dayHeading(date)}${date === props.today ? ' (hoje)' : ''}`,
            }));
        }

        const weeks: string[] = [];
        let start = classViewData.value.range.start;
        const weekday = new Date(`${start}T12:00:00Z`).getUTCDay();
        start = addDays(start, weekday === 0 ? -6 : 1 - weekday);

        while (start <= classViewData.value.range.end) {
            weeks.push(start);
            start = addDays(start, 7);
        }

        return weeks.map((weekStart) => ({
            target: `semana-turma-${weekStart}`,
            label: 'sem.',
            sub: dayMonthLabel(weekStart),
            description: `Semana de ${weekRangeLabel(weekStart, addDays(weekStart, 6))}`,
        }));
    }

    return [];
});

// ---------------------------------------------------- Horário → ler na semana

function readInWeek(lesson: WeekLesson): void {
    guard.request(() => {
        timetableScroll = window.scrollY;
        pinnedLesson.value = matchesWeekFilters(lesson, state.value, now.value)
            ? null
            : lesson.ulid;
        go({ view: 'semana' }, { push: true });
        cameFromTimetable.value = true;

        nextTick(() =>
            window.requestAnimationFrame(() => {
                const card = document.getElementById(`aula-${lesson.ulid}`);
                card?.scrollIntoView({ block: 'start' });
                card?.querySelector<HTMLElement>(
                    `[data-testid="edit-${lesson.ulid}"]`,
                )?.focus({ preventScroll: true });
                highlighted.value = lesson.ulid;
                window.setTimeout(() => (highlighted.value = null), 1600);
            }),
        );
    });
}

function backToTimetable(): void {
    guard.request(() => {
        cameFromTimetable.value = false;
        pinnedLesson.value = null;
        window.history.back();
    });
}

// O regresso ao Horário repõe a posição em que se estava, mesmo que o histórico não o faça.
watch(view, (current, previous) => {
    if (
        current === 'horario' &&
        previous === 'semana' &&
        timetableScroll !== null
    ) {
        const target = timetableScroll;
        timetableScroll = null;
        nextTick(() =>
            window.requestAnimationFrame(() =>
                window.scrollTo({ top: target }),
            ),
        );
    }

    if (current !== 'semana') {
        cameFromTimetable.value = false;
    }
});

// ------------------------------------------------------------- texto compacto

const expanded = ref<string[]>([]);

function toggleExpanded(ulid: string): void {
    expanded.value = expanded.value.includes(ulid)
        ? expanded.value.filter((value) => value !== ulid)
        : [...expanded.value, ulid];
}

// -------------------------------------------------------- editar no cartão

type EditorState = {
    ulid: string;
    draft: string;
    original: string;
    baseVersion: number;
    saving: boolean;
    error: string | null;
    notice: string | null;
    conflict: { stored: string; version: number } | null;
    before: Pick<WeekLesson, 'status' | 'outcome'>;
    // O número da lição (0.169.0): o rascunho, o ponto de partida, os erros
    // junto do campo e a pré-visualização que espera confirmação.
    number: LessonNumberDraft;
    numberOriginal: LessonNumberDraft;
    numberErrors: LessonNumberErrors;
    numberPreview: LessonNumberPreview | null;
};

const editor = ref<EditorState | null>(null);
const editorComponent = ref<InstanceType<typeof LessonSummaryEditor> | null>(
    null,
);
const savedNotes = ref<
    Record<string, { time: string; transition: string | null }>
>({});

function setEditorComponent(element: unknown): void {
    editorComponent.value =
        (element as InstanceType<typeof LessonSummaryEditor> | null) ?? null;
}

function findLesson(ulid: string): WeekLesson | null {
    return (
        weekLessons.value.find((lesson) => lesson.ulid === ulid) ??
        classViewData.value?.lessons.find((lesson) => lesson.ulid === ulid) ??
        null
    );
}

/** A aula tal como veio do servidor, sem a sobreposição das gravações confirmadas. */
function rawLesson(ulid: string): WeekLesson | null {
    return (
        props.lessons.find((lesson) => lesson.ulid === ulid) ??
        props.classView?.lessons.find((lesson) => lesson.ulid === ulid) ??
        null
    );
}

// O número conta como alteração por guardar (cartão e guarda de navegação),
// mas só numa aula que o admite: professor ausente não tem lição.
function numberIsDirty(): boolean {
    const current = editor.value;
    const lesson = current === null ? null : findLesson(current.ulid);

    return (
        current !== null &&
        lesson !== null &&
        canSetLessonNumber(lesson) &&
        numberDraftDirty(current.number, current.numberOriginal)
    );
}

function isDirty(): boolean {
    return (
        editor.value !== null &&
        (editor.value.draft !== editor.value.original || numberIsDirty())
    );
}

const editingLesson = computed(() =>
    editor.value ? findLesson(editor.value.ulid) : null,
);
const editingContext = computed(() =>
    editingLesson.value
        ? `${editingLesson.value.context_label} (${dayHeading(lessonDate(editingLesson.value))}, ${lessonTimeRange(editingLesson.value)})`
        : null,
);

// ---------------------------------------------- projetar o sumário na sala
//
// Abrir e fechar a projeção não é navegação: não passa pela guarda, não grava,
// não fecha o editor e não toca no URL. Mostra o texto que está no editor
// (marcado «Por guardar») ou o guardado, e nada mais.

const projected = ref<string | null>(null);
const projectionLesson = computed<WeekLesson | null>(() => {
    if (projected.value === null) {
        return null;
    }

    return (
        findLesson(projected.value) ??
        classViewData.value?.previous
            .map((entry) => entry.lesson)
            .find((lesson) => lesson?.ulid === projected.value) ??
        null
    );
});
const projection = computed(() => {
    const lesson = projectionLesson.value;

    if (lesson === null) {
        return null;
    }

    return projectionFor(
        lesson,
        editor.value?.ulid === lesson.ulid
            ? { text: editor.value.draft, dirty: isDirty() }
            : null,
    );
});

function openProjection(lesson: WeekLesson): void {
    projected.value = lesson.ulid;
}

function setProjectionOpen(value: boolean): void {
    if (!value) {
        projected.value = null;
    }
}

// Se a aula projetada deixa de existir (outra semana, outro filtro), fecha.
watch(projectionLesson, (lesson) => {
    if (lesson === null) {
        projected.value = null;
    }
});

function focusEditor(): void {
    nextTick(() => editorComponent.value?.focus());
}

function focusEditButton(ulid: string): void {
    nextTick(() =>
        document
            .querySelector<HTMLElement>(`[data-testid="edit-${ulid}"]`)
            ?.focus({ preventScroll: true }),
    );
}

function startEdit(lesson: WeekLesson): void {
    if (editor.value?.ulid === lesson.ulid) {
        return;
    }

    guard.request(() => {
        delete savedNotes.value[lesson.ulid];
        editor.value = {
            ulid: lesson.ulid,
            draft: lesson.summary ?? '',
            original: lesson.summary ?? '',
            baseVersion: lesson.summary_version,
            saving: false,
            error: null,
            notice: null,
            conflict: null,
            before: { status: lesson.status, outcome: lesson.outcome },
            number: numberDraftFromLesson(lesson),
            numberOriginal: numberDraftFromLesson(lesson),
            numberErrors: NO_NUMBER_ERRORS,
            numberPreview: null,
        };
        focusEditor();
    });
}

function lisbonNow(): string {
    return lessonClock(new Date().toISOString());
}

/**
 * Grava SÓ o sumário, com a versão lida. Devolve `true` apenas quando a
 * gravação foi aceite; em qualquer outro caso o texto fica no editor.
 */
async function saveSummary(): Promise<boolean> {
    const current = editor.value;

    if (current === null || current.saving) {
        return false;
    }

    const content = current.draft.trim();

    if (content === '') {
        current.error =
            'Escreve o sumário antes de guardar. Para apagar o texto de um sumário, usa «Limpar sumário» na página da aula.';
        focusEditor();

        return false;
    }

    // Número da lição NÃO mudou: grava como sempre, sem chaves `lesson_number*`
    // (o servidor não toca na numeração).
    if (!numberIsDirty()) {
        return persistSummary(current, content, null);
    }

    // Mudou: primeiro vê-se o que a alteração faria. Valida-se aqui o que é
    // óbvio para poupar o pedido; o servidor valida de novo.
    const local = validateNumberDraft(current.number);

    if (local.number !== null || local.end !== null) {
        current.numberErrors = local;
        current.numberPreview = null;

        return false;
    }

    current.numberErrors = NO_NUMBER_ERRORS;
    current.numberPreview = null;
    current.error = null;
    // Enquanto se verifica, o editor está ocupado: um segundo Ctrl+Enter não
    // lança uma segunda verificação por cima da primeira.
    current.saving = true;

    const result = await requestNumberPreview(current.ulid, current.number);

    current.saving = false;

    if (editor.value?.ulid !== current.ulid) {
        return false;
    }

    if (result.kind === 'invalid') {
        current.numberErrors = result.errors;

        return false;
    }

    if (result.kind === 'failed') {
        current.error = result.message;

        return false;
    }

    const preview = result.preview;

    // Um conflito (outra aula já tem esse número) bloqueia: nada se grava.
    if (preview.conflicts.length > 0) {
        current.numberErrors = {
            number: preview.conflicts.join(' '),
            end: null,
        };

        return false;
    }

    // O servidor diz que não há nada a mudar na numeração (p. ex. o número
    // escrito é a própria sugestão): grava só o sumário.
    if (!preview.changed) {
        return persistSummary(current, content, null);
    }

    // Efeitos em aulas já registadas, ou avisos: o professor vê a lista e decide.
    if (previewNeedsConfirmation(preview)) {
        current.numberPreview = preview;

        return false;
    }

    return persistSummary(current, content, preview);
}

/** «Guardar» no painel de confirmação: envia a confirmação que o servidor deu. */
function confirmNumber(): Promise<boolean> {
    const current = editor.value;
    const preview = current?.numberPreview ?? null;

    if (current === null || preview === null || current.saving) {
        return Promise.resolve(false);
    }

    const content = current.draft.trim();

    if (content === '') {
        current.error =
            'Escreve o sumário antes de guardar. Para apagar o texto de um sumário, usa «Limpar sumário» na página da aula.';

        return Promise.resolve(false);
    }

    return persistSummary(current, content, preview);
}

function setNumber(value: LessonNumberDraft): void {
    if (editor.value === null) {
        return;
    }

    editor.value.number = value;
    // Um número diferente invalida o que se viu e o que se recusou.
    editor.value.numberErrors = NO_NUMBER_ERRORS;
    editor.value.numberPreview = null;
}

function cancelNumberConfirmation(): void {
    if (editor.value !== null) {
        editor.value.numberPreview = null;
    }
}

/**
 * O pedido de gravação propriamente dito. `preview` só vem quando o número
 * mudou: então as chaves da numeração (e a confirmação) seguem no mesmo PATCH,
 * que o servidor aplica numa só transação com o sumário.
 */
function persistSummary(
    current: EditorState,
    content: string,
    preview: LessonNumberPreview | null,
): Promise<boolean> {
    current.saving = true;
    current.error = null;
    current.notice = null;
    current.numberErrors = NO_NUMBER_ERRORS;

    return new Promise((resolve) => {
        let saved = false;

        router.patch(
            `/lessons/${current.ulid}/summary/content`,
            {
                content,
                summary_version: current.baseVersion,
                ...(preview === null
                    ? {}
                    : numberSavePayload(current.number, preview.confirmation)),
            },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['lessons', 'activitySessions', 'classView'],
                onSuccess: () => {
                    saved = true;

                    // As props já são as frescas (reload parcial): a versão
                    // devolvida é a que fica registada. Se a aula não veio,
                    // a gravação subiu a versão em um e o texto foi o enviado.
                    const fresh = rawLesson(current.ulid);

                    if (
                        fresh !== null &&
                        fresh.summary_version > current.baseVersion
                    ) {
                        recordConfirmedSummary(
                            current.ulid,
                            fresh.summary ?? content,
                            fresh.summary_version,
                        );
                    } else {
                        recordConfirmedSummary(
                            current.ulid,
                            content,
                            current.baseVersion + 1,
                        );
                    }
                },
                onError: (errors) => {
                    if (errors.summary_version) {
                        nextTick(() => {
                            const fresh = findLesson(current.ulid);

                            if (editor.value?.ulid === current.ulid) {
                                editor.value.conflict = {
                                    stored: fresh?.summary ?? '',
                                    version:
                                        fresh?.summary_version ??
                                        current.baseVersion,
                                };
                            }
                        });

                        return;
                    }

                    // O servidor recusou o NÚMERO (conflito, confirmação em
                    // falta ou desatualizada, aula ausente): fica junto do
                    // campo, e o «Guardar» seguinte volta a pedir a verificação.
                    const numberErrors = numberErrorsFrom(errors);

                    if (numberErrors !== null) {
                        current.numberErrors = numberErrors;
                        current.numberPreview = null;

                        return;
                    }

                    current.error =
                        errors.content ??
                        Object.values(errors)[0] ??
                        'Não foi possível guardar o sumário.';
                },
                onHttpException: () => {
                    current.error =
                        'Não foi possível guardar: o servidor não respondeu como devia. O teu texto continua aqui — tenta outra vez.';

                    return false;
                },
                onNetworkError: () => {
                    current.error =
                        'Não foi possível guardar: a ligação falhou. O teu texto continua aqui, por gravar — tenta outra vez.';

                    return false;
                },
                onFinish: () => {
                    current.saving = false;

                    if (saved && editor.value?.ulid === current.ulid) {
                        const fresh = findLesson(current.ulid);
                        let transition: string | null = null;

                        if (
                            current.before.status === 'preparation' &&
                            current.before.outcome === null &&
                            fresh?.status === 'prepared'
                        ) {
                            transition = 'passou a «Preparada»';
                        } else if (isTaughtLesson(current.before)) {
                            transition = 'registado como revisão do sumário';
                        }

                        savedNotes.value[current.ulid] = {
                            time: lisbonNow(),
                            transition,
                        };
                        editor.value = null;
                        focusEditButton(current.ulid);
                    } else if (
                        !saved &&
                        editor.value?.ulid === current.ulid &&
                        !editor.value.conflict
                    ) {
                        focusEditor();
                    }

                    resolve(saved);
                },
            },
        );
    });
}

function combineTexts(): void {
    if (editor.value?.conflict) {
        editor.value.draft = combineSummaries(
            editor.value.draft,
            editor.value.conflict.stored,
        );
        editor.value.baseVersion = editor.value.conflict.version;
        editor.value.conflict = null;
        editor.value.notice =
            'Os dois textos foram combinados no editor. Revê e guarda; a versão volta a ser verificada.';
        focusEditor();
    }
}

function keepMine(): void {
    if (editor.value?.conflict) {
        editor.value.baseVersion = editor.value.conflict.version;
        editor.value.conflict = null;
        editor.value.notice =
            'Ficas com o teu texto. Ao guardar, ele substitui o que está gravado; a versão volta a ser verificada.';
        focusEditor();
    }
}

// Confirmações (descartar o rascunho) — uma de cada vez, sempre explícitas.
const confirmation = ref<{
    title: string;
    description: string;
    confirm: string;
    onConfirm: () => void;
} | null>(null);

function useStored(): void {
    confirmation.value = {
        title: 'Descartar o teu texto?',
        description:
            'O que escreveste neste sumário perde-se e fica o texto que está gravado.',
        confirm: 'Descartar o meu texto',
        onConfirm: () => {
            const ulid = editor.value?.ulid;
            editor.value = null;

            if (ulid) {
                focusEditButton(ulid);
            }
        },
    };
}

function cancelEdit(): void {
    const current = editor.value;

    if (current === null || current.saving) {
        return;
    }

    if (!isDirty()) {
        editor.value = null;
        focusEditButton(current.ulid);

        return;
    }

    confirmation.value = {
        title: 'Descartar as alterações?',
        description:
            'O que escreveste neste sumário perde-se. O sumário volta ao que estava.',
        confirm: 'Descartar alterações',
        onConfirm: () => {
            editor.value = null;
            focusEditButton(current.ulid);
        },
    };
}

function confirmAndClose(): void {
    const action = confirmation.value?.onConfirm;
    confirmation.value = null;
    action?.();
}

function closeConfirmation(): void {
    confirmation.value = null;
    focusEditor();
}

const guard = useUnsavedChangesGuard({
    isDirty,
    isSubmitting: () => editor.value?.saving === true,
    save: saveSummary,
    discard: () => {
        editor.value = null;
    },
});

function onGuardStay(): void {
    guard.stay();
    focusEditor();
}

async function onGuardSave(): Promise<void> {
    const saved = await guard.saveAndContinue();

    if (!saved) {
        focusEditor();
    }
}

// --------------------------------------------- seleção para o lote e fecho rápido

const selectionMode = ref(false);
const selected = ref<string[]>([]);

watch(
    () => props.week.start,
    () => {
        selected.value = [];
    },
);

watch(selectionMode, (active) => {
    if (!active) {
        selected.value = [];
    }
});

function canQuickClose(lesson: WeekLesson): boolean {
    return isQuickClosable(lesson, now.value);
}

// Só aulas abertas que já começaram entram no lote rápido.
const selectableLessons = computed(() =>
    weekLessons.value.filter((lesson) => canQuickClose(lesson)),
);

watch(selectableLessons, (lessons) => {
    const eligible = new Set(lessons.map((lesson) => lesson.ulid));
    selected.value = selected.value.filter((ulid) => eligible.has(ulid));
});

function setSelected(ulid: string, value: boolean): void {
    selected.value = value
        ? [...new Set([...selected.value, ulid])]
        : selected.value.filter((item) => item !== ulid);
}

const batchDialog = ref<InstanceType<typeof BatchTaughtDialog> | null>(null);
const insertDialog = ref<InstanceType<typeof InsertLessonDialog> | null>(null);

// Um só diálogo de resultado para a página inteira, apontado à aula escolhida.
const outcomeDialogOpen = ref(false);
const outcomeLesson = ref<WeekLesson | null>(null);
const outcomeKind = ref<SpecialLessonOutcome>('teacher_absent');

/** O texto do editor desta aula tem alterações por guardar (0.171.0). */
function editorHasUnsavedChanges(ulid: string | null | undefined): boolean {
    return ulid != null && editor.value?.ulid === ulid && isDirty();
}

/**
 * Depois de um resultado registado, o texto que o editor mostra já saiu desta
 * aula (o servidor deslocou o sumário GRAVADO). O editor fecha-se — sem
 * alterações por guardar, porque o diálogo as pede primeiro — para não deixar
 * à vista nem gravar de novo um plano que já seguiu (0.171.0).
 */
function closeEditorOnLesson(ulid: string | null | undefined): void {
    if (ulid != null && editor.value?.ulid === ulid && !isDirty()) {
        editor.value = null;
    }
}

// «Registar e adiar a preparação» (0.171.0): um só diálogo para a página.
const postponeOpen = ref(false);
const postponeLesson = ref<WeekLesson | null>(null);
const postponeEvent = ref<WeekLesson['day_events'][number] | null>(null);

function openPostpone(lesson: WeekLesson, event: WeekLesson['day_events'][number]): void {
    postponeLesson.value = lesson;
    postponeEvent.value = event;
    postponeOpen.value = true;
}

function openOutcome(lesson: WeekLesson, outcome: SpecialLessonOutcome): void {
    outcomeLesson.value = lesson;
    outcomeKind.value = outcome;
    outcomeDialogOpen.value = true;
}

// ------------------------------------------------------------ atualizar a semana

const materializeForm = useForm({ from: props.week.start, to: props.week.end });
const materializeError = computed(
    () => Object.values(materializeForm.errors)[0],
);

function materialize(): void {
    materializeForm.from = props.week.start;
    materializeForm.to = props.week.end;
    materializeForm.post('/lessons/materialize-week', { preserveScroll: true });
}

// --------------------------------------------- «Aulas de hoje» (`foco=hoje`)
//
// O atalho do Dashboard abre `/lessons?view=semana&foco=hoje`. Depois de a
// página estar renderizada, a Semana posiciona-se no primeiro dia VISÍVEL
// (já filtrado) que seja hoje ou posterior. Se a semana aberta já não o tem,
// pergunta ao servidor (só leitura) qual é o próximo dia com aula e salta para
// essa semana, mantendo os filtros, repetindo o passo quando as props chegam.
// Nada aqui usa temporizadores: só `nextTick` e `requestAnimationFrame`.

type TodayNotice = { kind: 'none' | 'error'; filtered: boolean };

const todayNotice = ref<TodayNotice | null>(null);
/** As semanas por onde o atalho já passou — cada salto tem de avançar. */
const todayVisited = new Set<string>();

function wantsTodayFocus(): boolean {
    const query = page.url.includes('?')
        ? page.url.slice(page.url.indexOf('?') + 1).split('#')[0]
        : '';

    return new URLSearchParams(query).get('foco') === 'hoje';
}

/** Retira `foco` do URL, para que recarregar não volte a saltar. */
function clearTodayFocus(): void {
    const url = new URL(page.url, 'http://lapis.local');
    url.searchParams.delete('foco');

    router.replace({
        url: `${url.pathname}${url.search}${url.hash}`,
        preserveState: true,
        preserveScroll: true,
    });
}

function weekStartOf(date: string): string {
    const weekday = new Date(`${date}T12:00:00Z`).getUTCDay();

    return addDays(date, weekday === 0 ? -6 : 1 - weekday);
}

function nextFrame(): Promise<void> {
    return new Promise((resolve) =>
        window.requestAnimationFrame(() => resolve()),
    );
}

async function positionToday(): Promise<void> {
    if (!wantsTodayFocus()) {
        return;
    }

    // Durante uma pesquisa não há dias à vista para onde ir — e o atalho
    // nunca tira ninguém dos resultados.
    if (view.value !== 'semana' || !props.academicYear || searching.value) {
        clearTodayFocus();

        return;
    }

    await nextTick();
    await nextFrame();

    if (!wantsTodayFocus()) {
        return;
    }

    const target = days.value.find((day) => day.date >= props.today);

    if (target) {
        const heading = document.getElementById(`dia-${target.date}`);
        heading?.scrollIntoView({ block: 'start' });
        heading?.focus({ preventScroll: true });
        todayNotice.value = null;
        clearTodayFocus();

        return;
    }

    await seekNextLessonDay();
}

async function seekNextLessonDay(): Promise<void> {
    const filtered = activeFilterCount(state.value) > 0;

    // Guarda de ciclo: uma semana por onde já se passou não pode voltar a pedir salto.
    if (todayVisited.has(props.week.start)) {
        todayNotice.value = { kind: 'error', filtered };
        clearTodayFocus();

        return;
    }

    todayVisited.add(props.week.start);

    try {
        const query = new URLSearchParams({ after: props.week.end });

        if (state.value.classes.length > 0) {
            query.set('classes', state.value.classes.join(','));
        }

        const response = await fetch(`/lessons/next-day?${query.toString()}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            throw new Error(`next-day ${response.status}`);
        }

        const { date } = (await response.json()) as { date: string | null };

        if (date === null) {
            todayNotice.value = { kind: 'none', filtered };
            clearTodayFocus();

            return;
        }

        const week = weekStartOf(date);

        if (week <= props.week.start || todayVisited.has(week)) {
            todayNotice.value = { kind: 'error', filtered };
            clearTodayFocus();

            return;
        }

        router.get(
            `${weekViewUrl(state.value, week)}&foco=hoje`,
            {},
            {
                preserveState: true,
                replace: true,
                onSuccess: () => {
                    void positionToday();
                },
                onError: () => {
                    todayNotice.value = { kind: 'error', filtered };
                },
            },
        );
    } catch {
        todayNotice.value = { kind: 'error', filtered };
        clearTodayFocus();
    }
}

function clearAllFilters(): void {
    todayNotice.value = null;
    updateFilters({
        classes: [],
        states: [],
        attention: false,
        summary: 'todos',
    });
}

// ------------------------------------------------------- pesquisa nos sumários
//
// O campo escreve em `searchInput`; o URL só muda depois de uma pausa na
// escrita (ou logo, com Enter), e é a mudança do URL que faz o pedido
// (`useSummarySearch`, que só aceita a resposta do último). Entrar na pesquisa
// passa pela guarda, como mudar de vista: o calendário — e o editor que lá
// estiver aberto — sai do ecrã. Sair dela é retirar os `q*` do URL; a semana,
// a vista e os filtros do calendário nunca foram tocados.

const SEARCH_DELAY = 350;
const SHORT_TERM_HINT = 'Escreve pelo menos dois caracteres para pesquisar.';

const summarySearch = useSummarySearch(
    () => searchState.value,
    // QUEM e ONDE: a resposta guardada na sessão nunca serve a outra pessoa,
    // outra organização ou outro ano letivo.
    () => {
        const auth = page.props?.auth as
            | {
                  user?: { id?: number } | null;
                  organization?: { ulid?: string } | null;
              }
            | undefined;

        return `${auth?.user?.id ?? '-'}|${auth?.organization?.ulid ?? '-'}|${props.academicYear ?? '-'}`;
    },
);
const searchInput = ref(searchState.value?.term ?? '');
/** A última expressão que ESTA página pôs no URL — para distinguir uma mudança vinda de fora. */
let committedTerm = searchState.value?.term ?? '';
const searchHint = ref<string | null>(null);
const searchField = ref<InstanceType<typeof LessonSearchField> | null>(null);
let searchTimer: ReturnType<typeof setTimeout> | null = null;
/** Onde estava o calendário quando a pesquisa começou, para lá voltar. */
let calendarScroll: number | null = null;
let leavingSearch = false;

/** As turmas que a pesquisa oferece: todas as do professor no ano letivo, também as arquivadas. */
const searchClasses = computed(() =>
    [...props.classes]
        .sort(
            (a, b) =>
                a.label.localeCompare(b.label, 'pt-PT', { numeric: true }) ||
                a.subject.localeCompare(b.subject, 'pt-PT'),
        )
        .map((schoolClass) => ({
            ulid: schoolClass.ulid,
            label: schoolClass.label,
            subject: schoolClass.subject,
            archived: schoolClass.archived,
            tone: tones.value.get(schoolClass.ulid) ?? ('blue' as TurmaTone),
        })),
);
const showSubject = computed(() => needsSubject(props.classes));
const searchScope = computed(() =>
    searchState.value !== null && props.academicYear
        ? summarySearchScope(
              props.academicYear,
              props.classes,
              searchState.value,
          )
        : [],
);

function cancelSearchTimer(): void {
    if (searchTimer !== null) {
        clearTimeout(searchTimer);
        searchTimer = null;
    }
}

// O URL mudou por fora (uma resposta atrasada, o histórico): o campo
// acompanha-o, em vez de mostrar uma expressão e os resultados de outra.
watch(
    () => searchState.value?.term ?? '',
    (term) => {
        if (term === committedTerm) {
            return;
        }

        committedTerm = term;
        cancelSearchTimer();
        searchInput.value = term;
    },
);

// Uma escrita feita nesta página durante a pesquisa (inserir uma aula, marcar
// como lecionadas) recarrega as aulas — e os resultados são revalidados com
// elas, para nenhum cartão continuar a mostrar um sumário que já mudou.
watch(
    () => props.lessons,
    () => {
        if (searchState.value !== null) {
            summarySearch.retry();
        }
    },
);

watch(searchInput, (value) => {
    cancelSearchTimer();
    searchHint.value = null;

    if (value.trim() === '') {
        if (searchState.value !== null && !leavingSearch) {
            leaveSearch('field');
        }

        return;
    }

    if (!isSearchableTerm(value)) {
        searchHint.value = SHORT_TERM_HINT;

        return;
    }

    if (value !== searchState.value?.term) {
        searchTimer = setTimeout(submitSearch, SEARCH_DELAY);
    }
});

function submitSearch(): void {
    cancelSearchTimer();

    if (!isSearchableTerm(searchInput.value)) {
        searchHint.value =
            searchInput.value.trim() === '' ? null : SHORT_TERM_HINT;

        return;
    }

    const current = searchState.value;

    if (current !== null) {
        if (searchInput.value !== current.term) {
            committedTerm = searchInput.value;
            updateSearch({ term: searchInput.value });
        } else if (summarySearch.status.value === 'error') {
            summarySearch.retry();
        }

        return;
    }

    guard.request(() => {
        calendarScroll = window.scrollY;
        editor.value = null;
        selectionMode.value = false;
        pinnedLesson.value = null;
        cameFromTimetable.value = false;
        todayNotice.value = null;

        // Começa nas turmas que o calendário estava a mostrar: as filtradas,
        // ou a turma aberta na vista Por turma. Nenhuma = todas.
        const known = new Set(
            props.classes.map((schoolClass) => schoolClass.ulid),
        );
        const classes =
            view.value === 'turma'
                ? classViewData.value
                    ? [classViewData.value.class.ulid]
                    : []
                : state.value.classes.filter((ulid) => known.has(ulid));

        committedTerm = searchInput.value;
        router.replace({
            url: summarySearchUrl(page.url, {
                term: searchInput.value,
                classes,
                states: storedSummarySearchStates(),
                other: false,
                from: null,
                to: null,
                page: 1,
            }),
            preserveState: true,
            preserveScroll: true,
        });
    });
}

/** Muda a pesquisa em curso; qualquer mudança que não seja de página volta à primeira. */
function updateSearch(patch: Partial<SummarySearchState>): void {
    const current = searchState.value;

    if (current === null) {
        return;
    }

    if (patch.states) {
        rememberSummarySearchStates(patch.states);
    }

    router.replace({
        url: summarySearchUrl(page.url, {
            ...current,
            ...patch,
            page: patch.page ?? 1,
        }),
        preserveState: true,
        preserveScroll: true,
    });
}

/**
 * «Voltar ao calendário», e limpar o campo: retira a pesquisa do URL e repõe a
 * posição em que o calendário estava. A semana nunca muda por causa de um
 * resultado.
 */
function leaveSearch(focus: 'field' | 'view'): void {
    cancelSearchTimer();
    searchHint.value = null;
    leavingSearch = true;
    committedTerm = '';
    searchInput.value = '';
    const scroll = calendarScroll;
    calendarScroll = null;

    router.replace({
        url: summarySearchUrl(page.url, null),
        preserveState: true,
        preserveScroll: true,
    });

    nextTick(() => {
        leavingSearch = false;
        window.requestAnimationFrame(() => {
            if (scroll !== null) {
                window.scrollTo({ top: scroll });
            }

            if (focus === 'field') {
                searchField.value?.focus();
            } else {
                document
                    .getElementById(`lessons-view-${view.value}`)
                    ?.focus({ preventScroll: true });
            }
        });
    });
}

function reloadPage(): void {
    window.location.reload();
}

function clearSearch(): void {
    if (searchState.value !== null) {
        leaveSearch('field');

        return;
    }

    cancelSearchTimer();
    searchInput.value = '';
    searchHint.value = null;
}

// ---------------------------------------------------- modo de seleção (Escape)

/** Escape sai da seleção — fora de campos de texto, menus e diálogos, que usam a tecla. */
function onWindowKeydown(event: KeyboardEvent): void {
    if (
        event.key !== 'Escape' ||
        !selectionMode.value ||
        event.defaultPrevented
    ) {
        return;
    }

    const target = event.target instanceof HTMLElement ? event.target : null;

    if (
        target?.closest(
            'input, textarea, select, [contenteditable="true"], [role="dialog"], [role="menu"]',
        ) ||
        // Só um menu ou diálogo ABERTO: um que se está a fechar (animação de
        // saída) continua no DOM com `data-state="closed"` e não usa a tecla.
        document.querySelector(
            '[role="dialog"][data-state="open"], [role="menu"][data-state="open"]',
        )
    ) {
        return;
    }

    selectionMode.value = false;
}

// ------------------------------------------------------------------------ ciclo

/** O resto do arranque: a vista guardada, o regresso ao cartão e «Aulas de hoje». */
function continueMount(): void {
    // Sem vista no URL, usa a última escolhida neste browser — nunca a meio de
    // uma pesquisa, que `go()` deixaria cair.
    if (!searching.value && !page.url.includes('view=')) {
        const stored = storedView();

        if (stored === 'horario') {
            go({ view: 'horario' });
        } else if (stored === 'turma' && props.classes.length > 0) {
            go({ view: 'turma' }, { server: true });
        }
    }

    restoreFromLesson();
    void positionToday();
}

onMounted(() => {
    clock = setInterval(() => {
        now.value = new Date();
    }, 60_000);
    window.addEventListener('keydown', onWindowKeydown);

    // Regresso à semana (histórico do Inertia com props antigas, ou alguma
    // alteração feita na página da aula): revalida no servidor, mantendo o
    // scroll e o estado. Enquanto a resposta não chega, a sobreposição já
    // mostra o texto confirmado.
    const stale = consumeLessonsStale();

    if (
        !stale &&
        !hasPendingConfirmations([
            ...props.lessons,
            ...(props.classView?.lessons ?? []),
        ])
    ) {
        continueMount();

        return;
    }

    // UMA VISITA SÍNCRONA, E NÃO `router.reload()`. O reload do Inertia é
    // assíncrono, e uma resposta assíncrona só é descartada se mudar o
    // PATHNAME (Inertia 3.6, `shouldSetPage`): chegando depois de uma visita a
    // outra semana ou à vista Por turma — o mesmo `/lessons`, outra query —
    // repunha as props E o URL antigos. Síncrona, é a visita seguinte que a
    // cancela, nunca o contrário. E o resto do arranque, que pode navegar
    // (vista guardada, «Aulas de hoje»), espera por ela.
    let cancelled = false;

    //
    // E `preserveUrl`: é uma recarga do MESMO URL, e o que o professor fizer
    // entretanto do lado do cliente (entrar na pesquisa, mudar-lhe a
    // expressão, voltar ao calendário) não pode ser desfeito pela resposta.
    router.get(
        page.url,
        {},
        {
            only: ['lessons', 'activitySessions', 'classView'],
            preserveState: true,
            preserveScroll: true,
            preserveUrl: true,
            replace: true,
            onCancel: () => {
                cancelled = true;
            },
            onFinish: () => {
                if (!cancelled) {
                    continueMount();
                }
            },
        },
    );
});

onBeforeUnmount(() => {
    if (clock !== null) {
        clearInterval(clock);
    }

    cancelSearchTimer();
    window.removeEventListener('keydown', onWindowKeydown);
});

const weekLabel = computed(() =>
    weekRangeLabel(props.week.start, props.week.end),
);
const isCurrentWeek = computed(
    () => props.today >= props.week.start && props.today <= props.week.end,
);

const VIEWS: { value: WeekView; label: string; icon: typeof Rows3 }[] = [
    { value: 'semana', label: 'Semana', icon: Rows3 },
    { value: 'turma', label: 'Por turma', icon: GitCommitVertical },
    { value: 'horario', label: 'Horário', icon: LayoutGrid },
];

function onViewKeydown(event: KeyboardEvent, index: number): void {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
    }

    event.preventDefault();
    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? VIEWS.length - 1
              : (index + (event.key === 'ArrowRight' ? 1 : -1) + VIEWS.length) %
                VIEWS.length;
    const target = VIEWS[next];

    if (target) {
        setView(target.value);
        nextTick(() =>
            document.getElementById(`lessons-view-${target.value}`)?.focus(),
        );
    }
}
</script>

<template>
    <Head title="Aulas e Sumários" />
    <main class="mx-auto w-full max-w-7xl p-4 pb-24 sm:p-6">
        <!-- 1. CABEÇALHO — o título, a ação principal e «Mais ações». -->
        <header
            class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3"
        >
            <div class="min-w-0">
                <h1
                    class="text-xl leading-7 font-semibold tracking-tight sm:text-[22px]"
                >
                    Aulas e Sumários
                </h1>
                <p
                    v-if="academicYear"
                    class="text-sm text-muted-foreground"
                    data-testid="lessons-academic-year"
                >
                    Ano letivo {{ academicYear }}
                </p>
            </div>
            <div
                v-if="academicYear"
                class="flex w-full flex-wrap items-center gap-2 sm:w-auto"
            >
                <InsertLessonDialog
                    v-if="insertableClasses.length > 0"
                    ref="insertDialog"
                    :classes="insertableClasses"
                    :default-date="week.start"
                    trigger-variant="default"
                    trigger-class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                />
                <!-- Aberto a partir de «Mais ações» e da barra da seleção; sem botão próprio. -->
                <BatchTaughtDialog
                    ref="batchDialog"
                    :week-start="week.start"
                    :today="today"
                    :selected="selected"
                    trigger-class="hidden"
                    return-focus-to="[data-testid='more-actions']"
                />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                            data-testid="more-actions"
                        >
                            <Ellipsis class="size-4" aria-hidden="true" />
                            Mais ações
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-[17rem]">
                        <DropdownMenuItem
                            v-if="!searching"
                            class="min-h-11 sm:min-h-9"
                            :disabled="
                                !selectionMode && selectableLessons.length === 0
                            "
                            data-testid="selection-toggle"
                            @select="selectionMode = !selectionMode"
                        >
                            <CheckSquare class="size-4" aria-hidden="true" />
                            {{
                                selectionMode
                                    ? 'Sair da seleção'
                                    : 'Selecionar aulas'
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="min-h-11 sm:min-h-9"
                            data-testid="batch-taught-open"
                            @select="batchDialog?.show()"
                        >
                            <CheckCheck class="size-4" aria-hidden="true" />
                            Marcar aulas como lecionadas…
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="!searching"
                            class="min-h-11 sm:min-h-9"
                            :disabled="materializeForm.processing"
                            @select="materialize"
                        >
                            <RefreshCw class="size-4" aria-hidden="true" />
                            {{
                                materializeForm.processing
                                    ? 'A atualizar…'
                                    : 'Atualizar aulas desta semana'
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child class="min-h-11 sm:min-h-9">
                            <Link href="/lessons/sequences"
                                ><ListOrdered
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Sequências de aulas</Link
                            >
                        </DropdownMenuItem>
                        <template v-if="!searching && view !== 'horario'">
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel
                                class="text-xs font-semibold text-muted-foreground"
                                >Mostrar os sumários</DropdownMenuLabel
                            >
                            <DropdownMenuRadioGroup
                                :model-value="state.density"
                            >
                                <DropdownMenuRadioItem
                                    value="completo"
                                    class="min-h-11 sm:min-h-9"
                                    data-testid="density-full"
                                    @select="setDensity('completo')"
                                    >Texto completo</DropdownMenuRadioItem
                                >
                                <DropdownMenuRadioItem
                                    value="compacto"
                                    class="min-h-11 sm:min-h-9"
                                    data-testid="density-compact"
                                    @select="setDensity('compacto')"
                                    >Compacto (duas
                                    linhas)</DropdownMenuRadioItem
                                >
                            </DropdownMenuRadioGroup>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
        <InputError :message="materializeError" />

        <!-- 2. CONSULTA — a vista do calendário e a pesquisa nos sumários. -->
        <div
            v-if="academicYear"
            class="mt-4 flex flex-wrap items-start gap-2 sm:gap-3"
            data-testid="lessons-query"
        >
            <div
                v-if="!searching"
                class="grid w-full grid-cols-3 gap-0.5 rounded-lg border bg-card p-0.5 sm:inline-grid sm:w-auto"
                role="tablist"
                aria-label="Forma de ver as aulas"
            >
                <button
                    v-for="(option, index) in VIEWS"
                    :id="`lessons-view-${option.value}`"
                    :key="option.value"
                    type="button"
                    role="tab"
                    :aria-selected="view === option.value"
                    :tabindex="view === option.value ? 0 : -1"
                    :class="[
                        'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8',
                        view === option.value
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    ]"
                    @click="setView(option.value)"
                    @keydown="onViewKeydown($event, index)"
                >
                    <component
                        :is="option.icon"
                        class="size-4"
                        aria-hidden="true"
                    />{{ option.label }}
                </button>
            </div>
            <Button
                v-else
                type="button"
                variant="outline"
                class="min-h-11 w-full sm:min-h-9 sm:w-auto"
                data-testid="search-back"
                @click="leaveSearch('view')"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                Voltar ao calendário
            </Button>
            <LessonSearchField
                ref="searchField"
                v-model="searchInput"
                :hint="searchHint"
                :class="[
                    'w-full sm:ml-auto',
                    searching ? 'sm:max-w-2xl sm:flex-1' : 'sm:w-80 lg:w-96',
                ]"
                @submit="submitSearch"
                @clear="clearSearch"
            />
        </div>

        <!-- 3. FILTROS -->
        <LessonSearchFilters
            v-if="searchState"
            class="mt-3"
            :state="searchState"
            :classes="searchClasses"
            :show-subject="showSubject"
            :bounds="academicYearBounds"
            @update="updateSearch"
        />
        <LessonWeekFilters
            v-else-if="
                (academicYear &&
                    weekLessons.length + (classViewData?.lessons.length ?? 0) >
                        0) ||
                (academicYear && view === 'turma')
            "
            class="mt-3"
            :state="state"
            :classes="filterClasses"
            :show-classes="view !== 'turma'"
            :visible-count="
                view === 'turma'
                    ? classViewLessons.length
                    : view === 'horario'
                      ? weekLessons.filter((lesson) =>
                            matchesWeekFilters(lesson, state, now),
                        ).length
                      : visibleWeekLessons.length
            "
            :total-count="
                view === 'turma'
                    ? (classViewData?.lessons.length ?? 0)
                    : weekLessons.length
            "
            :unit-label="
                view === 'turma' ? 'aulas no intervalo' : 'aulas desta semana'
            "
            @update="updateFilters"
        />

        <!-- 5. CONTEÚDO DA PESQUISA — no lugar do calendário. -->
        <LessonSearchResults
            v-if="searchState"
            :state="searchState"
            :scope="searchScope"
            :response="summarySearch.response.value"
            :status="summarySearch.status.value"
            :current="summarySearch.current.value"
            :error="summarySearch.error.value"
            :error-kind="summarySearch.errorKind.value"
            :academic-year="academicYear ?? ''"
            :tones="tones"
            :show-subject="showSubject"
            :highlighted="highlighted"
            @page="(target) => updateSearch({ page: target })"
            @retry="summarySearch.retry()"
            @open="rememberReturn"
            @include-prepared="updateSearch({ states: 'both' })"
            @reload-page="reloadPage"
        />

        <template v-else>
            <!-- 4. NAVEGAÇÃO TEMPORAL — junto do calendário. -->
            <div
                class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2"
                data-testid="week-navigation"
            >
                <nav
                    class="flex w-full items-center gap-1.5 sm:w-auto"
                    aria-label="Navegação entre semanas"
                >
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="min-h-11 min-w-11 sm:min-h-9"
                        aria-label="Semana anterior"
                        @click="shiftWeek(-7)"
                    >
                        <ChevronLeft class="size-4" aria-hidden="true" /><span
                            class="hidden lg:inline"
                            >Anterior</span
                        >
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                        :aria-current="isCurrentWeek ? 'date' : undefined"
                        @click="goToWeek(today)"
                    >
                        <CalendarDays class="size-4" aria-hidden="true" />
                        Semana atual
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="min-h-11 min-w-11 sm:min-h-9"
                        aria-label="Semana seguinte"
                        @click="shiftWeek(7)"
                    >
                        <span class="hidden lg:inline">Seguinte</span
                        ><ChevronRight class="size-4" aria-hidden="true" />
                    </Button>
                </nav>
                <p
                    class="text-[15px] font-semibold"
                    aria-live="polite"
                    data-testid="week-label"
                >
                    Semana de {{ weekLabel
                    }}<span
                        v-if="isCurrentWeek"
                        class="font-normal text-muted-foreground"
                    >
                        (semana atual)</span
                    >
                </p>
            </div>
            <p
                v-if="
                    academicYear && view !== 'turma' && weekLessons.length > 0
                "
                class="mt-2 text-sm text-muted-foreground"
                data-testid="week-facts"
            >
                Esta semana:
                <strong class="font-semibold text-foreground">{{
                    facts.total
                }}</strong>
                aula{{ facts.total === 1 ? '' : 's' }} ·
                <strong class="font-semibold text-foreground">{{
                    facts.withSummary
                }}</strong>
                com sumário<template v-if="facts.taughtWithoutSummary">
                    ·
                    <strong class="font-semibold text-foreground">{{
                        facts.taughtWithoutSummary
                    }}</strong>
                    lecionada{{
                        facts.taughtWithoutSummary === 1 ? '' : 's'
                    }}
                    sem sumário</template
                ><template v-if="facts.toConfirm">
                    ·
                    <strong class="font-semibold text-foreground">{{
                        facts.toConfirm
                    }}</strong>
                    terminada{{ facts.toConfirm === 1 ? '' : 's' }} por
                    confirmar</template
                >.
            </p>

            <LessonDayNav
                v-if="academicYear && view !== 'horario'"
                class="mt-3"
                :items="dayNavItems"
                :back-label="cameFromTimetable ? 'Voltar ao horário' : null"
                :aria-label="
                    view === 'turma' &&
                    classViewData &&
                    classViewData.range.key !== '1'
                        ? 'Atalhos para as semanas'
                        : 'Atalhos para os dias'
                "
                @back="backToTimetable"
            />

            <div
                v-if="todayNotice"
                class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border bg-card p-3 text-sm"
                role="status"
                data-testid="today-notice"
            >
                <span v-if="todayNotice.kind === 'error'"
                    >Não foi possível encontrar a próxima aula.</span
                >
                <span v-else-if="todayNotice.filtered"
                    >Não há aulas hoje nem nas próximas semanas deste ano letivo
                    com os filtros ativos.</span
                >
                <span v-else
                    >Não há aulas hoje nem nas próximas semanas deste ano
                    letivo.</span
                >
                <Button
                    v-if="todayNotice.kind === 'none' && todayNotice.filtered"
                    type="button"
                    variant="link"
                    class="h-auto min-h-11 px-1 sm:min-h-0"
                    data-testid="today-clear-filters"
                    @click="clearAllFilters"
                    >Limpar filtros</Button
                >
            </div>
        </template>

        <div v-if="!searchState" class="mt-2">
            <EmptyState
                v-if="!academicYear"
                title="Seleciona um ano letivo"
                description="A semana usa o ano letivo selecionado no topo da aplicação."
                :icon="CalendarDays"
            />

            <!-- POR TURMA -->
            <template v-else-if="view === 'turma'">
                <EmptyState
                    v-if="classes.length === 0 && !classViewData"
                    title="Ainda sem turmas neste ano letivo"
                    description="A vista por turma mostra a sequência das aulas de cada uma das tuas turmas."
                    :icon="BookOpen"
                />
                <p
                    v-else-if="!classViewData"
                    class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
                >
                    A carregar a turma…
                </p>
                <LessonClassView
                    v-else
                    class="mt-2"
                    :class-view="classViewData"
                    :classes="pickableClasses"
                    :tones="tones"
                    :lessons="classViewLessons"
                    :density="state.density"
                    @update="updateClassView"
                    @open="rememberReturn"
                    @project="openProjection"
                >
                    <template #card="{ lesson, dayLessons }">
                        <LessonWeekCard
                            :lesson="lesson"
                            :tone="toneOf(lesson)"
                            mode="turma"
                            :day-lessons="dayLessons"
                            :density="state.density"
                            :expanded="expanded.includes(lesson.ulid)"
                            :now="now"
                            :selection-mode="selectionMode"
                            :selected="selected.includes(lesson.ulid)"
                            :editing="editor?.ulid === lesson.ulid"
                            :saved-note="savedNotes[lesson.ulid] ?? null"
                            :highlighted="highlighted === lesson.ulid"
                            @edit="startEdit(lesson)"
                            @open="rememberReturn(lesson)"
                            @project="openProjection(lesson)"
                            @toggle-expanded="toggleExpanded(lesson.ulid)"
                            @update:selected="
                                (value) => setSelected(lesson.ulid, value)
                            "
                            @outcome="(outcome) => openOutcome(lesson, outcome)"
                        >
                            <template #editor>
                                <LessonSummaryEditor
                                    v-if="editor && editor.ulid === lesson.ulid"
                                    :ref="setEditorComponent"
                                    v-model="editor.draft"
                                    :lesson="lesson"
                                    :original="editor.original"
                                    :saving="editor.saving"
                                    :error="editor.error"
                                    :notice="editor.notice"
                                    :conflict="editor.conflict"
                                    :number="editor.number"
                                    :number-original="editor.numberOriginal"
                                    :number-errors="editor.numberErrors"
                                    :number-preview="editor.numberPreview"
                                    @update:number="setNumber"
                                    @confirm-number="confirmNumber"
                                    @cancel-number="cancelNumberConfirmation"
                                    @save="saveSummary"
                                    @cancel="cancelEdit"
                                    @project="openProjection(lesson)"
                                    @postpone="(event) => openPostpone(lesson, event)"
                                    @combine="combineTexts"
                                    @keep-mine="keepMine"
                                    @use-stored="useStored"
                                />
                            </template>
                        </LessonWeekCard>
                    </template>
                </LessonClassView>
            </template>

            <EmptyState
                v-else-if="
                    weekLessons.length === 0 &&
                    (view === 'horario' || weekSessions.length === 0)
                "
                title="Sem aulas nesta semana"
                :description="
                    configuredClassesCount > 0
                        ? 'O horário recorrente das tuas turmas não tem aulas nesta semana. Abrir outra semana mostra as aulas dessa semana automaticamente.'
                        : 'Configura primeiro o horário na página de cada turma.'
                "
                :icon="BookOpen"
            />

            <!-- HORÁRIO — as mesmas `lessons`, sem segunda consulta. -->
            <LessonTimetable
                v-else-if="view === 'horario'"
                class="mt-2"
                :lessons="weekLessons"
                :week-start="week.start"
                :today="today"
                :now="now"
                :tones="tones"
                :is-dimmed="
                    (lesson: WeekLesson) =>
                        !matchesWeekFilters(lesson, state, now)
                "
                :selectable="selectionMode"
                :is-selectable="canQuickClose"
                :selected="selected"
                @update:selected="selected = $event"
                @read="readInWeek"
                @open="rememberReturn"
                @project="openProjection"
            >
                <template #actions="{ lesson, time, variant }">
                    <LessonQuickActions
                        v-if="canQuickClose(lesson)"
                        :lesson-ulid="lesson.ulid"
                        :context-label="lesson.context_label"
                        :time="time"
                        :variant="variant"
                        @outcome="(outcome) => openOutcome(lesson, outcome)"
                    />
                </template>
            </LessonTimetable>

            <!-- SEMANA -->
            <template v-else>
                <p
                    v-if="
                        weekLessons.length > 0 &&
                        visibleWeekLessons.length === 0
                    "
                    class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
                >
                    Os filtros escondem todas as aulas desta semana.
                </p>
                <div class="@container">
                    <section
                        v-for="day in days"
                        :key="day.date"
                        class="mt-5 grid gap-3"
                        :aria-labelledby="`dia-${day.date}`"
                    >
                        <h2
                            :id="`dia-${day.date}`"
                            tabindex="-1"
                            class="flex scroll-mt-24 flex-wrap items-baseline gap-x-3 gap-y-1 border-b pb-2 text-[17px] leading-6 font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        >
                            {{ dayHeading(day.date) }}
                            <span
                                v-if="day.date === today"
                                class="rounded-full bg-amber-100 px-2 text-xs leading-5 font-semibold text-amber-900 dark:bg-amber-950 dark:text-amber-200"
                                >Hoje</span
                            >
                            <span
                                v-if="day.all.length > 0"
                                class="text-[13px] font-normal text-muted-foreground"
                                >{{ day.all.length }} aula{{
                                    day.all.length === 1 ? '' : 's'
                                }}
                                ·
                                {{
                                    day.all.filter(
                                        (lesson) => lesson.has_summary,
                                    ).length
                                }}
                                com sumário</span
                            >
                            <!-- As sessões não são aulas: contam-se à parte. -->
                            <span
                                v-if="day.sessions.length > 0"
                                class="text-[13px] font-normal text-muted-foreground"
                                data-testid="day-activity-sessions-count"
                                >{{ day.sessions.length }} outra{{
                                    day.sessions.length === 1 ? '' : 's'
                                }}
                                atividade{{
                                    day.sessions.length === 1 ? '' : 's'
                                }}</span
                            >
                        </h2>
                        <ol class="grid gap-3">
                            <li v-for="entry in day.entries" :key="entry.key">
                                <ActivitySessionCard
                                    v-if="entry.kind === 'session'"
                                    :session="entry.session"
                                    @open="openSession(entry.session)"
                                />
                                <template v-else>
                                    <LessonWeekCard
                                        :lesson="entry.lesson"
                                        :tone="toneOf(entry.lesson)"
                                        mode="semana"
                                        :day-lessons="day.all"
                                        :density="state.density"
                                        :expanded="
                                            expanded.includes(entry.lesson.ulid)
                                        "
                                        :now="now"
                                        :selection-mode="selectionMode"
                                        :selected="
                                            selected.includes(entry.lesson.ulid)
                                        "
                                        :editing="
                                            editor?.ulid === entry.lesson.ulid
                                        "
                                        :saved-note="
                                            savedNotes[entry.lesson.ulid] ??
                                            null
                                        "
                                        :highlighted="
                                            highlighted === entry.lesson.ulid
                                        "
                                        @edit="startEdit(entry.lesson)"
                                        @open="rememberReturn(entry.lesson)"
                                        @project="openProjection(entry.lesson)"
                                        @toggle-expanded="
                                            toggleExpanded(entry.lesson.ulid)
                                        "
                                        @update:selected="
                                            (value) =>
                                                setSelected(
                                                    entry.lesson.ulid,
                                                    value,
                                                )
                                        "
                                        @outcome="
                                            (outcome) =>
                                                openOutcome(
                                                    entry.lesson,
                                                    outcome,
                                                )
                                        "
                                    >
                                        <template #editor>
                                            <LessonSummaryEditor
                                                v-if="
                                                    editor &&
                                                    editor.ulid ===
                                                        entry.lesson.ulid
                                                "
                                                :ref="setEditorComponent"
                                                v-model="editor.draft"
                                                :lesson="entry.lesson"
                                                :original="editor.original"
                                                :saving="editor.saving"
                                                :error="editor.error"
                                                :notice="editor.notice"
                                                :conflict="editor.conflict"
                                                :number="editor.number"
                                                :number-original="
                                                    editor.numberOriginal
                                                "
                                                :number-errors="
                                                    editor.numberErrors
                                                "
                                                :number-preview="
                                                    editor.numberPreview
                                                "
                                                @update:number="setNumber"
                                                @confirm-number="confirmNumber"
                                                @cancel-number="
                                                    cancelNumberConfirmation
                                                "
                                                @save="saveSummary"
                                                @cancel="cancelEdit"
                                                @project="
                                                    openProjection(entry.lesson)
                                                "
                                                @postpone="
                                                    (event) =>
                                                        openPostpone(
                                                            entry.lesson,
                                                            event,
                                                        )
                                                "
                                                @combine="combineTexts"
                                                @keep-mine="keepMine"
                                                @use-stored="useStored"
                                            />
                                        </template>
                                    </LessonWeekCard>
                                </template>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </div>

        <!-- A barra contextual da seleção: fixa no fundo enquanto se seleciona,
             com as operações e a saída (também Escape). -->
        <div
            v-if="selectionMode && academicYear && !searching"
            class="sticky bottom-4 z-10 mt-4 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border bg-card p-3 text-sm shadow-lg"
            role="region"
            aria-label="Seleção de aulas"
            data-testid="quick-batch-bar"
        >
            <div class="grid min-w-0 gap-0.5">
                <span role="status" aria-live="polite" class="font-medium">
                    {{ selected.length }} aula{{
                        selected.length === 1 ? '' : 's'
                    }}
                    selecionada{{ selected.length === 1 ? '' : 's' }}
                </span>
                <span class="text-[13px] text-muted-foreground"
                    >Só se selecionam aulas por fechar que já começaram.</span
                >
            </div>
            <div
                class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto"
            >
                <Button
                    v-if="selected.length > 0"
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="min-h-11 sm:min-h-9"
                    @click="selected = []"
                    >Limpar seleção</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="min-h-11 sm:min-h-9"
                    data-testid="quick-batch-exit"
                    @click="selectionMode = false"
                >
                    <X class="size-4" aria-hidden="true" /> Sair da seleção
                </Button>
                <Button
                    type="button"
                    size="sm"
                    class="min-h-11 flex-1 sm:min-h-9 sm:flex-none"
                    :disabled="selected.length === 0"
                    data-testid="quick-batch-confirm"
                    @click="batchDialog?.openForSelection()"
                >
                    <CheckSquare class="size-4" aria-hidden="true" /> Marcar
                    como lecionadas
                </Button>
            </div>
        </div>

        <ActivitySessionDialog
            v-model:open="sessionDialogOpen"
            :session="openedSession"
        />

        <ActivityPostponeDialog
            v-model:open="postponeOpen"
            :lesson-ulid="postponeLesson?.ulid ?? ''"
            :event="postponeEvent"
            :has-unsaved-changes="editorHasUnsavedChanges(postponeLesson?.ulid)"
            :save-first="saveSummary"
            @done="closeEditorOnLesson(postponeLesson?.ulid)"
        />

        <LessonOutcomeDialog
            v-model:open="outcomeDialogOpen"
            :lesson-ulid="outcomeLesson?.ulid ?? null"
            :reasons="absenceReasons"
            :initial-outcome="outcomeKind"
            :unsaved-changes="editorHasUnsavedChanges(outcomeLesson?.ulid)"
            :save-first="saveSummary"
            @recorded="closeEditorOnLesson(outcomeLesson?.ulid)"
            :lesson-context="
                outcomeLesson
                    ? `${outcomeLesson.context_label} · ${lessonTimeRange(outcomeLesson)}`
                    : null
            "
        />

        <LessonProjection
            :open="projection !== null"
            :projection="projection"
            @update:open="setProjectionOpen"
        />

        <UnsavedChangesDialog
            :open="guard.pending.value !== null"
            :context="editingContext"
            :saving="guard.saving.value"
            @stay="onGuardStay"
            @leave="guard.leaveWithoutSaving"
            @save="onGuardSave"
        />

        <Dialog
            :open="confirmation !== null"
            @update:open="(value: boolean) => !value && closeConfirmation()"
        >
            <DialogContent class="sm:max-w-md" :show-close-button="false">
                <DialogHeader class="space-y-2">
                    <DialogTitle>{{ confirmation?.title }}</DialogTitle>
                    <DialogDescription>{{
                        confirmation?.description
                    }}</DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 sm:min-h-9"
                        @click="closeConfirmation"
                        >Continuar a editar</Button
                    >
                    <Button
                        type="button"
                        variant="destructive"
                        class="min-h-11 sm:min-h-9"
                        data-testid="confirm-discard"
                        @click="confirmAndClose"
                        >{{ confirmation?.confirm }}</Button
                    >
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </main>
</template>
