<script setup lang="ts">
/**
 * O CARTÃO LARGO DE UMA AULA — Semana e Por turma (0.158.0).
 *
 * Três zonas, e cada uma com uma só pergunta:
 *  - à esquerda, QUEM e QUANDO: a hora, a turma (etiqueta com o tom guardado),
 *    o âmbito por extenso e o número da lição;
 *  - ao centro, O QUE SE DEU: o sumário inteiro, com os parágrafos e as
 *    quebras de linha que o professor escreveu, numa coluna de leitura
 *    confortável (72 caracteres) — nunca uma caixa com scroll;
 *  - à direita, EM QUE PONTO ESTÁ: o estado (pílula redonda), a assiduidade e
 *    o fecho rápido.
 *
 * As zonas reorganizam-se pela largura do CONTENTOR (consultas @container), e
 * não do ecrã: com a barra lateral aberta ou fechada, o cartão decide pela
 * largura que realmente tem. No telemóvel ficam empilhadas, em largura total.
 */
import { Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    CircleAlert,
    ClipboardList,
    FileText,
    History,
    Layers,
    Link2,
    Pencil,
    Plus,
} from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import type { SpecialLessonOutcome } from '@/components/lessons/LessonOutcomeDialog.vue';
import LessonQuickActions from '@/components/lessons/LessonQuickActions.vue';
import LessonIdentity from '@/components/lessons/week/LessonIdentity.vue';
import LessonStatePill from '@/components/lessons/week/LessonStatePill.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { shortDayLabel } from '@/lib/lessonDates';
import {
    emptySummaryReason,
    followsPreviousWithoutBreak,
    isQuickClosable,
    lessonDate,
    lessonQuickCloseState,
    lessonScope,
    lessonTimeRange,
    simultaneousLessons,
    summaryOneLine,
    summaryParagraphs,
} from '@/lib/lessons';
import type { WeekLesson } from '@/lib/lessons';
import type { Density } from '@/lib/lessonWeekView';
import { TURMA_BAND, TURMA_BAND_STRIPED } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = withDefaults(
    defineProps<{
        lesson: WeekLesson;
        tone: TurmaTone;
        mode?: 'semana' | 'turma';
        /** As aulas do mesmo dia nesta vista, por ordem — para «tempo seguido» e «em simultâneo». */
        dayLessons?: readonly WeekLesson[];
        density?: Density;
        expanded?: boolean;
        now: Date;
        selectionMode?: boolean;
        selected?: boolean;
        editing?: boolean;
        savedNote?: { time: string; transition: string | null } | null;
        highlighted?: boolean;
    }>(),
    {
        mode: 'semana',
        dayLessons: () => [],
        density: 'completo',
        expanded: false,
        selectionMode: false,
        selected: false,
        editing: false,
        savedNote: null,
        highlighted: false,
    },
);

const emit = defineEmits<{
    edit: [];
    open: [];
    'toggle-expanded': [];
    'update:selected': [value: boolean];
    outcome: [outcome: SpecialLessonOutcome];
}>();

defineSlots<{ editor(): unknown }>();

const scope = computed(() => lessonScope(props.lesson));
const time = computed(() => lessonTimeRange(props.lesson));
const quickState = computed(() =>
    lessonQuickCloseState(props.lesson, props.now),
);
const canQuickClose = computed(() => isQuickClosable(props.lesson, props.now));
const consecutive = computed(() =>
    followsPreviousWithoutBreak(props.lesson, props.dayLessons),
);
const simultaneous = computed(() =>
    simultaneousLessons(props.lesson, props.dayLessons),
);
const attendanceApplies = computed(
    () => props.lesson.outcome === null || props.lesson.outcome === 'taught',
);
const numberLabel = computed(() =>
    props.lesson.lesson_number === null
        ? 'Sem número'
        : `Lição ${props.lesson.lesson_number}`,
);
const context = computed(
    () =>
        `${props.lesson.context_label}, ${shortDayLabel(lessonDate(props.lesson))}, ${time.value}`,
);
const band = computed(() =>
    scope.value.kind === 'group'
        ? TURMA_BAND_STRIPED[props.tone]
        : TURMA_BAND[props.tone],
);
const compact = computed(() => props.density === 'compacto' && !props.expanded);
const paragraphs = computed(() =>
    summaryParagraphs(props.lesson.summary ?? ''),
);
const emptyReason = computed(() => emptySummaryReason(props.lesson, props.now));

const attendanceLabel = computed(() => {
    if (
        !attendanceApplies.value ||
        !props.lesson.attendance_recorded ||
        props.lesson.absent_count === null
    ) {
        return null;
    }

    return props.lesson.absent_count === 0
        ? 'Sem faltas'
        : `${props.lesson.absent_count} falta${props.lesson.absent_count === 1 ? '' : 's'}`;
});

// «Mostrar tudo» só aparece quando o texto foi MESMO recortado no modo compacto.
const clampElement = ref<HTMLElement | null>(null);
const overflowing = ref(false);

function measure(): void {
    overflowing.value =
        clampElement.value !== null &&
        clampElement.value.scrollHeight > clampElement.value.clientHeight + 1;
}

onMounted(() => nextTick(measure));
watch([compact, () => props.lesson.summary], () => nextTick(measure));

function scheduleLabel(event: WeekLesson['day_events'][number]): string {
    if (event.all_day) {
        return 'Todo o dia';
    }

    if (event.starts_at === null) {
        return '';
    }

    return event.ends_at
        ? `${event.starts_at}–${event.ends_at}`
        : event.starts_at;
}
</script>

<template>
    <article
        :id="`aula-${lesson.ulid}`"
        :aria-labelledby="`aula-${lesson.ulid}-titulo`"
        data-testid="lesson-card"
        :data-lesson="lesson.ulid"
        :class="[
            'relative grid scroll-mt-24 grid-cols-1 gap-x-6 gap-y-3 rounded-[10px] border bg-card py-4 pr-4 pl-[22px] shadow-xs transition-shadow',
            '@2xl:grid-cols-[minmax(0,1fr)_minmax(11rem,auto)]',
            '@4xl:grid-cols-[12.5rem_minmax(0,1fr)_13.5rem] @4xl:py-5 @4xl:pr-5 @4xl:pl-7',
            editing ? 'border-primary ring-[3px] ring-primary/20' : '',
            highlighted ? 'ring-[3px] ring-amber-400/70' : '',
        ]"
    >
        <span
            :class="['absolute inset-y-0 left-0 w-1.5 rounded-l-[10px]', band]"
            aria-hidden="true"
        />

        <!-- QUEM e QUANDO -->
        <div
            class="flex min-w-0 flex-col gap-1.5 @2xl:col-start-1 @2xl:row-start-1"
        >
            <div class="flex items-center gap-2">
                <Checkbox
                    v-if="selectionMode && canQuickClose"
                    :model-value="selected"
                    :aria-label="`Selecionar a aula de ${context}`"
                    @update:model-value="
                        (value: boolean | 'indeterminate') =>
                            emit('update:selected', value === true)
                    "
                />
                <template v-if="mode === 'turma'">
                    <p
                        :class="[
                            'font-semibold tracking-tight',
                            lesson.lesson_number === null
                                ? 'text-[15px] text-muted-foreground'
                                : 'text-[22px] leading-7',
                        ]"
                    >
                        {{ numberLabel }}
                    </p>
                </template>
                <p v-else class="text-[15px] font-semibold tabular-nums">
                    <time :datetime="lesson.starts_at">{{ time }}</time>
                </p>
            </div>
            <p
                v-if="mode === 'turma'"
                class="text-sm font-semibold text-muted-foreground tabular-nums"
            >
                {{ shortDayLabel(lessonDate(lesson)) }} ·
                <time :datetime="lesson.starts_at">{{ time }}</time>
            </p>
            <h3
                :id="`aula-${lesson.ulid}-titulo`"
                class="text-base font-normal"
            >
                <LessonIdentity
                    :class-label="lesson.school_class.label"
                    :tone="tone"
                    :scope="scope"
                />
                <span class="sr-only"
                    >, {{ shortDayLabel(lessonDate(lesson)) }}, {{ time }}</span
                >
            </h3>
            <p
                class="flex flex-wrap gap-x-1.5 text-[13px] leading-[18px] text-muted-foreground"
            >
                <span>{{ lesson.subject }}</span>
                <template v-if="mode === 'semana'">
                    <span aria-hidden="true">·</span>
                    <span
                        class="font-semibold text-foreground tabular-nums"
                        data-testid="lesson-number"
                        >{{ numberLabel }}</span
                    >
                </template>
            </p>
            <p
                v-if="consecutive"
                class="flex items-start gap-1.5 text-xs leading-4 font-semibold text-muted-foreground"
            >
                <Link2 class="size-3.5 shrink-0" aria-hidden="true" />Tempo
                seguido — mesma turma, sem intervalo
            </p>
            <p
                v-if="simultaneous.length > 0"
                class="flex items-start gap-1.5 text-xs leading-4 font-semibold text-muted-foreground"
                data-testid="lesson-simultaneous"
            >
                <Layers class="size-3.5 shrink-0" aria-hidden="true" />Em
                simultâneo com
                {{
                    simultaneous.map((other) => other.context_label).join(', ')
                }}
            </p>
        </div>

        <!-- EM QUE PONTO ESTÁ -->
        <div
            class="flex min-w-0 flex-col items-start gap-2 @2xl:col-start-2 @2xl:row-start-1 @2xl:items-end @2xl:text-right @4xl:col-start-3 @4xl:items-start @4xl:self-stretch @4xl:border-l @4xl:pl-5 @4xl:text-left"
        >
            <span class="sr-only">Estado da aula:</span>
            <LessonStatePill :lesson="lesson" />
            <p
                v-if="quickState === 'ended'"
                class="flex items-start gap-1.5 text-[13px] leading-[18px] font-semibold text-amber-700 dark:text-amber-400"
                data-testid="lesson-attention"
            >
                <CircleAlert
                    class="mt-px size-[15px] shrink-0"
                    aria-hidden="true"
                />
                Aula terminada · Confirmar estado
            </p>
            <p
                v-if="attendanceLabel"
                class="flex items-center gap-1.5 text-[13px] text-muted-foreground"
            >
                <ClipboardList class="size-3.5" aria-hidden="true" />{{
                    attendanceLabel
                }}
            </p>
            <p
                v-if="lesson.summary_reviewed"
                class="flex items-start gap-1.5 text-[13px] text-muted-foreground"
            >
                <History
                    class="mt-0.5 size-3.5 shrink-0"
                    aria-hidden="true"
                />Sumário revisto após lecionada
            </p>
            <p
                v-if="lesson.outcome === 'teacher_absent'"
                class="text-[13px] text-muted-foreground"
            >
                Não conta na numeração
            </p>
            <LessonQuickActions
                v-if="canQuickClose"
                class="w-full @2xl:w-auto"
                :lesson-ulid="lesson.ulid"
                :context-label="lesson.context_label"
                :time="time"
                @outcome="(outcome) => emit('outcome', outcome)"
            />
        </div>

        <!-- O QUE SE DEU -->
        <div
            class="min-w-0 @2xl:col-span-2 @2xl:row-start-2 @4xl:col-span-1 @4xl:col-start-2 @4xl:row-start-1"
        >
            <slot v-if="editing" name="editor" />
            <template v-else>
                <template v-if="lesson.summary">
                    <div
                        v-if="compact"
                        ref="clampElement"
                        class="line-clamp-2 max-w-[72ch] text-base leading-relaxed"
                        data-testid="lesson-summary"
                        data-density="compacto"
                    >
                        {{ summaryOneLine(lesson.summary) }}
                    </div>
                    <div
                        v-else
                        class="grid max-w-[72ch] gap-2.5 text-base leading-relaxed"
                        data-testid="lesson-summary"
                        data-density="completo"
                    >
                        <p
                            v-for="(paragraph, index) in paragraphs"
                            :key="index"
                            class="break-words whitespace-pre-line"
                        >
                            {{ paragraph }}
                        </p>
                    </div>
                    <Button
                        v-if="
                            (compact && overflowing) ||
                            (density === 'compacto' && expanded)
                        "
                        type="button"
                        variant="link"
                        class="-ml-1 h-auto min-h-11 px-1 sm:min-h-0 sm:py-1"
                        :aria-expanded="!compact"
                        :aria-label="`${compact ? 'Mostrar o sumário completo' : 'Mostrar menos'} — ${context}`"
                        @click="emit('toggle-expanded')"
                    >
                        {{ compact ? 'Mostrar tudo' : 'Mostrar menos' }}
                    </Button>
                </template>
                <p
                    v-else
                    class="flex max-w-[72ch] items-start gap-2 text-sm text-muted-foreground"
                    data-testid="lesson-summary-empty"
                >
                    <FileText
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span
                        ><strong class="font-semibold text-foreground"
                            >Sem sumário.</strong
                        >{{ emptyReason ? ` ${emptyReason}` : '' }}</span
                    >
                </p>

                <ul
                    v-if="lesson.day_events.length > 0"
                    class="mt-3.5 grid max-w-[72ch] gap-2"
                    aria-label="Acontecimentos do dia"
                >
                    <li
                        v-for="event in lesson.day_events"
                        :key="event.ulid"
                        class="flex flex-wrap items-center gap-x-2.5 gap-y-1 rounded-lg border border-dashed px-2.5 py-2 text-sm"
                        data-testid="lesson-day-event"
                    >
                        <span
                            v-if="event.type_label"
                            class="text-xs font-semibold text-muted-foreground"
                            >{{ event.type_label }}</span
                        >
                        <span>{{ event.title }}</span>
                        <span class="text-[13px] text-muted-foreground"
                            >{{ scheduleLabel(event)
                            }}<template v-if="event.notes">
                                · {{ event.notes }}</template
                            ></span
                        >
                    </li>
                </ul>

                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        class="min-h-11 flex-[1_1_calc(50%-0.5rem)] sm:min-h-9 sm:flex-none"
                        :data-testid="`edit-${lesson.ulid}`"
                        :aria-label="`${lesson.has_summary ? 'Editar' : 'Escrever'} sumário — ${context}`"
                        @click="emit('edit')"
                    >
                        <Pencil
                            v-if="lesson.has_summary"
                            class="size-4"
                            aria-hidden="true"
                        />
                        <Plus v-else class="size-4" aria-hidden="true" />
                        {{
                            lesson.has_summary
                                ? 'Editar sumário'
                                : 'Escrever sumário'
                        }}
                    </Button>
                    <Button
                        v-if="attendanceApplies"
                        as-child
                        variant="ghost"
                        class="min-h-11 flex-[1_1_calc(50%-0.5rem)] sm:min-h-9 sm:flex-none"
                    >
                        <Link
                            :href="`/lessons/${lesson.ulid}#assiduidade`"
                            :aria-label="`Assiduidade — ${context}`"
                            @click="emit('open')"
                        >
                            <ClipboardList class="size-4" aria-hidden="true" />
                            Assiduidade
                        </Link>
                    </Button>
                    <Button
                        as-child
                        variant="ghost"
                        class="min-h-11 flex-[1_1_100%] sm:min-h-9 sm:flex-none"
                    >
                        <Link
                            :href="`/lessons/${lesson.ulid}`"
                            :aria-label="`Abrir aula — ${context}`"
                            data-testid="open-lesson"
                            @click="emit('open')"
                        >
                            Abrir aula
                            <ArrowRight class="size-4" aria-hidden="true" />
                        </Link>
                    </Button>
                </div>
            </template>

            <p
                v-if="savedNote && !editing"
                role="status"
                class="mt-2.5 flex flex-wrap items-baseline gap-x-2 text-sm text-emerald-700 dark:text-emerald-400"
            >
                <span class="font-serif text-base italic"
                    >Guardado às {{ savedNote.time }}</span
                >
                <span v-if="savedNote.transition"
                    >· {{ savedNote.transition }}</span
                >
            </p>
        </div>
    </article>
</template>
