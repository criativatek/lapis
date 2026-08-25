<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    LayoutGrid,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';
import type { CalendarDay, CalendarPeriod } from './calendar';
import { periodTint } from './calendar';

const props = defineProps<{
    academicYear: {
        ulid: string;
        label: string;
        starts_on: string;
        ends_on: string;
    } | null;
    month: { value: string; starts_on: string; ends_on: string } | null;
    days: CalendarDay[];
    periods: CalendarPeriod[];
    navigation: {
        previous: string;
        next: string;
        home: string;
        home_is_today: boolean;
    } | null;
    assessmentsPerDay: number;
}>();

/**
 * Written out rather than derived from Intl, exactly as «Horário do Professor»
 * writes its own: lower case, as Portuguese writes them, with the first letter
 * raised on the string itself (capitalizeFirst) and never by the CSS
 * `capitalize` class, which would also raise the half after the hyphen.
 */
const weekdays = [
    'segunda-feira',
    'terça-feira',
    'quarta-feira',
    'quinta-feira',
    'sexta-feira',
    'sábado',
    'domingo',
];

const monthFormatter = new Intl.DateTimeFormat('pt-PT', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
const dayFormatter = new Intl.DateTimeFormat('pt-PT', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    timeZone: 'UTC',
});
const rangeFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'short',
    timeZone: 'UTC',
});

function asDate(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

const monthLabel = computed(() =>
    props.month
        ? capitalizeFirst(monthFormatter.format(asDate(props.month.starts_on)))
        : '',
);

/** The grid, seven days to a row, in the order the server sent them. */
const weeks = computed(() => {
    const rows: CalendarDay[][] = [];

    for (let index = 0; index < props.days.length; index += 7) {
        rows.push(props.days.slice(index, index + 7));
    }

    return rows;
});

/**
 * A período is NAMED where it begins — on the first cell of the grid, and again
 * wherever the band changes from one day to the next — and elsewhere carries
 * only its quiet tint. Repeating the name in all thirty cells would shout the
 * one thing about the month that never changes.
 */
const namesPeriod = computed(() => {
    const shows = new Set<string>();
    let previous: string | null | undefined;

    for (const [index, day] of props.days.entries()) {
        const current = day.period?.ulid ?? null;

        if (index === 0 || current !== previous) {
            if (current !== null) {
                shows.add(day.date);
            }
        }

        previous = current;
    }

    return shows;
});

/**
 * Days a cell has stopped hiding. The cap keeps a test-heavy Friday from
 * turning its column into a page of its own; opening one is the teacher's
 * choice, and it re-closes.
 */
const expanded = ref<Set<string>>(new Set());

function toggle(date: string): void {
    const next = new Set(expanded.value);

    if (!next.delete(date)) {
        next.add(date);
    }

    expanded.value = next;
}

function shown(day: CalendarDay): CalendarDay['assessments'] {
    return expanded.value.has(day.date)
        ? day.assessments
        : day.assessments.slice(0, props.assessmentsPerDay);
}

function hiddenCount(day: CalendarDay): number {
    return Math.max(0, day.assessments.length - props.assessmentsPerDay);
}

/**
 * The narrow-viewport reading. A month grid squeezed into a phone is seven
 * illegible columns, so the same month is read as an agenda instead — the way
 * the weekly view already stacks its own days — and only the days that carry
 * something are listed: an empty Tuesday is not information.
 */
const agenda = computed(() =>
    props.days.filter((day) => day.in_month && day.assessments.length > 0),
);

const assessmentsThisMonth = computed(() =>
    props.days.reduce(
        (total, day) => total + (day.in_month ? day.assessments.length : 0),
        0,
    ),
);

const description = computed(() => {
    if (!props.academicYear || !props.month) {
        return 'A estrutura do ano letivo e as avaliações, lado a lado.';
    }

    const count = assessmentsThisMonth.value;
    const avaliacoes =
        count === 1 ? '1 avaliação' : `${count} avaliações`;

    return `${monthLabel.value} · ${props.academicYear.label} · ${avaliacoes}`;
});

function dayLabel(date: string): string {
    return capitalizeFirst(dayFormatter.format(asDate(date)));
}

function periodRange(period: CalendarPeriod): string {
    return `${rangeFormatter.format(asDate(period.starts_on))} – ${rangeFormatter.format(asDate(period.ends_on))}`;
}

function goToMonth(month: string): void {
    router.get(
        '/calendar',
        { month },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Calendário do Ano Letivo" />

    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 pb-24 sm:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Calendário do Ano Letivo"
                :description="description"
            />

            <!--
                AS DUAS VISTAS SÃO DOIS ENDEREÇOS, não um estado interno desta
                página: cada uma pode ser guardada nos favoritos e o botão de
                voltar do navegador funciona entre elas.
            -->
            <nav
                class="flex shrink-0 gap-2"
                aria-label="Vista do calendário"
            >
                <Button variant="default" size="sm" class="min-h-10" disabled>
                    <LayoutGrid class="size-4" /> Mês
                </Button>
                <Button as-child variant="outline" size="sm" class="min-h-10">
                    <Link href="/calendar/ano">
                        <CalendarRange class="size-4" /> Ano
                    </Link>
                </Button>
            </nav>
        </div>

        <!--
            NADA É CRIADO AO ABRIR ESTA PÁGINA. Ao contrário da vista semanal de
            «Aulas e Sumários», que materializa as aulas da semana que mostra,
            aqui só se lê o que já existe — os períodos do ano e as avaliações
            marcadas. As aulas não aparecem aqui de propósito: essa pergunta é a
            do «Horário do Professor», e já tem a sua própria página.
        -->
        <section
            v-if="!academicYear || !month || !navigation"
            class="rounded-xl border border-dashed p-8 text-center"
            aria-labelledby="calendar-no-year-heading"
        >
            <CalendarDays class="mx-auto size-8 text-muted-foreground" />
            <h2 id="calendar-no-year-heading" class="mt-3 font-semibold">
                Ainda não há um ano letivo para mostrar
            </h2>
            <p class="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                O calendário mostra a estrutura do ano letivo selecionado. Cria
                um ano letivo e os seus períodos em «Estrutura do Ano Letivo» e
                ele aparece aqui.
            </p>
            <Button as-child variant="outline" class="mt-4">
                <Link href="/academic-years">Estrutura do Ano Letivo</Link>
            </Button>
        </section>

        <template v-else>
            <nav
                class="flex flex-wrap items-center gap-2"
                aria-label="Navegação entre meses"
            >
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="goToMonth(navigation.previous)"
                >
                    <ChevronLeft class="size-4" /> Mês anterior
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="goToMonth(navigation.home)"
                >
                    <CalendarDays class="size-4" />
                    {{
                        navigation.home_is_today
                            ? 'Mês atual'
                            : 'Início do ano'
                    }}
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="goToMonth(navigation.next)"
                >
                    Mês seguinte <ChevronRight class="size-4" />
                </Button>
            </nav>

            <!--
                OS PERÍODOS DO ANO como contexto estrutural — uma faixa, não um
                cartão. A distinção em relação a uma avaliação nunca é só de
                cor: um período não tem moldura nem ícone e escreve-se em texto
                discreto; uma avaliação tem as três coisas.
            -->
            <section
                v-if="periods.length > 0"
                class="flex flex-wrap gap-2"
                aria-label="Períodos deste mês"
            >
                <p
                    v-for="period in periods"
                    :key="period.ulid"
                    class="rounded-md px-2.5 py-1.5 text-xs"
                    :class="periodTint(periods, period.ulid)"
                >
                    <span class="font-medium">{{ period.label }}</span>
                    <span class="opacity-80">
                        · {{ period.kind_label }} · {{ periodRange(period) }}</span
                    >
                </p>
            </section>
            <p v-else class="text-sm text-muted-foreground">
                Este ano letivo ainda não tem períodos definidos. O calendário
                mostra os dias na mesma — os períodos aparecem assim que
                estiverem criados em «Estrutura do Ano Letivo».
            </p>

            <!-- A grelha do mês: legível em ecrã largo, substituída pela agenda em ecrã estreito. -->
            <section
                class="hidden overflow-hidden rounded-xl border sm:block"
                aria-label="Grelha do mês"
            >
                <div
                    class="grid grid-cols-7 border-b bg-muted/40 text-xs font-medium"
                >
                    <div
                        v-for="(weekday, index) in weekdays"
                        :key="weekday"
                        class="px-2 py-2 text-center"
                        :class="index > 4 ? 'text-muted-foreground' : ''"
                    >
                        <abbr :title="capitalizeFirst(weekday)" class="no-underline">{{
                            capitalizeFirst(weekday.slice(0, 3))
                        }}</abbr>
                    </div>
                </div>

                <div
                    v-for="(week, weekIndex) in weeks"
                    :key="weekIndex"
                    class="grid grid-cols-7 border-b last:border-b-0"
                >
                    <div
                        v-for="day in week"
                        :key="day.date"
                        class="min-h-28 border-r p-1.5 last:border-r-0"
                        :class="[
                            day.in_month ? '' : 'bg-muted/30',
                            day.period ? periodTint(periods, day.period.ulid) : '',
                        ]"
                        :aria-label="dayLabel(day.date)"
                        :data-date="day.date"
                    >
                        <div class="flex items-baseline justify-between gap-1">
                            <span
                                class="text-xs tabular-nums"
                                :class="[
                                    day.in_month
                                        ? 'font-medium'
                                        : 'text-muted-foreground',
                                    day.is_today
                                        ? 'rounded-full bg-foreground px-1.5 py-0.5 text-background'
                                        : '',
                                ]"
                                >{{ day.day }}</span
                            >
                            <!--
                                O período é NOMEADO onde começa, e não repetido
                                em todas as células do mês.
                            -->
                            <span
                                v-if="day.period && namesPeriod.has(day.date)"
                                class="truncate text-[0.65rem] leading-tight opacity-80"
                                >{{ day.period.label }}</span
                            >
                        </div>

                        <ul class="mt-1 space-y-1">
                            <li
                                v-for="assessment in shown(day)"
                                :key="assessment.ulid"
                            >
                                <!--
                                    UMA AVALIAÇÃO: moldura, ícone e peso de
                                    texto — nunca só uma cor diferente — para
                                    não se confundir com a faixa do período.
                                -->
                                <Link
                                    :href="assessment.href"
                                    class="flex items-start gap-1 rounded-md border border-foreground/25 bg-background/80 px-1.5 py-1 text-[0.7rem] leading-tight font-medium transition-colors outline-none hover:border-foreground/50 focus-visible:ring-2 focus-visible:ring-ring"
                                    :title="`${assessment.title} · ${assessment.class_label} · ${assessment.type}`"
                                >
                                    <ClipboardCheck
                                        class="mt-px size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate">{{
                                            assessment.title
                                        }}</span>
                                        <span
                                            class="block truncate font-normal opacity-75"
                                            >{{ assessment.class_label }} ·
                                            {{ assessment.type }}</span
                                        >
                                    </span>
                                </Link>
                            </li>
                        </ul>

                        <button
                            v-if="hiddenCount(day) > 0"
                            type="button"
                            class="mt-1 w-full rounded-md px-1 py-0.5 text-[0.7rem] font-medium underline-offset-2 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            @click="toggle(day.date)"
                        >
                            {{
                                expanded.has(day.date)
                                    ? 'Ver menos'
                                    : `+${hiddenCount(day)} mais`
                            }}
                        </button>
                    </div>
                </div>
            </section>

            <!-- A mesma informação em ecrã estreito, lida como agenda. -->
            <section class="space-y-3 sm:hidden" aria-label="Agenda do mês">
                <p
                    v-if="agenda.length === 0"
                    class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
                >
                    Não há avaliações marcadas em {{ monthLabel }}.
                </p>

                <section
                    v-for="day in agenda"
                    :key="day.date"
                    class="space-y-2"
                    :aria-label="dayLabel(day.date)"
                >
                    <h3 class="text-sm font-semibold">
                        {{ dayLabel(day.date) }}
                        <span
                            v-if="day.period"
                            class="font-normal text-muted-foreground"
                            >· {{ day.period.label }}</span
                        >
                    </h3>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li
                            v-for="assessment in day.assessments"
                            :key="assessment.ulid"
                        >
                            <Link
                                :href="assessment.href"
                                class="flex items-start gap-2 p-3 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <ClipboardCheck
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium">{{
                                        assessment.title
                                    }}</span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                        >{{ assessment.class_label }} ·
                                        {{ assessment.subject }} ·
                                        {{ assessment.type }}</span
                                    >
                                    <span
                                        class="block text-xs text-muted-foreground"
                                        >{{ assessment.status_label }}</span
                                    >
                                </span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </section>
        </template>
    </main>
</template>
