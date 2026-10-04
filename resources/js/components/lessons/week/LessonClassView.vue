<script setup lang="ts">
/**
 * A VISTA POR TURMA — a sequência de uma turma, lição a lição (0.158.0).
 *
 * Só lê. O servidor monta as aulas da turma no intervalo pedido sem criar nem
 * materializar nenhuma (abrir uma semana continua a ser a única coisa que cria
 * as aulas dessa semana); por isso uma semana que nunca foi aberta pode não
 * ter aulas, e diz-se em vez de se fingir que não havia horário.
 *
 * O SUMÁRIO ANTERIOR É DO MESMO GRUPO, E DE MAIS NENHUM (a regra de
 * «Basear no sumário anterior»): T1 e T2 avançam a ritmos diferentes. Com um
 * grupo escolhido, o anterior desse grupo vem à frente e sozinho; com todos os
 * grupos, os anteriores de cada um aparecem lado a lado num bloco recolhível.
 *
 * Os cartões são os da vista Semana (slot `card`), para as duas vistas nunca
 * dizerem coisas diferentes da mesma aula.
 */
import { Link, router } from '@inertiajs/vue3';
import { ChevronDown, History, Presentation } from '@lucide/vue';
import { computed, onMounted, ref, watch } from 'vue';
import LessonIdentity from '@/components/lessons/week/LessonIdentity.vue';
import { Button } from '@/components/ui/button';
import {
    addDays,
    dayHeading,
    dayMonthLabel,
    shortDayLabel,
} from '@/lib/lessonDates';
import {
    lessonDate,
    lessonDisplayState,
    summaryOneLine,
    summaryParagraphs,
} from '@/lib/lessons';
import type { LessonScope, WeekLesson } from '@/lib/lessons';
import { lessonStateIcon } from '@/lib/lessonStateIcons';
import type {
    ClassRange,
    ClassViewData,
    Density,
    TeacherClass,
} from '@/lib/lessonWeekView';
import type { TurmaTone } from '@/lib/turmaTones';

const props = defineProps<{
    classView: ClassViewData;
    classes: TeacherClass[];
    tones: Map<string, TurmaTone>;
    /** As aulas do intervalo que passam os filtros secundários. */
    lessons: WeekLesson[];
    density: Density;
}>();

const emit = defineEmits<{
    update: [patch: { classUlid?: string; group?: string; range?: ClassRange }];
    open: [lesson: WeekLesson];
    project: [lesson: WeekLesson];
}>();

defineSlots<{
    card(props: { lesson: WeekLesson; dayLessons: WeekLesson[] }): unknown;
}>();

const schoolClass = computed(() => props.classView.class);
const tone = computed<TurmaTone>(
    () => props.tones.get(schoolClass.value.ulid) ?? 'blue',
);
const groupOptions = computed(() => [
    { value: 'todos', label: 'Todos' },
    { value: 'inteira', label: 'Turma inteira' },
    ...schoolClass.value.groups.map((group) => ({
        value: String(group.id),
        label: group.label,
    })),
]);
const RANGES: { value: ClassRange; label: string }[] = [
    { value: '1', label: 'Esta semana' },
    { value: '2', label: '2 semanas' },
    { value: '4', label: '4 semanas' },
    { value: 'ano', label: 'Ano letivo' },
];
const nextRange = computed<ClassRange | null>(
    () =>
        ({ '1': '2', '2': '4', '4': 'ano', ano: null })[
            props.classView.range.key
        ] as ClassRange | null,
);

const numbers = computed(() =>
    props.classView.lessons
        .map((lesson) => lesson.lesson_number)
        .filter((value): value is number => value !== null),
);

/** As semanas do intervalo, cada uma com os seus dias — e as que não têm aula nenhuma registada. */
const weeks = computed(() => {
    const result: {
        start: string;
        days: { date: string; lessons: WeekLesson[] }[];
        empty: boolean;
    }[] = [];
    let start = mondayOf(props.classView.range.start);

    while (start <= props.classView.range.end) {
        const end = addDays(start, 6);
        const inWeek = props.lessons.filter(
            (lesson) =>
                lessonDate(lesson) >= start && lessonDate(lesson) <= end,
        );
        const dates = [...new Set(inWeek.map(lessonDate))];
        result.push({
            start,
            days: dates.map((date) => ({
                date,
                lessons: inWeek.filter((lesson) => lessonDate(lesson) === date),
            })),
            empty: !props.classView.lessons.some(
                (lesson) =>
                    lessonDate(lesson) >= start && lessonDate(lesson) <= end,
            ),
        });
        start = addDays(start, 7);
    }

    return result;
});

const multiWeek = computed(() => weeks.value.length > 1);

function mondayOf(date: string): string {
    const weekday = new Date(`${date}T12:00:00Z`).getUTCDay();

    return addDays(date, weekday === 0 ? -6 : 1 - weekday);
}

function laneScope(entry: ClassViewData['previous'][number]): LessonScope {
    if (entry.group_id !== null) {
        return {
            kind: 'group',
            label: `Grupo ${entry.group_label ?? ''}`.trim(),
        };
    }

    if (schoolClass.value.is_support_class) {
        return { kind: 'support', label: 'Turma de apoio' };
    }

    return { kind: 'whole', label: 'Turma inteira' };
}

// Com todos os grupos, o bloco dos anteriores recolhe-se; a escolha fica neste browser.
const PREVIOUS_KEY = 'lapis.lessons.previousCollapsed';
const previousCollapsed = ref(false);

onMounted(() => {
    try {
        previousCollapsed.value =
            window.localStorage.getItem(PREVIOUS_KEY) === '1';
    } catch {
        previousCollapsed.value = false;
    }
});

watch(previousCollapsed, (value) => {
    try {
        window.localStorage.setItem(PREVIOUS_KEY, value ? '1' : '0');
    } catch {
        // Sem persistência não se perde nada.
    }
});

const allGroups = computed(() => props.classView.group === 'todos');
const focusedLane = computed(() =>
    allGroups.value ? null : (props.classView.previous[0] ?? null),
);

function openWeek(start: string): void {
    router.get('/lessons', { week: start });
}

function radioKeydown(event: KeyboardEvent, index: number): void {
    if (
        ![
            'ArrowLeft',
            'ArrowRight',
            'ArrowUp',
            'ArrowDown',
            'Home',
            'End',
        ].includes(event.key)
    ) {
        return;
    }

    event.preventDefault();
    const count = props.classes.length;
    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? count - 1
              : (index +
                    (['ArrowRight', 'ArrowDown'].includes(event.key) ? 1 : -1) +
                    count) %
                count;
    const target = props.classes[next];

    if (target) {
        emit('update', { classUlid: target.ulid, group: 'todos' });
    }
}

const segment =
    'inline-flex min-h-11 min-w-11 items-center justify-center rounded-md px-3 text-sm font-medium outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8 aria-pressed:bg-primary aria-pressed:text-primary-foreground hover:bg-accent aria-pressed:hover:bg-primary';
</script>

<template>
    <div class="grid gap-4" data-testid="class-view">
        <!-- A turma -->
        <div
            role="radiogroup"
            aria-label="Escolher turma"
            class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2 sm:flex sm:flex-wrap"
        >
            <button
                v-for="(candidate, index) in classes"
                :key="candidate.ulid"
                type="button"
                role="radio"
                :aria-checked="candidate.ulid === schoolClass.ulid"
                :tabindex="candidate.ulid === schoolClass.ulid ? 0 : -1"
                :class="[
                    'flex min-h-11 items-center gap-2 rounded-lg border bg-card py-1.5 pr-3 pl-1.5 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                    candidate.ulid === schoolClass.ulid
                        ? 'border-primary ring-2 ring-primary/30'
                        : 'hover:border-foreground/30',
                ]"
                :data-testid="`class-pick-${candidate.ulid}`"
                @click="
                    emit('update', {
                        classUlid: candidate.ulid,
                        group: 'todos',
                    })
                "
                @keydown="radioKeydown($event, index)"
            >
                <LessonIdentity
                    :class-label="candidate.label"
                    :tone="tones.get(candidate.ulid) ?? 'blue'"
                />
                <span class="text-[13px] leading-4 text-muted-foreground">
                    {{ candidate.subject
                    }}<template v-if="candidate.is_support_class">
                        · turma de apoio</template
                    ><template v-else-if="candidate.groups.length">
                        ·
                        {{
                            candidate.groups
                                .map((group) => group.label)
                                .join(' / ')
                        }}</template
                    >
                </span>
            </button>
        </div>

        <!-- Grupo e intervalo -->
        <div class="flex flex-wrap items-center gap-x-5 gap-y-2">
            <div
                v-if="schoolClass.groups.length > 0"
                class="flex flex-wrap items-center gap-2"
            >
                <span
                    id="class-view-group"
                    class="text-[13px] font-semibold text-muted-foreground"
                    >Grupo</span
                >
                <div
                    role="group"
                    aria-labelledby="class-view-group"
                    class="inline-flex flex-wrap gap-0.5 rounded-lg border bg-card p-0.5"
                >
                    <button
                        v-for="option in groupOptions"
                        :key="option.value"
                        type="button"
                        :class="segment"
                        :aria-pressed="classView.group === option.value"
                        :data-testid="`class-group-${option.value}`"
                        @click="emit('update', { group: option.value })"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <span
                    id="class-view-range"
                    class="text-[13px] font-semibold text-muted-foreground"
                    >Intervalo</span
                >
                <div
                    role="group"
                    aria-labelledby="class-view-range"
                    class="inline-flex flex-wrap gap-0.5 rounded-lg border bg-card p-0.5"
                >
                    <button
                        v-for="option in RANGES"
                        :key="option.value"
                        type="button"
                        :class="segment"
                        :aria-pressed="classView.range.key === option.value"
                        :data-testid="`class-range-${option.value}`"
                        @click="emit('update', { range: option.value })"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Cabeçalho da turma e sequência -->
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
            <h2
                class="flex flex-wrap items-center gap-2 text-xl leading-7 font-semibold"
            >
                <LessonIdentity :class-label="schoolClass.label" :tone="tone" />
                <span>{{ schoolClass.subject }}</span>
            </h2>
            <p
                class="text-sm text-muted-foreground"
                data-testid="class-view-range-summary"
            >
                {{ dayMonthLabel(classView.range.start) }} –
                {{ dayMonthLabel(classView.range.end) }} ·
                {{ classView.lessons.length }} aula{{
                    classView.lessons.length === 1 ? '' : 's'
                }}<template v-if="numbers.length">
                    · Lições {{ Math.min(...numbers) }} a
                    {{ Math.max(...numbers) }}</template
                ><template v-if="classView.range.clamped">
                    · desde o início do ano letivo</template
                >
            </p>
        </div>

        <ol
            v-if="lessons.length > 0"
            class="flex flex-wrap gap-1.5"
            aria-label="Sequência de lições no intervalo"
            data-testid="class-view-sequence"
        >
            <li v-for="lesson in lessons" :key="`seq-${lesson.ulid}`">
                <a
                    :href="`#aula-${lesson.ulid}`"
                    class="inline-flex min-h-11 items-center gap-1.5 rounded-md border bg-card px-2.5 text-[13px] font-medium tabular-nums outline-none hover:border-foreground/30 focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8"
                >
                    <component
                        :is="lessonStateIcon(lessonDisplayState(lesson).value)"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    <span>{{
                        lesson.lesson_number === null
                            ? '—'
                            : `L${lesson.lesson_number}`
                    }}</span>
                    <span
                        v-if="lesson.class_group_label"
                        class="font-semibold"
                        >{{ lesson.class_group_label }}</span
                    >
                    <span class="text-muted-foreground">{{
                        shortDayLabel(lessonDate(lesson))
                    }}</span>
                    <span class="sr-only"
                        >, {{ lessonDisplayState(lesson).label }}</span
                    >
                </a>
            </li>
        </ol>

        <!-- O sumário anterior: do grupo escolhido, à frente; de todos, num bloco recolhível. -->
        <section
            v-if="focusedLane"
            aria-labelledby="class-view-previous"
            class="grid gap-2"
            data-testid="class-view-previous"
        >
            <h3
                id="class-view-previous"
                class="flex items-center gap-2 text-[15px] font-semibold"
            >
                <History
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Último sumário de {{ laneScope(focusedLane).label }} antes de
                {{ dayMonthLabel(classView.range.start) }}
            </h3>
            <article
                class="relative rounded-[10px] border bg-card py-3.5 pr-4 pl-[22px]"
                data-testid="class-view-previous-lane"
            >
                <span
                    class="absolute inset-y-0 left-0 w-1.5 rounded-l-[10px] bg-muted-foreground/40"
                    aria-hidden="true"
                />
                <template v-if="focusedLane.lesson">
                    <p
                        class="mb-2 flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground"
                    >
                        <strong class="font-semibold text-foreground">{{
                            focusedLane.lesson.lesson_number === null
                                ? 'Sem número'
                                : `Lição ${focusedLane.lesson.lesson_number}`
                        }}</strong>
                        <span>{{
                            shortDayLabel(lessonDate(focusedLane.lesson))
                        }}</span>
                    </p>
                    <div
                        v-if="density === 'compacto'"
                        class="line-clamp-2 max-w-[72ch] text-base leading-relaxed"
                    >
                        {{ summaryOneLine(focusedLane.lesson.summary ?? '') }}
                    </div>
                    <div
                        v-else
                        class="grid max-w-[72ch] gap-2 text-base leading-relaxed"
                    >
                        <p
                            v-for="(paragraph, index) in summaryParagraphs(
                                focusedLane.lesson.summary ?? '',
                            )"
                            :key="index"
                            class="whitespace-pre-line"
                        >
                            {{ paragraph }}
                        </p>
                    </div>
                    <Link
                        :href="`/lessons/${focusedLane.lesson.ulid}`"
                        class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-primary underline underline-offset-2 sm:min-h-0"
                        @click="emit('open', focusedLane.lesson)"
                    >
                        Abrir aula
                    </Link>
                    <button
                        type="button"
                        class="mt-2 ml-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-primary underline underline-offset-2 sm:min-h-0"
                        :aria-label="`Projetar sumário — ${focusedLane.lesson.context_label}`"
                        :data-testid="`previous-project-${focusedLane.lesson.ulid}`"
                        @click="emit('project', focusedLane.lesson)"
                    >
                        <Presentation class="size-4" aria-hidden="true" />
                        Projetar sumário
                    </button>
                </template>
                <p v-else class="text-sm text-muted-foreground">
                    Sem sumário anterior<template
                        v-if="
                            classView.range.clamped ||
                            classView.range.key === 'ano'
                        "
                    >
                        — o intervalo começa no início do ano letivo</template
                    >.
                </p>
            </article>
        </section>

        <section
            v-else
            class="grid gap-2"
            aria-labelledby="class-view-previous"
            data-testid="class-view-previous"
        >
            <h3 id="class-view-previous" class="text-[15px] font-semibold">
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center gap-2 rounded-md text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:min-h-8"
                    :aria-expanded="!previousCollapsed"
                    aria-controls="class-view-previous-lanes"
                    data-testid="class-view-previous-toggle"
                    @click="previousCollapsed = !previousCollapsed"
                >
                    <History
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    Últimos sumários antes de
                    {{ dayMonthLabel(classView.range.start) }}
                    <span class="font-normal text-muted-foreground"
                        >({{ classView.previous.length }})</span
                    >
                    <ChevronDown
                        :class="[
                            'size-4 transition-transform',
                            previousCollapsed ? '' : 'rotate-180',
                        ]"
                        aria-hidden="true"
                    />
                </button>
            </h3>
            <div
                v-show="!previousCollapsed"
                id="class-view-previous-lanes"
                class="@container grid gap-3"
            >
                <div class="grid gap-3 @3xl:grid-cols-2 @6xl:grid-cols-3">
                    <article
                        v-for="entry in classView.previous"
                        :key="entry.group_id ?? 'whole'"
                        class="relative rounded-[10px] border bg-muted/50 py-3.5 pr-4 pl-[22px]"
                        data-testid="class-view-previous-lane"
                    >
                        <span
                            class="absolute inset-y-0 left-0 w-1.5 rounded-l-[10px] bg-muted-foreground/40"
                            aria-hidden="true"
                        />
                        <p
                            class="mb-2 flex flex-wrap items-center gap-2 text-[13px] text-muted-foreground"
                        >
                            <LessonIdentity
                                :class-label="schoolClass.label"
                                :tone="tone"
                                :scope="laneScope(entry)"
                                size="sm"
                            />
                            <template v-if="entry.lesson">
                                <strong class="font-semibold text-foreground">{{
                                    entry.lesson.lesson_number === null
                                        ? 'Sem número'
                                        : `Lição ${entry.lesson.lesson_number}`
                                }}</strong>
                                <span>{{
                                    shortDayLabel(lessonDate(entry.lesson))
                                }}</span>
                            </template>
                        </p>
                        <template v-if="entry.lesson">
                            <div
                                v-if="density === 'compacto'"
                                class="line-clamp-2 text-[15px] leading-6"
                            >
                                {{ summaryOneLine(entry.lesson.summary ?? '') }}
                            </div>
                            <div
                                v-else
                                class="grid gap-2 text-[15px] leading-6"
                            >
                                <p
                                    v-for="(
                                        paragraph, index
                                    ) in summaryParagraphs(
                                        entry.lesson.summary ?? '',
                                    )"
                                    :key="index"
                                    class="whitespace-pre-line"
                                >
                                    {{ paragraph }}
                                </p>
                            </div>
                            <Link
                                :href="`/lessons/${entry.lesson.ulid}`"
                                class="mt-2 inline-flex min-h-11 items-center text-sm font-medium text-primary underline underline-offset-2 sm:min-h-0"
                                @click="emit('open', entry.lesson)"
                            >
                                Abrir aula
                            </Link>
                            <button
                                type="button"
                                class="mt-2 ml-4 inline-flex min-h-11 items-center gap-1.5 text-sm font-medium text-primary underline underline-offset-2 sm:min-h-0"
                                :aria-label="`Projetar sumário — ${entry.lesson.context_label}`"
                                :data-testid="`previous-project-${entry.lesson.ulid}`"
                                @click="emit('project', entry.lesson)"
                            >
                                <Presentation
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Projetar sumário
                            </button>
                        </template>
                        <p v-else class="text-sm text-muted-foreground">
                            Sem sumário anterior<template
                                v-if="
                                    classView.range.clamped ||
                                    classView.range.key === 'ano'
                                "
                            >
                                — o intervalo começa no início do ano
                                letivo</template
                            >.
                        </p>
                    </article>
                </div>
            </div>
        </section>

        <!-- As aulas do intervalo -->
        <p
            v-if="classView.lessons.length === 0"
            class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
        >
            Esta turma não tem aulas registadas no intervalo escolhido.
        </p>
        <p
            v-else-if="lessons.length === 0"
            class="rounded-[14px] border border-dashed bg-card p-6 text-center text-sm text-muted-foreground"
        >
            Os filtros escondem todas as aulas desta turma no intervalo.
        </p>

        <div v-else class="@container grid gap-2">
            <section
                v-for="weekGroup in weeks"
                :key="weekGroup.start"
                class="grid gap-2"
                :aria-labelledby="
                    multiWeek ? `semana-turma-${weekGroup.start}` : undefined
                "
            >
                <h2
                    v-if="multiWeek"
                    :id="`semana-turma-${weekGroup.start}`"
                    tabindex="-1"
                    class="mt-4 scroll-mt-24 text-[15px] font-semibold text-muted-foreground outline-none"
                >
                    Semana de {{ dayMonthLabel(weekGroup.start) }} –
                    {{ dayMonthLabel(addDays(weekGroup.start, 6)) }}
                </h2>
                <p
                    v-if="weekGroup.empty"
                    class="rounded-lg border border-dashed px-3 py-2.5 text-sm text-muted-foreground"
                >
                    Sem aulas registadas nesta semana. Se o horário previa
                    aulas, abrir a semana cria-as.
                    <Button
                        type="button"
                        variant="link"
                        class="h-auto min-h-11 px-1 sm:min-h-0"
                        @click="openWeek(weekGroup.start)"
                        >Abrir a semana</Button
                    >
                </p>
                <section
                    v-for="day in weekGroup.days"
                    :key="day.date"
                    class="grid gap-3"
                    :aria-labelledby="`dia-turma-${day.date}`"
                >
                    <h3
                        :id="`dia-turma-${day.date}`"
                        tabindex="-1"
                        class="mt-3 scroll-mt-24 border-b pb-2 text-[17px] leading-6 font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    >
                        {{ dayHeading(day.date) }}
                    </h3>
                    <ol class="grid gap-3">
                        <li v-for="lesson in day.lessons" :key="lesson.ulid">
                            <slot
                                name="card"
                                :lesson="lesson"
                                :day-lessons="day.lessons"
                            />
                        </li>
                    </ol>
                </section>
            </section>
        </div>

        <div
            v-if="nextRange"
            class="flex flex-wrap items-center gap-x-3 gap-y-1"
        >
            <Button
                type="button"
                variant="outline"
                class="min-h-11 sm:min-h-9"
                data-testid="class-view-widen"
                @click="emit('update', { range: nextRange })"
            >
                <History class="size-4" aria-hidden="true" />
                Alargar o intervalo ({{
                    nextRange === 'ano' ? 'ano letivo' : `${nextRange} semanas`
                }})
            </Button>
            <span class="text-[13px] text-muted-foreground"
                >Conta para trás a partir da semana selecionada e nunca cria
                aulas.</span
            >
        </div>
    </div>
</template>
