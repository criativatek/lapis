<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    BookOpen,
    CalendarDays,
    CheckCircle2,
    CheckSquare,
    ChevronLeft,
    ChevronRight,
    FileText,
    LayoutGrid,
    List,
    ListOrdered,
    RefreshCw,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import BatchTaughtDialog from '@/components/lessons/BatchTaughtDialog.vue';
import InsertLessonDialog from '@/components/lessons/InsertLessonDialog.vue';
import LessonTimetable from '@/components/lessons/LessonTimetable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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

const selectableLessons = computed(() =>
    props.lessons.filter((lesson) => lesson.status !== 'taught'),
);

/** Ver LessonTimetable: «indeterminate» conta como não selecionada. */
function toggle(ulid: string, checked: boolean | 'indeterminate'): void {
    selected.value =
        checked === true
            ? [...new Set([...selected.value, ulid])]
            : selected.value.filter((value) => value !== ulid);
}

function selectAll(): void {
    selected.value = selectableLessons.value.map((lesson) => lesson.ulid);
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

        <div
            v-if="selectionMode"
            class="flex flex-wrap items-center gap-3 rounded-xl border bg-muted/40 p-3 text-sm"
            role="status"
        >
            <span
                >{{ selected.length }} de
                {{ selectableLessons.length }} aulas selecionadas.</span
            >
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="min-h-9"
                @click="selectAll"
            >
                Selecionar todas
            </Button>
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
        </div>

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
                    :class="['mt-1.5', statusToneClasses(lesson.status)]"
                    >{{ lesson.status_label }}</Badge
                >
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
                        class="flex items-start"
                    >
                        <div v-if="selectionMode" class="py-6 pl-4">
                            <Checkbox
                                :model-value="selected.includes(lesson.ulid)"
                                :disabled="lesson.status === 'taught'"
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
                                        <span class="font-medium">{{
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
                                    <Badge
                                        variant="secondary"
                                        :class="statusToneClasses(lesson.status)"
                                        >{{ lesson.status_label }}</Badge
                                    >
                                    <span
                                        v-if="lesson.has_summary"
                                        class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400"
                                        ><CheckCircle2 class="size-3.5" /> Sumário</span
                                    >
                                </div>
                            </Link>
                            <!-- FORA do <Link>: um botão dentro de uma ligação
                                 navegaria ao ser ativado pelo teclado, e o que
                                 este faz é abrir texto, não mudar de página. -->
                            <div v-if="lesson.summary_full" class="px-4 pb-3">
                                <Button
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
                            </div>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </main>
</template>
