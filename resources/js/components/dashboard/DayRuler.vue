<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, Check } from '@lucide/vue';
import { computed } from 'vue';
import SectionHeading from '@/components/dashboard/SectionHeading.vue';
import type { DayAgenda, DayItem } from '@/lib/dashboard';
import { minutesOf } from '@/lib/dashboard';
import { TURMA_BADGE, TURMA_BADGE_BORDER, TURMA_BAND, isTurmaTone } from '@/lib/turmaTones';
import type { TurmaTone } from '@/lib/turmaTones';

const props = defineProps<{
    day: DayAgenda;
    now: string;
    lessonsHref: string;
}>();

/** A régua vai do início ao fim do horário DESTA semana, arredondados à hora. */
const bounds = computed(() => {
    const start = minutesOf(props.day.bounds?.start ?? '08:00');
    const end = Math.max(start + 60, minutesOf(props.day.bounds?.end ?? '17:00'));

    return { start, end, span: end - start };
});

const hours = computed(() => {
    const ticks: string[] = [];

    for (let minute = bounds.value.start; minute < bounds.value.end; minute += 60) {
        ticks.push(`${String(Math.floor(minute / 60)).padStart(2, '0')}:00`);
    }

    return ticks;
});

const nowMinutes = computed(() => {
    const time = new Intl.DateTimeFormat('pt-PT', { hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Europe/Lisbon' }).format(new Date(props.now));

    return minutesOf(time);
});

const nowLeft = computed<number | null>(() => {
    const { start, span } = bounds.value;
    const position = ((nowMinutes.value - start) / span) * 100;

    return position < 0 || position > 100 ? null : position;
});

const nowLabel = computed(() => `agora · ${String(Math.floor(nowMinutes.value / 60)).padStart(2, '0')}:${String(nowMinutes.value % 60).padStart(2, '0')}`);

function toneOf(item: DayItem): TurmaTone {
    return isTurmaTone(item.class?.tone) ? item.class.tone : 'stone';
}

function geometry(item: DayItem): { left: string; width: string; short: boolean } {
    const { start, span } = bounds.value;
    const from = Math.max(start, minutesOf(item.start_time));
    const to = Math.min(start + span, item.end_time ? minutesOf(item.end_time) : from + 60);
    const minutes = Math.max(20, to - from);

    return { left: `${((from - start) / span) * 100}%`, width: `${(minutes / span) * 100}%`, short: minutes <= 55 };
}

/** O que a linha diz em poucas palavras, pelo estado. */
function detail(item: DayItem): string {
    if (item.kind === 'activity') {
        return item.session?.presentation_label ?? item.subtitle ?? '';
    }

    const lesson = item.lesson;

    if (!lesson) {
        return item.state === 'unopened' ? 'por abrir' : (item.subtitle ?? '');
    }

    const parts: string[] = [];

    if (lesson.number) {
        parts.push(`n.º ${lesson.number}`);
    }

    if (lesson.outcome && lesson.outcome !== 'taught' && lesson.outcome_label) {
        parts.push(lesson.outcome_label.toLowerCase());
    } else if (lesson.has_summary) {
        parts.push(lesson.summary_registered_at ? `sumário às ${lesson.summary_registered_at.slice(11, 16)}` : 'sumário registado');
    } else if (item.state === 'pending') {
        parts.push('por registar');
    }

    if (lesson.attendance_recorded && lesson.absent_count !== null) {
        parts.push(lesson.absent_count === 0 ? 'sem faltas' : `${lesson.absent_count} ${lesson.absent_count === 1 ? 'falta' : 'faltas'}`);
    }

    return parts.join(' · ');
}

const STATE_LABEL: Record<DayItem['state'], string> = { done: 'registada', pending: 'por registar', current: 'a decorrer', upcoming: 'a seguir', unopened: 'por abrir' };

const legend = computed(() => {
    const seen = new Map<string, { label: string; tone: TurmaTone }>();

    for (const item of props.day.items) {
        if (item.class && !seen.has(item.class.ulid)) {
            seen.set(item.class.ulid, { label: item.class.label, tone: toneOf(item) });
        }
    }

    return [...seen.values()];
});

const note = computed(() => {
    const t = props.day.totals;
    const parts = [`${t.summaries_registered} de ${t.lessons} ${t.lessons === 1 ? 'sumário registado' : 'sumários registados'}`];

    if (t.sessions > 0) {
        parts.push(`${t.sessions_done} de ${t.sessions} ${t.sessions === 1 ? 'sessão realizada' : 'sessões realizadas'}`);
    }

    if (t.absences_recorded > 0) {
        parts.push(`${t.absences_recorded} ${t.absences_recorded === 1 ? 'falta marcada' : 'faltas marcadas'}`);
    }

    return parts.join(' · ');
});
</script>

<template>
    <section class="rounded-2xl border border-border bg-card p-4 sm:px-5" aria-labelledby="dash-day" data-testid="day-ruler">
        <SectionHeading id="dash-day" title="Hoje" accent :note="day.items.length > 0 ? note : null" />

        <!-- Sem nada hoje: diz-se, e diz-se o que há a seguir. -->
        <p v-if="day.items.length === 0" class="mt-3 font-serif text-base text-muted-foreground italic">Sem aulas nem atividades marcadas para hoje.</p>

        <template v-else>
            <!-- A RÉGUA (de md para cima): proporcional ao tempo, com a hora atual. -->
            <div class="mt-3 hidden md:block">
                <div class="grid text-xs font-semibold text-muted-foreground tabular-nums" :style="{ gridTemplateColumns: `repeat(${hours.length}, minmax(0, 1fr))` }" aria-hidden="true">
                    <span v-for="hour in hours" :key="hour" class="h-4 border-l border-border pl-1.5">{{ hour }}</span>
                </div>
                <ol class="relative mt-0.5 h-[60px]" :style="{ backgroundImage: 'repeating-linear-gradient(90deg, var(--border) 0 1px, transparent 1px calc(100% / ' + hours.length + '))' }" aria-label="Aulas e atividades de hoje">
                    <li v-for="item in day.items" :key="item.key" class="absolute top-2 h-12" :style="{ left: geometry(item).left, width: geometry(item).width }">
                        <Link
                            :href="item.href"
                            :title="`${item.start_time}–${item.end_time ?? ''} ${item.title} · ${STATE_LABEL[item.state]}`"
                            class="flex h-full w-full flex-col justify-center overflow-hidden rounded-lg border px-2.5 text-xs leading-4 transition-shadow focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="[
                                item.state === 'done'
                                    ? 'border-transparent bg-amber-50 pr-6 text-amber-950 dark:bg-amber-950/40 dark:text-amber-100'
                                    : item.kind === 'activity'
                                      ? 'border-dashed border-(--pencil) bg-transparent text-foreground'
                                      : `${TURMA_BADGE[toneOf(item)]} ${TURMA_BADGE_BORDER[toneOf(item)]}`,
                                item.state === 'current' || item.state === 'pending' ? 'card-soft ring-1 ring-primary/40' : '',
                                geometry(item).short ? 'px-2' : '',
                            ]"
                        >
                            <span class="truncate font-semibold">{{ item.title }}</span>
                            <span class="truncate text-[11px] opacity-90">{{ detail(item) }}</span>
                            <Check v-if="item.state === 'done'" class="absolute top-1.5 right-1.5 size-3.5 text-amber-700 dark:text-amber-300" aria-hidden="true" />
                            <span class="sr-only">{{ STATE_LABEL[item.state] }}</span>
                        </Link>
                    </li>
                    <span v-if="nowLeft !== null" class="absolute -inset-y-1 w-0.5 rounded bg-primary" :style="{ left: `${nowLeft}%` }" aria-hidden="true">
                        <span class="absolute -bottom-4 -left-5 text-[10px] font-bold whitespace-nowrap text-primary tabular-nums">{{ nowLabel }}</span>
                    </span>
                </ol>
            </div>

            <!-- A LISTA (abaixo de md): as mesmas linhas, na vertical, com a cor na margem. -->
            <ol class="mt-3 flex flex-col gap-2 md:hidden" aria-label="Aulas e atividades de hoje">
                <li v-for="item in day.items" :key="item.key">
                    <Link
                        :href="item.href"
                        class="grid min-h-14 grid-cols-[48px_1fr_auto] items-center gap-2.5 rounded-lg border border-border border-l-4 bg-card px-3 py-2.5 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="item.state === 'done' ? 'border-l-(--brand-amber) bg-amber-50/60 dark:bg-amber-950/30' : item.kind === 'activity' ? 'border-l-(--pencil)' : TURMA_BAND[toneOf(item)].replace(/bg-/g, 'border-l-')"
                    >
                        <span class="text-xs leading-[15px] font-semibold text-muted-foreground tabular-nums">{{ item.start_time }}<br />{{ item.end_time }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-sm font-semibold">{{ item.title }}</span>
                            <span class="block truncate text-xs text-muted-foreground">{{ detail(item) }}</span>
                        </span>
                        <span v-if="item.state === 'done'" class="rounded-full bg-amber-100 p-1 text-amber-800 dark:bg-amber-900/50 dark:text-amber-200" aria-hidden="true"><Check class="size-3.5" /></span>
                        <span v-else-if="item.state === 'current'" class="rounded-full bg-primary px-2 py-0.5 text-[11px] font-semibold text-primary-foreground">a decorrer</span>
                        <span v-else-if="item.state === 'pending'" class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-900 dark:bg-amber-900/50 dark:text-amber-200">por registar</span>
                        <span class="sr-only">{{ STATE_LABEL[item.state] }}</span>
                    </Link>
                </li>
            </ol>

            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-muted-foreground">
                <span v-for="entry in legend" :key="entry.label" class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-[3px]" :class="TURMA_BAND[entry.tone]" aria-hidden="true"></span>{{ entry.label }}</span>
                <span class="inline-flex items-center gap-1.5"><span class="size-2.5 rounded-[3px] bg-(--brand-amber)" aria-hidden="true"></span>registado</span>
                <span v-if="day.bounds" class="font-serif italic">{{ day.bounds.start }}–{{ day.bounds.end }} · limites do teu horário desta semana</span>
                <Link :href="lessonsHref" class="ml-auto inline-flex min-h-11 items-center gap-1 font-semibold text-primary hover:underline sm:min-h-0">Abrir as aulas de hoje <ArrowRight class="size-3.5" aria-hidden="true" /></Link>
            </div>
        </template>
    </section>
</template>
