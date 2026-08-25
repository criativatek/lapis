<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarRange,
    ClipboardCheck,
    LayoutGrid,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';
import type { CalendarPeriod } from './calendar';
import { periodTint } from './calendar';

type YearPeriod = CalendarPeriod & { assessments_count: number };

type YearMonth = {
    value: string;
    starts_on: string;
    assessments_count: number;
    /** Acontecimentos CRUZANDO este mês, não apenas os que começam nele. */
    events_count: number;
    period_ulids: string[];
    is_current: boolean;
};

const props = defineProps<{
    academicYear: {
        ulid: string;
        label: string;
        starts_on: string;
        ends_on: string;
    } | null;
    months: YearMonth[];
    periods: YearPeriod[];
    assessmentsTotal: number;
    eventsTotal: number;
}>();

/** How many indicator marks a month draws before it simply states the number. */
const MAX_MARKS = 6;

const monthFormatter = new Intl.DateTimeFormat('pt-PT', {
    month: 'long',
    year: 'numeric',
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

function monthLabel(month: YearMonth): string {
    return capitalizeFirst(monthFormatter.format(asDate(month.starts_on)));
}

function periodRange(period: CalendarPeriod): string {
    return `${rangeFormatter.format(asDate(period.starts_on))} – ${rangeFormatter.format(asDate(period.ends_on))}`;
}

function periodsOf(month: YearMonth): YearPeriod[] {
    return props.periods.filter((period) =>
        month.period_ulids.includes(period.ulid),
    );
}

function marks(count: number): number {
    return Math.min(count, MAX_MARKS);
}

function assessmentsLabel(count: number): string {
    return count === 1 ? '1 avaliação' : `${count} avaliações`;
}

function eventsLabel(count: number): string {
    return count === 1 ? '1 acontecimento' : `${count} acontecimentos`;
}

const description = computed(() => {
    if (!props.academicYear) {
        return 'O ano letivo inteiro, de uma vez.';
    }

    return `${props.academicYear.label} · ${props.periods.length === 1 ? '1 período' : `${props.periods.length} períodos`} · ${assessmentsLabel(props.assessmentsTotal)} · ${eventsLabel(props.eventsTotal)}`;
});
</script>

<template>
    <Head title="Calendário do Ano Letivo — Ano" />

    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 pb-24 sm:p-6">
        <div
            class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"
        >
            <Heading
                title="Calendário do Ano Letivo"
                :description="description"
            />

            <nav class="flex shrink-0 gap-2" aria-label="Vista do calendário">
                <Button as-child variant="outline" size="sm" class="min-h-10">
                    <Link href="/calendar">
                        <LayoutGrid class="size-4" /> Mês
                    </Link>
                </Button>
                <Button variant="default" size="sm" class="min-h-10" disabled>
                    <CalendarRange class="size-4" /> Ano
                </Button>
            </nav>
        </div>

        <!--
            UMA VISTA SINÓPTICA, e por isso sem grelha de dias e sem lista de
            avaliações uma a uma: a lista já existe em «Elementos de Avaliação»,
            e cem linhas aqui enterrariam justamente o que só esta vista mostra
            — a forma do ano. Os acontecimentos seguem exatamente a mesma regra:
            contam-se, não se enumeram, e leem-se um a um na vista de Mês, a um
            clique de cada cartão. Criar e alterar acontecimentos vive lá, e não
            aqui: a esta escala não há dia nenhum em que os pôr. As aulas não
            aparecem, aqui como no resto do calendário: essa é a pergunta do
            «Horário do Professor».
        -->
        <section
            v-if="!academicYear"
            class="rounded-xl border border-dashed p-8 text-center"
            aria-labelledby="calendar-year-no-year-heading"
        >
            <CalendarDays class="mx-auto size-8 text-muted-foreground" />
            <h2 id="calendar-year-no-year-heading" class="mt-3 font-semibold">
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
            <!--
                OS PERÍODOS COMO FAIXAS — contexto estrutural, não cartões de
                evento: sem moldura, sem ícone, texto discreto. Uma avaliação,
                em qualquer sítio desta página, tem moldura, ícone e peso.
            -->
            <section
                v-if="periods.length > 0"
                class="space-y-2"
                aria-label="Períodos do ano letivo"
            >
                <div
                    v-for="period in periods"
                    :key="period.ulid"
                    class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 rounded-lg px-3 py-2.5"
                    :class="periodTint(periods, period.ulid)"
                >
                    <span class="text-sm">
                        <span class="font-medium">{{ period.label }}</span>
                        <span class="opacity-80">
                            · {{ period.kind_label }} ·
                            {{ periodRange(period) }}</span
                        >
                    </span>
                    <span
                        class="flex items-center gap-1.5 rounded-md border border-foreground/25 bg-background/80 px-2 py-0.5 text-xs font-medium"
                    >
                        <ClipboardCheck class="size-3" aria-hidden="true" />
                        {{ assessmentsLabel(period.assessments_count) }}
                    </span>
                </div>
            </section>
            <p v-else class="text-sm text-muted-foreground">
                Este ano letivo ainda não tem períodos definidos. Os meses
                aparecem na mesma — as faixas dos períodos aparecem assim que
                estiverem criados em «Estrutura do Ano Letivo».
            </p>

            <section
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                aria-label="Meses do ano letivo"
            >
                <Link
                    v-for="month in months"
                    :key="month.value"
                    :href="`/calendar?month=${month.value}`"
                    class="flex flex-col gap-2 rounded-xl border p-3 transition-colors outline-none hover:border-foreground/40 focus-visible:ring-2 focus-visible:ring-ring"
                    :class="
                        month.period_ulids.length > 0
                            ? periodTint(periods, month.period_ulids[0] as string)
                            : ''
                    "
                    :aria-label="monthLabel(month)"
                    :data-month="month.value"
                >
                    <span class="flex items-baseline justify-between gap-2">
                        <span class="text-sm font-semibold">{{
                            monthLabel(month)
                        }}</span>
                        <span
                            v-if="month.is_current"
                            class="rounded-full bg-foreground px-1.5 py-0.5 text-[0.65rem] text-background"
                            >mês atual</span
                        >
                    </span>

                    <span
                        v-if="periodsOf(month).length > 0"
                        class="text-xs opacity-80"
                        >{{
                            periodsOf(month)
                                .map((period) => period.label)
                                .join(' · ')
                        }}</span
                    >

                    <!--
                        Indicadores compactos, nunca a lista: um traço por
                        avaliação até seis, e o número escrito sempre. Os
                        acontecimentos entram aqui pela MESMA regra da Fase 5.2
                        — uma contagem, e nunca os nomes: a esta escala o que se
                        procura é a forma do ano, e ler os acontecimentos um a
                        um é o que a vista de Mês faz, a um clique deste mesmo
                        cartão.
                    -->
                    <span class="mt-auto flex flex-wrap items-center gap-1.5">
                        <template v-if="month.assessments_count > 0">
                            <span
                                class="flex items-center gap-1.5 rounded-md border border-foreground/25 bg-background/80 px-2 py-0.5 text-xs font-medium"
                            >
                                <ClipboardCheck
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{ assessmentsLabel(month.assessments_count) }}
                            </span>
                            <span class="flex gap-0.5" aria-hidden="true">
                                <span
                                    v-for="mark in marks(
                                        month.assessments_count,
                                    )"
                                    :key="mark"
                                    class="h-3 w-1 rounded-full bg-foreground/50"
                                />
                            </span>
                        </template>
                        <span v-else class="text-xs text-muted-foreground"
                            >Sem avaliações</span
                        >

                        <!--
                            Distinto de uma avaliação por ícone E por palavra, e
                            não só por posição ou cor — a mesma disciplina que a
                            vista de Mês aplica a cada acontecimento.
                        -->
                        <span
                            v-if="month.events_count > 0"
                            :data-events-count="month.events_count"
                            class="flex items-center gap-1.5 rounded-md border border-dashed border-foreground/25 px-2 py-0.5 text-xs"
                        >
                            <CalendarDays class="size-3" aria-hidden="true" />
                            {{ eventsLabel(month.events_count) }}
                        </span>
                    </span>
                </Link>
            </section>
        </template>
    </main>
</template>
