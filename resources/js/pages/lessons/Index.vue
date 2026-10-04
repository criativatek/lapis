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
 */
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    CalendarPlus,
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
    TextQuote,
    WrapText,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import InputError from '@/components/InputError.vue';
import BatchTaughtDialog from '@/components/lessons/BatchTaughtDialog.vue';
import InsertLessonDialog from '@/components/lessons/InsertLessonDialog.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { SpecialLessonOutcome } from '@/components/lessons/LessonOutcomeDialog.vue';
import LessonQuickActions from '@/components/lessons/LessonQuickActions.vue';
import LessonTimetable from '@/components/lessons/LessonTimetable.vue';
import UnsavedChangesDialog from '@/components/lessons/UnsavedChangesDialog.vue';
import LessonClassView from '@/components/lessons/week/LessonClassView.vue';
import LessonDayNav from '@/components/lessons/week/LessonDayNav.vue';
import type { DayNavItem } from '@/components/lessons/week/LessonDayNav.vue';
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
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard';
import {
    addDays,
    dayHeading,
    dayMonthLabel,
    dayNumber,
    weekdayAbbr,
    weekRangeLabel,
} from '@/lib/lessonDates';
import {
    isQuickClosable,
    isTaughtLesson,
    lessonClock,
    lessonDate,
    lessonTimeRange,
} from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';
import {
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
    }>(),
    { classes: () => [], classView: null },
);

const page = usePage();
const state = computed<WeekViewState>(() => parseWeekViewState(page.url));
const view = computed<WeekView>(() => state.value.view);

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
        ...props.lessons,
        ...(props.classView?.lessons ?? []),
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
                props.lessons.some(
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
            schoolClass.ulid === props.classView?.class.ulid,
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
                        props.classView?.class.ulid ??
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

/** Antes de abrir uma aula: de onde se saiu, para o «Voltar» da aula regressar aqui. */
function rememberReturn(lesson: WeekLesson): void {
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
    // ao cartão de onde se saiu, sem o deslocar.
    window.requestAnimationFrame(() => {
        document
            .querySelector<HTMLElement>(
                `#aula-${ulid} [data-testid="open-lesson"]`,
            )
            ?.focus({ preventScroll: true });
        highlighted.value = ulid;
        window.setTimeout(() => (highlighted.value = null), 1600);
    });
}

// ------------------------------------------------------------ vista Semana

const visibleWeekLessons = computed(() =>
    props.lessons.filter(
        (lesson) =>
            lesson.ulid === pinnedLesson.value ||
            matchesWeekFilters(lesson, state.value, now.value),
    ),
);

const days = computed(() => {
    const dates = [...new Set(visibleWeekLessons.value.map(lessonDate))];

    return dates.map((date) => ({
        date,
        lessons: visibleWeekLessons.value.filter(
            (lesson) => lessonDate(lesson) === date,
        ),
        all: props.lessons.filter((lesson) => lessonDate(lesson) === date),
    }));
});

const facts = computed(() => weekFacts(props.lessons, now.value));

// ---------------------------------------------------------- vista Por turma

const classViewLessons = computed(() =>
    (props.classView?.lessons ?? []).filter(
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

    if (view.value === 'turma' && props.classView) {
        const lessons = classViewLessons.value;

        if (props.classView.range.key === '1') {
            return [...new Set(lessons.map(lessonDate))].map((date) => ({
                target: `dia-turma-${date}`,
                label: weekdayAbbr(date),
                sub: dayNumber(date),
                today: date === props.today,
                description: `${dayHeading(date)}${date === props.today ? ' (hoje)' : ''}`,
            }));
        }

        const weeks: string[] = [];
        let start = props.classView.range.start;
        const weekday = new Date(`${start}T12:00:00Z`).getUTCDay();
        start = addDays(start, weekday === 0 ? -6 : 1 - weekday);

        while (start <= props.classView.range.end) {
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
        props.lessons.find((lesson) => lesson.ulid === ulid) ??
        props.classView?.lessons.find((lesson) => lesson.ulid === ulid) ??
        null
    );
}

function isDirty(): boolean {
    return (
        editor.value !== null && editor.value.draft !== editor.value.original
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
function saveSummary(): Promise<boolean> {
    const current = editor.value;

    if (current === null || current.saving) {
        return Promise.resolve(false);
    }

    const content = current.draft.trim();

    if (content === '') {
        current.error =
            'Escreve o sumário antes de guardar. Para apagar o texto de um sumário, usa «Limpar sumário» na página da aula.';
        focusEditor();

        return Promise.resolve(false);
    }

    current.saving = true;
    current.error = null;
    current.notice = null;

    return new Promise((resolve) => {
        let saved = false;

        router.patch(
            `/lessons/${current.ulid}/summary/content`,
            { content, summary_version: current.baseVersion },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['lessons', 'classView'],
                onSuccess: () => {
                    saved = true;
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
    props.lessons.filter((lesson) => canQuickClose(lesson)),
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

// ------------------------------------------------------------------------ ciclo

onMounted(() => {
    clock = setInterval(() => {
        now.value = new Date();
    }, 60_000);

    // Sem vista no URL, usa a última escolhida neste browser.
    if (!page.url.includes('view=')) {
        const stored = storedView();

        if (stored === 'horario') {
            go({ view: 'horario' });
        } else if (stored === 'turma' && props.classes.length > 0) {
            go({ view: 'turma' }, { server: true });
        }
    }

    restoreFromLesson();
});

onBeforeUnmount(() => {
    if (clock !== null) {
        clearInterval(clock);
    }
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
        <!-- Cabeçalho compacto -->
        <div
            class="flex items-start justify-between gap-x-3 gap-y-2 sm:flex-wrap sm:gap-x-6"
        >
            <div class="min-w-0 flex-1">
                <h1
                    class="text-xl leading-7 font-semibold tracking-tight sm:text-[22px]"
                >
                    Aulas e Sumários
                </h1>
                <p class="text-sm text-muted-foreground" aria-live="polite">
                    Semana de {{ weekLabel
                    }}<template v-if="isCurrentWeek"> (semana atual)</template
                    ><template v-if="academicYear">
                        · {{ academicYear }}</template
                    >
                </p>
            </div>
            <div
                v-if="academicYear"
                class="flex shrink-0 flex-wrap items-center gap-2"
            >
                <InsertLessonDialog
                    v-if="insertableClasses.length > 0"
                    ref="insertDialog"
                    :classes="insertableClasses"
                    :default-date="week.start"
                    trigger-class="hidden min-h-9 sm:inline-flex"
                />
                <Button
                    type="button"
                    :variant="selectionMode ? 'secondary' : 'outline'"
                    size="sm"
                    class="hidden min-h-9 sm:inline-flex"
                    :disabled="selectableLessons.length === 0"
                    @click="selectionMode = !selectionMode"
                >
                    <CheckSquare class="size-4" aria-hidden="true" />
                    {{
                        selectionMode ? 'Terminar seleção' : 'Selecionar aulas'
                    }}
                </Button>
                <BatchTaughtDialog
                    ref="batchDialog"
                    :week-start="week.start"
                    :today="today"
                    :selected="selected"
                    trigger-class="hidden min-h-9 sm:inline-flex"
                />
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="min-h-11 min-w-11 sm:min-h-9 sm:min-w-9"
                            aria-label="Mais ações"
                        >
                            <Ellipsis class="size-4" aria-hidden="true" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-[16rem]">
                        <!-- No telemóvel, as ações que no ecrã largo têm botão próprio. -->
                        <DropdownMenuItem
                            v-if="insertableClasses.length > 0"
                            class="min-h-11 sm:hidden"
                            @select="insertDialog?.show()"
                        >
                            <CalendarPlus class="size-4" aria-hidden="true" />
                            Inserir aula
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="min-h-11 sm:hidden"
                            :disabled="selectableLessons.length === 0"
                            @select="selectionMode = !selectionMode"
                        >
                            <CheckSquare class="size-4" aria-hidden="true" />
                            {{
                                selectionMode
                                    ? 'Terminar seleção'
                                    : 'Selecionar aulas'
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            class="min-h-11 sm:hidden"
                            @select="batchDialog?.show()"
                        >
                            <CheckCheck class="size-4" aria-hidden="true" />
                            Marcar lecionadas
                        </DropdownMenuItem>
                        <DropdownMenuItem
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
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
        <InputError :message="materializeError" />

        <!-- Semana, vista e densidade -->
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <nav
                class="flex flex-1 items-center gap-1.5 sm:flex-none"
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
                    <CalendarDays class="size-4" aria-hidden="true" /> Semana
                    atual
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

            <div
                v-if="academicYear"
                class="grid w-full grid-cols-3 gap-0.5 rounded-lg border bg-card p-0.5 sm:inline-grid sm:w-auto"
                role="tablist"
                aria-label="Forma de ver a semana"
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

            <div
                v-if="academicYear && view !== 'horario'"
                class="hidden grid-cols-2 gap-0.5 rounded-lg border bg-card p-0.5 sm:ml-auto sm:inline-grid"
                role="group"
                aria-label="Sumários"
            >
                <button
                    type="button"
                    :aria-pressed="state.density === 'completo'"
                    :class="[
                        'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8',
                        state.density === 'completo'
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    ]"
                    data-testid="density-full"
                    @click="setDensity('completo')"
                >
                    <TextQuote class="size-4" aria-hidden="true" />Texto
                    completo
                </button>
                <button
                    type="button"
                    :aria-pressed="state.density === 'compacto'"
                    :class="[
                        'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8',
                        state.density === 'compacto'
                            ? 'bg-primary text-primary-foreground'
                            : 'hover:bg-accent',
                    ]"
                    data-testid="density-compact"
                    @click="setDensity('compacto')"
                >
                    <WrapText class="size-4" aria-hidden="true" />Compacto
                </button>
            </div>
        </div>

        <template
            v-if="
                (academicYear &&
                    lessons.length + (classView?.lessons.length ?? 0) > 0) ||
                (academicYear && view === 'turma')
            "
        >
            <LessonWeekFilters
                class="mt-3"
                :state="state"
                :classes="filterClasses"
                :show-classes="view !== 'turma'"
                :visible-count="
                    view === 'turma'
                        ? classViewLessons.length
                        : view === 'horario'
                          ? lessons.filter((lesson) =>
                                matchesWeekFilters(lesson, state, now),
                            ).length
                          : visibleWeekLessons.length
                "
                :total-count="
                    view === 'turma'
                        ? (classView?.lessons.length ?? 0)
                        : lessons.length
                "
                :unit-label="
                    view === 'turma'
                        ? 'aulas no intervalo'
                        : 'aulas desta semana'
                "
                @update="updateFilters"
            >
                <template #actions>
                    <div
                        v-if="view !== 'horario'"
                        class="grid grid-cols-2 gap-0.5 rounded-lg border bg-card p-0.5 sm:hidden"
                        role="group"
                        aria-label="Sumários"
                    >
                        <button
                            type="button"
                            :aria-pressed="state.density === 'completo'"
                            aria-label="Texto completo"
                            :class="[
                                'inline-flex min-h-11 items-center justify-center rounded-md px-2.5 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                state.density === 'completo'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                            ]"
                            data-testid="density-full-mobile"
                            @click="setDensity('completo')"
                        >
                            Completo
                        </button>
                        <button
                            type="button"
                            :aria-pressed="state.density === 'compacto'"
                            :class="[
                                'inline-flex min-h-11 items-center justify-center rounded-md px-2.5 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                state.density === 'compacto'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'hover:bg-accent',
                            ]"
                            data-testid="density-compact-mobile"
                            @click="setDensity('compacto')"
                        >
                            Compacto
                        </button>
                    </div>
                </template>
            </LessonWeekFilters>
            <p
                v-if="view !== 'turma' && lessons.length > 0"
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
        </template>

        <p v-if="selectionMode" class="mt-2 text-sm text-muted-foreground">
            Só é possível selecionar aulas por fechar que já começaram.
        </p>

        <LessonDayNav
            v-if="academicYear && view !== 'horario'"
            class="mt-3"
            :items="dayNavItems"
            :back-label="cameFromTimetable ? 'Voltar ao horário' : null"
            :aria-label="
                view === 'turma' && classView && classView.range.key !== '1'
                    ? 'Atalhos para as semanas'
                    : 'Atalhos para os dias'
            "
            @back="backToTimetable"
        />

        <div class="mt-2">
            <EmptyState
                v-if="!academicYear"
                title="Seleciona um ano letivo"
                description="A semana usa o ano letivo selecionado no topo da aplicação."
                :icon="CalendarDays"
            />

            <!-- POR TURMA -->
            <template v-else-if="view === 'turma'">
                <EmptyState
                    v-if="classes.length === 0 && !classView"
                    title="Ainda sem turmas neste ano letivo"
                    description="A vista por turma mostra a sequência das aulas de cada uma das tuas turmas."
                    :icon="BookOpen"
                />
                <p
                    v-else-if="!classView"
                    class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
                >
                    A carregar a turma…
                </p>
                <LessonClassView
                    v-else
                    class="mt-2"
                    :class-view="classView"
                    :classes="pickableClasses"
                    :tones="tones"
                    :lessons="classViewLessons"
                    :density="state.density"
                    @update="updateClassView"
                    @open="rememberReturn"
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
                                    @save="saveSummary"
                                    @cancel="cancelEdit"
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
                v-else-if="lessons.length === 0"
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
                :lessons="lessons"
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
                    v-if="visibleWeekLessons.length === 0"
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
                        </h2>
                        <ol class="grid gap-3">
                            <li
                                v-for="lesson in day.lessons"
                                :key="lesson.ulid"
                            >
                                <LessonWeekCard
                                    :lesson="lesson"
                                    :tone="toneOf(lesson)"
                                    mode="semana"
                                    :day-lessons="day.all"
                                    :density="state.density"
                                    :expanded="expanded.includes(lesson.ulid)"
                                    :now="now"
                                    :selection-mode="selectionMode"
                                    :selected="selected.includes(lesson.ulid)"
                                    :editing="editor?.ulid === lesson.ulid"
                                    :saved-note="
                                        savedNotes[lesson.ulid] ?? null
                                    "
                                    :highlighted="highlighted === lesson.ulid"
                                    @edit="startEdit(lesson)"
                                    @open="rememberReturn(lesson)"
                                    @toggle-expanded="
                                        toggleExpanded(lesson.ulid)
                                    "
                                    @update:selected="
                                        (value) =>
                                            setSelected(lesson.ulid, value)
                                    "
                                    @outcome="
                                        (outcome) =>
                                            openOutcome(lesson, outcome)
                                    "
                                >
                                    <template #editor>
                                        <LessonSummaryEditor
                                            v-if="
                                                editor &&
                                                editor.ulid === lesson.ulid
                                            "
                                            :ref="setEditorComponent"
                                            v-model="editor.draft"
                                            :lesson="lesson"
                                            :original="editor.original"
                                            :saving="editor.saving"
                                            :error="editor.error"
                                            :notice="editor.notice"
                                            :conflict="editor.conflict"
                                            @save="saveSummary"
                                            @cancel="cancelEdit"
                                            @combine="combineTexts"
                                            @keep-mine="keepMine"
                                            @use-stored="useStored"
                                        />
                                    </template>
                                </LessonWeekCard>
                            </li>
                        </ol>
                    </section>
                </div>
            </template>
        </div>

        <!-- Barra do lote rápido: fixa no fundo enquanto se seleciona. -->
        <div
            v-if="selectionMode && academicYear"
            class="sticky bottom-4 z-10 mt-4 flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 text-sm shadow-lg"
            data-testid="quick-batch-bar"
        >
            <span role="status" aria-live="polite" class="font-medium">
                {{ selected.length }} aula{{
                    selected.length === 1 ? '' : 's'
                }}
                selecionada{{ selected.length === 1 ? '' : 's' }}
            </span>
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
                size="sm"
                class="ml-auto min-h-11 sm:min-h-9"
                :disabled="selected.length === 0"
                data-testid="quick-batch-confirm"
                @click="batchDialog?.openForSelection()"
            >
                <CheckSquare class="size-4" aria-hidden="true" /> Marcar
                selecionadas como lecionadas
            </Button>
        </div>

        <LessonOutcomeDialog
            v-model:open="outcomeDialogOpen"
            :lesson-ulid="outcomeLesson?.ulid ?? null"
            :reasons="absenceReasons"
            :initial-outcome="outcomeKind"
            :lesson-context="
                outcomeLesson
                    ? `${outcomeLesson.context_label} · ${lessonTimeRange(outcomeLesson)}`
                    : null
            "
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
