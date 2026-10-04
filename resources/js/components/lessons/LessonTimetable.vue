<script setup lang="ts">
/**
 * A VISTA HORÁRIO — a mesma semana das outras vistas, desenhada como grelha.
 *
 * MESMA FONTE DE DADOS, SEM EXCEÇÃO. Recebe exatamente o array `lessons` que a
 * vista Semana recebe: não há aqui uma segunda consulta nem um formato próprio.
 *
 * LINHAS PELAS HORAS REAIS (0.158.0). Cada início e cada fim de aula da semana
 * é uma fronteira (`lib/timetableRows`): a mesma hora fica na mesma linha em
 * todos os dias, um troço sem aula em nenhum dia aparece como tempo livre —
 * «Sem aulas no horário do professor» —, e aulas que se cruzam no mesmo dia
 * ficam lado a lado, em pistas. Continua a não se inventar a grelha da escola:
 * as linhas saem das aulas, nunca de uma régua fixa.
 *
 * OS FILTROS ESBATEM, NÃO TIRAM. Uma aula fora dos filtros fica no seu lugar,
 * esbatida, para o horário não perder a forma.
 *
 * NO TELEMÓVEL É UM DIA DE CADA VEZ (§27), sem scroll horizontal: as aulas por
 * ordem, as simultâneas agrupadas sob um cabeçalho horário comum (cada aula e
 * cada grupo continuam separados) e os tempos livres entre elas. A grelha só
 * aparece quando o CONTENTOR tem largura para ela.
 */
import { Link } from '@inertiajs/vue3';
import { CircleAlert, Layers, Presentation } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import LessonIdentity from '@/components/lessons/week/LessonIdentity.vue';
import LessonStatePill from '@/components/lessons/week/LessonStatePill.vue';
import { Checkbox } from '@/components/ui/checkbox';
import {
    dayHeading,
    dayMonthLabel,
    dayNumber,
    weekdayAbbr,
} from '@/lib/lessonDates';
import {
    lessonDate,
    lessonQuickCloseState,
    lessonScope,
    lessonTimeRange,
    simultaneousLessons,
    summaryOneLine,
} from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';
import {
    dayClusters,
    dayLanes,
    formatMinutes,
    FREE_TIME_LABEL,
    lessonRowSpan,
    segmentRowSize,
    timetableDays,
    timetableSegments,
} from '@/lib/timetableRows';
import { TURMA_BAND, TURMA_BAND_STRIPED } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        lessons: WeekLesson[];
        weekStart: string;
        today: string;
        now: Date;
        tones: Map<string, TurmaTone>;
        isDimmed?: (lesson: WeekLesson) => boolean;
        selectable?: boolean;
        selected?: string[];
        /** Fecho rápido (0.147.0): só as aulas abertas que já começaram entram no lote. */
        isSelectable?: (lesson: WeekLesson) => boolean;
    }>(),
    {
        isDimmed: () => false,
        selectable: false,
        selected: () => [],
        isSelectable: () => true,
    },
);

const emit = defineEmits<{
    'update:selected': [value: string[]];
    read: [lesson: WeekLesson];
    open: [lesson: WeekLesson];
    project: [lesson: WeekLesson];
}>();

defineSlots<{
    actions(props: {
        lesson: WeekLesson;
        time: string;
        variant: 'mobile' | 'desktop';
    }): unknown;
}>();

const days = computed(() => timetableDays(props.weekStart, props.lessons));
const segments = computed(() => timetableSegments(props.lessons));
const lanes = computed(() => days.value.map((day) => dayLanes(lessonsOn(day))));
const dayColumns = computed(() => {
    let next = 2;

    return lanes.value.map((lane) => {
        const column = next;
        next += lane.count;

        return column;
    });
});
const gridStyle = computed(() => ({
    gridTemplateColumns: `4.5rem ${lanes.value.map((lane) => `repeat(${lane.count}, minmax(0, 1fr))`).join(' ')}`,
    gridTemplateRows: `auto ${segments.value.map(segmentRowSize).join(' ')}`,
}));

function lessonsOn(day: string): WeekLesson[] {
    return props.lessons.filter((lesson) => lessonDate(lesson) === day);
}

function tone(lesson: WeekLesson): TurmaTone {
    return props.tones.get(lesson.school_class.ulid) ?? 'blue';
}

function band(lesson: WeekLesson): string {
    return lessonScope(lesson).kind === 'group'
        ? TURMA_BAND_STRIPED[tone(lesson)]
        : TURMA_BAND[tone(lesson)];
}

function slotStyle(lesson: WeekLesson, dayIndex: number) {
    const span = lessonRowSpan(lesson, segments.value);

    return {
        gridColumn: String(
            (dayColumns.value[dayIndex] ?? 2) +
                (lanes.value[dayIndex]?.laneOf.get(lesson.ulid) ?? 0),
        ),
        gridRow: `${span.start} / ${span.end}`,
    };
}

function simultaneousText(lesson: WeekLesson): string | null {
    const others = simultaneousLessons(lesson, props.lessons);

    return others.length === 0
        ? null
        : `Em simultâneo com ${others.map((other) => other.context_label).join(', ')}`;
}

function context(lesson: WeekLesson): string {
    return `${lesson.context_label}, ${dayHeading(lessonDate(lesson))}, ${lessonTimeRange(lesson)}`;
}

function numberLabel(lesson: WeekLesson): string {
    return lesson.lesson_number === null
        ? 'Sem número'
        : `Lição ${lesson.lesson_number}`;
}

function toggle(ulid: string, checked: boolean | 'indeterminate'): void {
    emit(
        'update:selected',
        checked === true
            ? [...new Set([...props.selected, ulid])]
            : props.selected.filter((value) => value !== ulid),
    );
}

// Telemóvel: um dia de cada vez — hoje quando está nesta semana; senão, o primeiro com aulas.
const selectedDay = ref('');

watch(
    days,
    (value) => {
        if (value.includes(selectedDay.value)) {
            return;
        }

        selectedDay.value =
            value.find((day) => day === props.today) ??
            value.find((day) => lessonsOn(day).length > 0) ??
            value[0] ??
            '';
    },
    { immediate: true },
);

const clusters = computed(() => dayClusters(lessonsOn(selectedDay.value)));

function onDayKeydown(event: KeyboardEvent, index: number): void {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
        return;
    }

    event.preventDefault();
    const count = days.value.length;
    const next =
        event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? count - 1
              : (index + (event.key === 'ArrowRight' ? 1 : -1) + count) % count;
    selectedDay.value = days.value[next] ?? selectedDay.value;
    (event.currentTarget as HTMLElement).parentElement
        ?.querySelectorAll<HTMLElement>('[role="tab"]')
        [next]?.focus();
}

// 24px de altura no mínimo (WCAG 2.2, 2.5.8): três ligações empilhadas num bloco estreito deixam de caber na exceção do espaçamento.
const linkClass =
    'inline-flex min-h-6 items-center font-medium text-primary underline-offset-2 hover:underline focus-visible:rounded focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
const mobileLinkClass =
    'inline-flex min-h-11 items-center rounded-md px-1 text-sm font-medium text-primary underline underline-offset-2 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none';
const freeStripes =
    'bg-[repeating-linear-gradient(135deg,var(--muted)_0_6px,transparent_6px_12px)]';
</script>

<template>
    <div class="@container">
        <!-- Ecrã largo: a grelha da semana pelas horas reais. -->
        <div
            class="hidden overflow-hidden rounded-[14px] border bg-card @3xl:grid"
            :style="gridStyle"
            role="region"
            :aria-label="`Horário da semana de ${dayMonthLabel(weekStart)}`"
            data-testid="timetable-grid"
        >
            <div
                class="border-b bg-muted px-2 py-2.5"
                style="grid-column: 1; grid-row: 1"
            >
                <span class="sr-only">Hora</span>
            </div>
            <div
                v-for="(day, index) in days"
                :key="`head-${day}`"
                :class="[
                    'border-b border-l bg-muted px-2 py-2.5 text-center text-sm font-semibold',
                    day === today
                        ? 'shadow-[inset_0_-3px_0_var(--color-amber-400)]'
                        : '',
                ]"
                :style="{
                    gridColumn: `${dayColumns[index]} / span ${lanes[index]?.count ?? 1}`,
                    gridRow: '1',
                }"
                data-testid="timetable-day"
            >
                <span class="capitalize">{{ weekdayAbbr(day) }}</span>
                <span
                    class="ml-1 font-normal text-muted-foreground tabular-nums"
                    >{{ dayMonthLabel(day) }}</span
                >
                <span v-if="day === today" class="sr-only"> (hoje)</span>
            </div>

            <template
                v-for="(segment, index) in segments"
                :key="`segment-${segment.from}`"
            >
                <div
                    v-if="!segment.busy"
                    :class="[
                        'flex items-center overflow-hidden border-t px-2.5 text-[11.5px] leading-[14px] font-semibold whitespace-nowrap text-muted-foreground tabular-nums',
                        freeStripes,
                    ]"
                    :style="{
                        gridColumn: '1 / -1',
                        gridRow: String(index + 2),
                    }"
                    data-testid="timetable-free"
                >
                    {{ formatMinutes(segment.from) }}–{{
                        formatMinutes(segment.to)
                    }}
                    · {{ FREE_TIME_LABEL }}
                </div>
                <template v-else>
                    <div
                        class="border-t px-2 pt-2 text-right text-[13px] font-semibold text-muted-foreground tabular-nums"
                        :style="{ gridColumn: '1', gridRow: String(index + 2) }"
                        data-testid="timetable-time"
                    >
                        {{ formatMinutes(segment.from) }}
                    </div>
                    <div
                        v-for="(day, dayIndex) in days"
                        :key="`bg-${segment.from}-${day}`"
                        :class="[
                            'border-t border-l',
                            day === today
                                ? 'bg-amber-50/40 dark:bg-amber-950/10'
                                : '',
                        ]"
                        :style="{
                            gridColumn: `${dayColumns[dayIndex]} / span ${lanes[dayIndex]?.count ?? 1}`,
                            gridRow: String(index + 2),
                        }"
                        aria-hidden="true"
                    />
                </template>
            </template>

            <template v-for="(day, dayIndex) in days" :key="`lessons-${day}`">
                <div
                    v-for="lesson in lessonsOn(day)"
                    :key="lesson.ulid"
                    class="relative z-[1] min-w-0 p-1.5"
                    :style="slotStyle(lesson, dayIndex)"
                    data-testid="timetable-slot"
                    :data-lesson="lesson.ulid"
                >
                    <div
                        :class="[
                            'relative flex h-full flex-col gap-1.5 rounded-lg border bg-card py-2 pr-2 pl-[13px]',
                            isDimmed(lesson) ? 'opacity-40' : '',
                        ]"
                        data-testid="timetable-block"
                        :data-dimmed="isDimmed(lesson) || undefined"
                    >
                        <span
                            :class="[
                                'absolute inset-y-0 left-0 w-[5px] rounded-l-lg',
                                band(lesson),
                            ]"
                            aria-hidden="true"
                        />
                        <div class="flex items-start gap-1.5">
                            <Checkbox
                                v-if="selectable && isSelectable(lesson)"
                                :model-value="selected.includes(lesson.ulid)"
                                :aria-label="`Selecionar a aula de ${context(lesson)}`"
                                @update:model-value="
                                    (value: boolean | 'indeterminate') =>
                                        toggle(lesson.ulid, value)
                                "
                            />
                            <p class="text-xs font-semibold tabular-nums">
                                <span class="sr-only"
                                    >{{ dayHeading(day) }}, </span
                                >{{ lessonTimeRange(lesson) }}
                                <span v-if="isDimmed(lesson)" class="sr-only">
                                    (fora dos filtros)</span
                                >
                            </p>
                        </div>
                        <LessonIdentity
                            :class-label="lesson.school_class.label"
                            :tone="tone(lesson)"
                            :scope="lessonScope(lesson)"
                            size="sm"
                        />
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{ numberLabel(lesson) }}
                        </p>
                        <span
                            ><LessonStatePill :lesson="lesson" size="sm"
                        /></span>
                        <p
                            v-if="
                                lessonQuickCloseState(lesson, now) === 'ended'
                            "
                            class="flex items-start gap-1 text-xs font-semibold text-amber-700 dark:text-amber-400"
                            data-testid="lesson-attention"
                        >
                            <CircleAlert
                                class="mt-px size-3.5 shrink-0"
                                aria-hidden="true"
                            />
                            Confirmar estado
                        </p>
                        <p
                            v-if="simultaneousText(lesson)"
                            class="flex items-start gap-1 text-xs font-semibold text-muted-foreground"
                            data-testid="lesson-simultaneous"
                        >
                            <Layers
                                class="mt-px size-3.5 shrink-0"
                                aria-hidden="true"
                            />{{ simultaneousText(lesson) }}
                        </p>
                        <p
                            class="line-clamp-2 text-[12.5px] leading-[17px] text-muted-foreground"
                        >
                            <template v-if="lesson.summary">{{
                                summaryOneLine(lesson.summary)
                            }}</template>
                            <em v-else>Sem sumário</em>
                        </p>
                        <div
                            class="mt-auto flex flex-wrap items-center gap-x-2.5 gap-y-1 pt-0.5 text-[12.5px]"
                        >
                            <Link
                                :href="`/lessons/${lesson.ulid}`"
                                :class="linkClass"
                                :aria-label="`Abrir aula — ${context(lesson)}`"
                                @click="emit('open', lesson)"
                                >Abrir aula</Link
                            >
                            <button
                                type="button"
                                :class="linkClass"
                                :aria-label="`Ver sumário completo — ${context(lesson)}`"
                                data-testid="timetable-read"
                                @click="emit('read', lesson)"
                            >
                                Ver sumário completo
                            </button>
                            <button
                                type="button"
                                :class="[linkClass, 'gap-1']"
                                :aria-label="`Projetar sumário — ${context(lesson)}`"
                                data-testid="timetable-project"
                                @click="emit('project', lesson)"
                            >
                                <Presentation
                                    class="size-3.5"
                                    aria-hidden="true"
                                />
                                Projetar sumário
                            </button>
                            <slot
                                name="actions"
                                :lesson="lesson"
                                :time="lessonTimeRange(lesson)"
                                variant="desktop"
                            />
                        </div>
                    </div>
                </div>
            </template>
        </div>

        <!-- Telemóvel: um dia de cada vez. -->
        <div class="@3xl:hidden">
            <div
                class="grid gap-1.5"
                :style="{
                    gridTemplateColumns: `repeat(${days.length}, minmax(0, 1fr))`,
                }"
                role="tablist"
                aria-label="Escolher dia da semana"
            >
                <button
                    v-for="(day, index) in days"
                    :key="`tab-${day}`"
                    type="button"
                    role="tab"
                    :aria-selected="day === selectedDay"
                    :tabindex="day === selectedDay ? 0 : -1"
                    :class="[
                        'flex min-h-12 flex-col items-center justify-center rounded-lg border text-[13px] leading-4 font-semibold outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                        day === selectedDay
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'bg-card',
                    ]"
                    :data-testid="`timetable-day-tab-${day}`"
                    @click="selectedDay = day"
                    @keydown="onDayKeydown($event, index)"
                >
                    <span class="capitalize">{{ weekdayAbbr(day) }}</span>
                    <span
                        :class="[
                            'font-normal tabular-nums',
                            day === selectedDay
                                ? 'opacity-85'
                                : 'text-muted-foreground',
                        ]"
                        >{{ dayNumber(day) }}</span
                    >
                    <span v-if="day === today" class="sr-only">(hoje)</span>
                </button>
            </div>

            <div
                class="mt-3 grid gap-2.5"
                role="tabpanel"
                :aria-label="selectedDay ? dayHeading(selectedDay) : ''"
                data-testid="timetable-day-list"
            >
                <p
                    v-if="clusters.length === 0"
                    class="rounded-xl border bg-card p-4 text-sm text-muted-foreground"
                >
                    Sem aulas neste dia.
                </p>
                <template
                    v-for="cluster in clusters"
                    :key="`${cluster.kind}-${cluster.from}`"
                >
                    <p
                        v-if="cluster.kind === 'free'"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-[13px] font-semibold text-muted-foreground tabular-nums',
                            freeStripes,
                        ]"
                        data-testid="timetable-free"
                    >
                        {{ formatMinutes(cluster.from) }}–{{
                            formatMinutes(cluster.to)
                        }}
                        · {{ FREE_TIME_LABEL }}
                    </p>
                    <section
                        v-else
                        :class="
                            cluster.kind === 'simultaneous'
                                ? 'grid gap-2 rounded-xl border border-dashed p-2'
                                : 'grid'
                        "
                        :data-testid="
                            cluster.kind === 'simultaneous'
                                ? 'timetable-simultaneous'
                                : undefined
                        "
                        :aria-label="
                            cluster.kind === 'simultaneous'
                                ? `${formatMinutes(cluster.from)}–${formatMinutes(cluster.to)}, ${cluster.lessons.length} aulas em simultâneo`
                                : undefined
                        "
                    >
                        <p
                            v-if="cluster.kind === 'simultaneous'"
                            class="flex items-center gap-1.5 px-1 text-[13px] font-semibold tabular-nums"
                        >
                            <Layers
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            {{ formatMinutes(cluster.from) }}–{{
                                formatMinutes(cluster.to)
                            }}
                            · {{ cluster.lessons.length }} aulas em simultâneo
                        </p>
                        <article
                            v-for="lesson in cluster.lessons"
                            :key="lesson.ulid"
                            :class="[
                                'relative flex flex-col gap-2 rounded-xl border bg-card py-3 pr-3 pl-[18px]',
                                isDimmed(lesson) ? 'opacity-40' : '',
                            ]"
                            data-testid="timetable-block"
                            :data-lesson="lesson.ulid"
                            :data-dimmed="isDimmed(lesson) || undefined"
                        >
                            <span
                                :class="[
                                    'absolute inset-y-0 left-0 w-1.5 rounded-l-xl',
                                    band(lesson),
                                ]"
                                aria-hidden="true"
                            />
                            <div class="flex items-start gap-2">
                                <Checkbox
                                    v-if="selectable && isSelectable(lesson)"
                                    class="mt-0.5"
                                    :model-value="
                                        selected.includes(lesson.ulid)
                                    "
                                    :aria-label="`Selecionar a aula de ${context(lesson)}`"
                                    @update:model-value="
                                        (value: boolean | 'indeterminate') =>
                                            toggle(lesson.ulid, value)
                                    "
                                />
                                <p class="text-sm font-semibold tabular-nums">
                                    {{ lessonTimeRange(lesson)
                                    }}<span
                                        v-if="isDimmed(lesson)"
                                        class="sr-only"
                                    >
                                        (fora dos filtros)</span
                                    >
                                </p>
                            </div>
                            <LessonIdentity
                                :class-label="lesson.school_class.label"
                                :tone="tone(lesson)"
                                :scope="lessonScope(lesson)"
                            />
                            <p
                                class="text-[13px] text-muted-foreground tabular-nums"
                            >
                                {{ lesson.subject }} · {{ numberLabel(lesson) }}
                            </p>
                            <span><LessonStatePill :lesson="lesson" /></span>
                            <p
                                v-if="
                                    lessonQuickCloseState(lesson, now) ===
                                    'ended'
                                "
                                class="flex items-start gap-1.5 text-[13px] font-semibold text-amber-700 dark:text-amber-400"
                                data-testid="lesson-attention"
                            >
                                <CircleAlert
                                    class="mt-px size-[15px] shrink-0"
                                    aria-hidden="true"
                                />
                                Aula terminada · Confirmar estado
                            </p>
                            <p
                                class="line-clamp-2 text-sm text-muted-foreground"
                            >
                                <template v-if="lesson.summary">{{
                                    summaryOneLine(lesson.summary)
                                }}</template>
                                <em v-else>Sem sumário</em>
                            </p>
                            <div class="flex flex-wrap items-center gap-2">
                                <Link
                                    :href="`/lessons/${lesson.ulid}`"
                                    :class="mobileLinkClass"
                                    :aria-label="`Abrir aula — ${context(lesson)}`"
                                    @click="emit('open', lesson)"
                                    >Abrir aula</Link
                                >
                                <button
                                    type="button"
                                    :class="mobileLinkClass"
                                    :aria-label="`Ver sumário completo — ${context(lesson)}`"
                                    data-testid="timetable-read"
                                    @click="emit('read', lesson)"
                                >
                                    Ver sumário completo
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        mobileLinkClass,
                                        'inline-flex items-center gap-1.5',
                                    ]"
                                    :aria-label="`Projetar sumário — ${context(lesson)}`"
                                    data-testid="timetable-project"
                                    @click="emit('project', lesson)"
                                >
                                    <Presentation
                                        class="size-4"
                                        aria-hidden="true"
                                    />
                                    Projetar sumário
                                </button>
                                <slot
                                    name="actions"
                                    :lesson="lesson"
                                    :time="lessonTimeRange(lesson)"
                                    variant="mobile"
                                />
                            </div>
                        </article>
                    </section>
                </template>
            </div>
        </div>
    </div>
</template>
