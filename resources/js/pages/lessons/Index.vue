<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    CheckCircle2,
    CheckSquare,
    CircleAlert,
    ChevronLeft,
    ChevronRight,
    FileText,
    LayoutGrid,
    List,
    ListOrdered,
    RefreshCw,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import BatchTaughtDialog from '@/components/lessons/BatchTaughtDialog.vue';
import InsertLessonDialog from '@/components/lessons/InsertLessonDialog.vue';
import LessonOutcomeDialog from '@/components/lessons/LessonOutcomeDialog.vue';
import type { SpecialLessonOutcome } from '@/components/lessons/LessonOutcomeDialog.vue';
import LessonQuickActions from '@/components/lessons/LessonQuickActions.vue';
import LessonTimetable from '@/components/lessons/LessonTimetable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { isQuickClosable, lessonDisplayState, lessonQuickCloseState } from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';
import { statusToneClasses } from '@/lib/statusTone';
import { capitalizeFirst } from '@/lib/text';

type InsertableClass = {
    ulid: string;
    label: string;
    subject: string;
    groups: { id: number; label: string }[];
};

const props = defineProps<{
    lessons: WeekLesson[];
    week: { start: string; end: string };
    academicYear: string | null;
    configuredClassesCount: number;
    today: string;
    insertableClasses: InsertableClass[];
    /** As categorias de «Professor ausente» — uma lista para a página, não uma por cartão. */
    absenceReasons: { value: string; label: string }[];
}>();

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'Europe/Lisbon',
});
const shortDateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});
const timeFormatter = new Intl.DateTimeFormat('pt-PT', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Lisbon',
});
const materializeForm = useForm({
    from: props.week.start,
    to: props.week.end,
});
const materializeError = computed(
    () => Object.values(materializeForm.errors)[0],
);

/**
 * A vista escolhida vive no browser, e não no URL nem no servidor: é uma
 * preferência de leitura da mesma semana, e não um recurso diferente. Guardada
 * em localStorage para que quem prefere o horário não tenha de o escolher outra
 * vez a cada aula que abre e fecha. Dentro de try/catch porque uma janela
 * anónima com dados de site bloqueados atira ao ler.
 */
type ViewMode = 'list' | 'timetable';

function storedView(): ViewMode {
    try {
        return window.localStorage.getItem('lapis.lessons.view') === 'timetable'
            ? 'timetable'
            : 'list';
    } catch {
        return 'list';
    }
}

const view = ref<ViewMode>(storedView());

watch(view, (value) => {
    try {
        window.localStorage.setItem('lapis.lessons.view', value);
    } catch {
        // Sem persistência não se perde nada: a vista continua a funcionar.
    }
});

/** A semana NÃO muda ao alternar de vista: o alternador não navega (§26). */
const days = computed(() => {
    const grouped = new Map<string, WeekLesson[]>();

    for (const lesson of props.lessons) {
        const key = lesson.starts_at.slice(0, 10);
        grouped.set(key, [...(grouped.get(key) ?? []), lesson]);
    }

    return [...grouped.entries()].map(([date, lessons]) => ({ date, lessons }));
});

const weekLabel = computed(
    () =>
        `${shortDateFormatter.format(new Date(`${props.week.start}T00:00:00Z`))} – ${shortDateFormatter.format(new Date(`${props.week.end}T00:00:00Z`))}`,
);

// Seleção para o lote. Limpa-se sempre que a semana muda: um ulid selecionado
// numa semana que já saiu do ecrã seria uma aula marcada às escuras.
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

/**
 * «Agora», reativo (0.147.0). Atualizado a cada minuto para que uma aula que
 * termina com a página aberta passe a pedir confirmação sem recarregar. É só
 * apresentação: o servidor não lê este relógio para nada.
 */
const now = ref(new Date());
let clock: ReturnType<typeof setInterval> | null = null;

onMounted(() => {
    clock = setInterval(() => {
        now.value = new Date();
    }, 60_000);
});

onBeforeUnmount(() => {
    if (clock !== null) {
        clearInterval(clock);
    }
});

function quickState(lesson: WeekLesson) {
    return lessonQuickCloseState(lesson, now.value);
}

function canQuickClose(lesson: WeekLesson): boolean {
    return isQuickClosable(lesson, now.value);
}

// Só aulas abertas que já começaram entram no lote rápido: uma aula futura
// não é selecionável, e não existe «selecionar todas» às cegas.
const selectableLessons = computed(() => props.lessons.filter((lesson) => canQuickClose(lesson)));

// Depois de fechar aulas (cartão ou lote) as props recarregam: o que deixou de
// ser elegível sai da seleção em vez de ficar lá escondido.
watch(selectableLessons, (lessons) => {
    const eligible = new Set(lessons.map((lesson) => lesson.ulid));
    selected.value = selected.value.filter((ulid) => eligible.has(ulid));
});

/** Ver LessonTimetable: «indeterminate» conta como não selecionada. */
function toggle(ulid: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...new Set([...selected.value, ulid])]
            : selected.value.filter((value) => value !== ulid);
}

const batchDialog = ref<InstanceType<typeof BatchTaughtDialog> | null>(null);

function confirmSelection(): void {
    batchDialog.value?.openForSelection();
}

// Um só diálogo de resultado para a página inteira, apontado à aula escolhida.
const outcomeDialogOpen = ref(false);
const outcomeLesson = ref<WeekLesson | null>(null);
const outcomeKind = ref<SpecialLessonOutcome>('teacher_absent');

function openOutcome(lesson: WeekLesson, outcome: SpecialLessonOutcome): void {
    outcomeLesson.value = lesson;
    outcomeKind.value = outcome;
    outcomeDialogOpen.value = true;
}

// «Ver sumário completo»: expansão inline, um clique, sem hover e sem ir ao
// servidor — o texto completo já veio com a semana quando é maior do que o
// excerto (WeeklyLessonsQuery).
const expanded = ref<string[]>([]);

function toggleExpanded(ulid: string): void {
    expanded.value = expanded.value.includes(ulid)
        ? expanded.value.filter((value) => value !== ulid)
        : [...expanded.value, ulid];
}

function navigate(offsetDays: number): void {
    const date = new Date(`${props.week.start}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + offsetDays);
    router.get(
        '/lessons',
        { week: date.toISOString().slice(0, 10) },
        { preserveState: true, preserveScroll: true },
    );
}

function goToday(): void {
    router.get('/lessons', { week: props.today }, { preserveState: true });
}

function materialize(): void {
    materializeForm.from = props.week.start;
    materializeForm.to = props.week.end;
    materializeForm.post('/lessons/materialize-week', { preserveScroll: true });
}

function dayLabel(date: string): string {
    return capitalizeFirst(
        dateFormatter.format(new Date(`${date}T12:00:00+01:00`)),
    );
}

function lessonTime(lesson: WeekLesson): string {
    const start = timeFormatter.format(new Date(lesson.starts_at));

    return lesson.ends_at
        ? `${start}–${timeFormatter.format(new Date(lesson.ends_at))}`
        : start;
}

function lessonNumberLabel(lesson: WeekLesson): string | null {
    return lesson.lesson_number === null ? null : `Lição ${lesson.lesson_number}`;
}

/**
 * «N falta(s)» ou «Sem faltas» quando a assiduidade já está consolidada;
 * nada quando ainda não está — mostrar um número aqui seria confundir um
 * rascunho ou a ausência de registo com um registo real.
 */
function attendanceLabel(lesson: WeekLesson): string | null {
    if (!lesson.attendance_recorded || lesson.absent_count === null) {
        return null;
    }

    return lesson.absent_count === 0 ? 'Sem faltas' : `${lesson.absent_count} falta${lesson.absent_count === 1 ? '' : 's'}`;
}
</script>

<template>
    <Head title="Aulas e Sumários" />
    <main class="mx-auto w-full max-w-4xl space-y-6 p-4 pb-24 sm:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Aulas e Sumários"
                :description="
                    academicYear
                        ? `Semana de ${weekLabel} · ${academicYear}`
                        : `Semana de ${weekLabel}`
                "
            />
            <Button
                variant="outline"
                size="sm"
                class="min-h-10 shrink-0"
                title="As aulas desta semana são criadas automaticamente ao abrir a página. Usa isto para refletir de imediato uma alteração ao horário recorrente."
                :disabled="materializeForm.processing || !academicYear"
                @click="materialize"
            >
                <RefreshCw class="size-4" />{{
                    materializeForm.processing
                        ? 'A atualizar…'
                        : 'Atualizar aulas desta semana'
                }}
            </Button>
        </div>
        <InputError :message="materializeError" />

        <nav
            class="flex flex-wrap items-center gap-2"
            aria-label="Navegação entre semanas"
        >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="navigate(-7)"
                ><ChevronLeft class="size-4" /> Semana anterior</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="goToday"
                ><CalendarDays class="size-4" /> Semana atual</Button
            >
            <Button
                variant="outline"
                size="sm"
                class="min-h-10"
                @click="navigate(7)"
                >Semana seguinte <ChevronRight class="size-4"
            /></Button>
            <Button as-child variant="ghost" size="sm" class="ml-auto min-h-10">
                <Link href="/lessons/sequences">
                    <ListOrdered class="size-4" /> Sequências de aulas
                </Link>
            </Button>
        </nav>

        <!-- Lista | Horário. NÃO navega: as duas vistas leem as mesmas aulas
             que já estão nesta página, e por isso a semana apresentada não pode
             mudar ao alternar (§26). -->
        <div v-if="academicYear" class="flex flex-wrap items-center gap-2">
            <div
                class="inline-flex rounded-lg border p-0.5"
                role="tablist"
                aria-label="Forma de ver a semana"
            >
                <Button
                    type="button"
                    role="tab"
                    :aria-selected="view === 'list'"
                    :variant="view === 'list' ? 'default' : 'ghost'"
                    size="sm"
                    class="min-h-9"
                    @click="view = 'list'"
                >
                    <List class="size-4" /> Lista
                </Button>
                <Button
                    type="button"
                    role="tab"
                    :aria-selected="view === 'timetable'"
                    :variant="view === 'timetable' ? 'default' : 'ghost'"
                    size="sm"
                    class="min-h-9"
                    @click="view = 'timetable'"
                >
                    <LayoutGrid class="size-4" /> Horário
                </Button>
            </div>

            <Button
                type="button"
                :variant="selectionMode ? 'secondary' : 'outline'"
                size="sm"
                class="min-h-9"
                :disabled="selectableLessons.length === 0"
                @click="selectionMode = !selectionMode"
            >
                <CheckSquare class="size-4" />
                {{ selectionMode ? 'Terminar seleção' : 'Selecionar aulas' }}
            </Button>

            <BatchTaughtDialog
                ref="batchDialog"
                :week-start="week.start"
                :today="today"
                :selected="selected"
            />

            <InsertLessonDialog
                v-if="insertableClasses.length > 0"
                :classes="insertableClasses"
                :default-date="week.start"
            />
        </div>

        <p v-if="selectionMode" class="text-sm text-muted-foreground">
            Só é possível selecionar aulas por fechar que já começaram.
        </p>

        <EmptyState
            v-if="!academicYear"
            title="Seleciona um ano letivo"
            description="A semana usa o ano letivo selecionado no topo da aplicação."
            :icon="CalendarDays"
        />

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

        <!-- VISTA HORÁRIO — as mesmas `lessons`, sem segunda consulta. -->
        <LessonTimetable
            v-else-if="view === 'timetable'"
            :lessons="lessons"
            :week-start="week.start"
            :today="today"
            :selectable="selectionMode"
            :is-selectable="canQuickClose"
            :selected="selected"
            @update:selected="selected = $event"
        >
            <template #block="{ lesson, time }">
                <p class="text-xs font-medium tabular-nums text-muted-foreground">
                    {{ time }}
                </p>
                <p class="truncate text-sm font-medium">
                    {{ lesson.context_label }}
                </p>
                <p
                    v-if="lessonNumberLabel(lesson)"
                    class="text-xs tabular-nums text-muted-foreground"
                >
                    {{ lessonNumberLabel(lesson) }}
                </p>
                <p
                    v-if="lesson.summary_excerpt"
                    class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                >
                    {{ lesson.summary_excerpt }}
                </p>
                <Badge
                    variant="secondary"
                    data-testid="lesson-state"
                    :class="['mt-1.5 max-w-full whitespace-normal text-left', statusToneClasses(lessonDisplayState(lesson).value)]"
                    >{{ lessonDisplayState(lesson).label }}</Badge
                >
                <p
                    v-if="attendanceLabel(lesson)"
                    class="mt-1 text-xs text-muted-foreground"
                >
                    {{ attendanceLabel(lesson) }}
                </p>
                <p
                    v-if="quickState(lesson) === 'ended'"
                    class="mt-1 flex items-start gap-1 text-xs font-medium text-amber-700 dark:text-amber-400"
                    data-testid="lesson-attention"
                >
                    <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" /> Aula terminada · Confirmar estado
                </p>
            </template>
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

        <!-- VISTA LISTA -->
        <div v-else class="space-y-6">
            <section v-for="day in days" :key="day.date" class="space-y-2">
                <h2 class="text-sm font-semibold">
                    {{ dayLabel(day.date) }}
                </h2>
                <ul class="divide-y rounded-xl border bg-card">
                    <li
                        v-for="lesson in day.lessons"
                        :key="lesson.ulid"
                        class="group flex items-start"
                    >
                        <div v-if="selectionMode" class="w-8 shrink-0 py-6 pl-4">
                            <Checkbox
                                v-if="canQuickClose(lesson)"
                                :model-value="selected.includes(lesson.ulid)"
                                :aria-label="`Selecionar a aula de ${lesson.context_label}`"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') => toggle(lesson.ulid, value)
                                "
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="`/lessons/${lesson.ulid}`"
                                class="flex min-h-20 items-start gap-3 p-4 transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring sm:items-center"
                            >
                                <time
                                    class="w-24 shrink-0 text-sm font-medium tabular-nums"
                                    >{{ lessonTime(lesson) }}</time
                                >
                                <div class="min-w-0 flex-1">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="font-medium whitespace-nowrap">{{
                                            lesson.context_label
                                        }}</span>
                                        <span class="text-sm text-muted-foreground">{{
                                            lesson.subject
                                        }}</span>
                                        <!-- O número da lição aparece nas DUAS
                                             vistas e diz o mesmo nas duas: é o
                                             mesmo campo da mesma aula. -->
                                        <span
                                            v-if="lessonNumberLabel(lesson)"
                                            class="rounded-md bg-muted px-1.5 py-0.5 text-xs font-medium tabular-nums"
                                            >{{ lessonNumberLabel(lesson) }}</span
                                        >
                                    </div>
                                    <p
                                        v-if="
                                            lesson.summary_excerpt &&
                                            !expanded.includes(lesson.ulid)
                                        "
                                        class="mt-1 truncate text-sm text-muted-foreground"
                                    >
                                        {{ lesson.summary_excerpt }}
                                    </p>
                                    <p
                                        v-else-if="lesson.summary_excerpt"
                                        class="mt-1 text-sm whitespace-pre-line text-muted-foreground"
                                    >
                                        {{ lesson.summary_full ?? lesson.summary_excerpt }}
                                    </p>
                                    <p
                                        v-else
                                        class="mt-1 flex items-center gap-1 text-xs text-muted-foreground"
                                    >
                                        <FileText class="size-3.5" /> Sem sumário
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <!-- Tom pela paleta da casa (SUP-UEVAH4): dada verde, preparada azul. -->
                                    <!-- Um só estado: o resultado da ocorrência, se existir; senão a preparação (0.146.1). -->
                                    <Badge
                                        variant="secondary"
                                        data-testid="lesson-state"
                                        :class="['max-w-[7.5rem] whitespace-normal text-right sm:max-w-[11rem]', statusToneClasses(lessonDisplayState(lesson).value)]"
                                        >{{ lessonDisplayState(lesson).label }}</Badge
                                    >
                                    <span
                                        v-if="lesson.has_summary"
                                        class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                                        ><CheckCircle2 class="size-3.5" /> Sumário</span
                                    >
                                    <span
                                        v-if="attendanceLabel(lesson)"
                                        class="text-xs text-muted-foreground"
                                        >{{ attendanceLabel(lesson) }}</span
                                    >
                                    <span
                                        v-if="quickState(lesson) === 'ended'"
                                        class="flex max-w-[7.5rem] items-start justify-end gap-1 text-right text-xs font-medium text-amber-700 sm:max-w-[11rem] sm:items-center dark:text-amber-400"
                                        data-testid="lesson-attention"
                                        ><CircleAlert class="mt-0.5 size-3.5 shrink-0 sm:mt-0" aria-hidden="true" /> Aula terminada · Confirmar estado</span
                                    >
                                </div>
                            </Link>
                            <!-- FORA do <Link>: um botão dentro de uma ligação
                                 navegaria ao ser ativado pelo teclado, e o que
                                 este faz é abrir texto, não mudar de página. -->
                            <div
                                v-if="lesson.summary_full || canQuickClose(lesson)"
                                class="flex flex-wrap items-center justify-between gap-2 px-4 pb-3"
                            >
                                <Button
                                    v-if="lesson.summary_full"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="-ml-2 min-h-9"
                                    :aria-expanded="expanded.includes(lesson.ulid)"
                                    @click="toggleExpanded(lesson.ulid)"
                                >
                                    {{
                                        expanded.includes(lesson.ulid)
                                            ? 'Ver menos'
                                            : 'Ver sumário completo'
                                    }}
                                </Button>
                                <!-- Fecho rápido (0.147.0): só aulas abertas que já começaram. -->
                                <LessonQuickActions
                                    v-if="canQuickClose(lesson)"
                                    class="ml-auto w-full sm:w-auto"
                                    :lesson-ulid="lesson.ulid"
                                    :context-label="lesson.context_label"
                                    :time="lessonTime(lesson)"
                                    @outcome="(outcome) => openOutcome(lesson, outcome)"
                                />
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Barra do lote rápido: fixa no fundo enquanto se seleciona. -->
        <div
            v-if="selectionMode && academicYear"
            class="sticky bottom-4 z-10 flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 text-sm shadow-lg"
            data-testid="quick-batch-bar"
        >
            <span role="status" aria-live="polite" class="font-medium">
                {{ selected.length }} aula{{ selected.length === 1 ? '' : 's' }} selecionada{{ selected.length === 1 ? '' : 's' }}
            </span>
            <Button
                v-if="selected.length > 0"
                type="button"
                variant="ghost"
                size="sm"
                class="min-h-9"
                @click="selected = []"
            >
                Limpar seleção
            </Button>
            <Button
                type="button"
                size="sm"
                class="ml-auto min-h-11 sm:min-h-9"
                :disabled="selected.length === 0"
                data-testid="quick-batch-confirm"
                @click="confirmSelection"
            >
                <CheckSquare class="size-4" /> Marcar selecionadas como lecionadas
            </Button>
        </div>

        <LessonOutcomeDialog
            v-model:open="outcomeDialogOpen"
            :lesson-ulid="outcomeLesson?.ulid ?? null"
            :reasons="absenceReasons"
            :initial-outcome="outcomeKind"
            :lesson-context="outcomeLesson ? `${outcomeLesson.context_label} · ${lessonTime(outcomeLesson)}` : null"
        />
    </main>
</template>
