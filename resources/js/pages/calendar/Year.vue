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
import {
    asDate,
    fullyContainedPeriod,
    periodContext,
    periodRange,
    PERIOD_TINT,
} from './calendar';

type YearPeriod = CalendarPeriod & { assessments_count: number };

type YearMonth = {
    value: string;
    starts_on: string;
    /** O último dia do mês, para se poder saber se um período o cobre INTEIRO. */
    ends_on: string;
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
    /**
     * «2 semestres», «3 períodos» — contado E NOMEADO no servidor, onde a
     * espécie de cada período está escrita. Esta página não volta a derivar o
     * plural a partir do número: dizer «períodos» a um ano de semestres seria
     * inventar uma estrutura que o ano não tem.
     */
    periodsCountLabel: string;
    assessmentsTotal: number;
    eventsTotal: number;
}>();

const monthFormatter = new Intl.DateTimeFormat('pt-PT', {
    month: 'long',
    year: 'numeric',
    timeZone: 'UTC',
});
function monthLabel(month: YearMonth): string {
    return capitalizeFirst(monthFormatter.format(asDate(month.starts_on)));
}

function periodsOf(month: YearMonth): YearPeriod[] {
    return props.periods.filter((period) =>
        month.period_ulids.includes(period.ulid),
    );
}

/**
 * O período que cobre este mês DE UMA PONTA À OUTRA — e o que um mês de
 * transição diz em vez do nome seco do período.
 *
 * As DUAS perguntas são respondidas em `calendar.ts`, e não aqui: a vista de Mês
 * faz-lhes exatamente as mesmas perguntas sobre o mês que está a mostrar, e
 * tê-las escritas duas vezes era como as duas vistas acabariam a descrever o
 * mesmo mês de maneiras diferentes. O que fica aqui é só o que é DESTA vista —
 * saber que os períodos que tocam um mês são os `period_ulids` que o servidor
 * lhe mandou.
 */
function containedPeriod(month: YearMonth): YearPeriod | null {
    return fullyContainedPeriod(month, periodsOf(month));
}

/**
 * O tom de um mês, e a única circunstância em que ele o tem: quando um período
 * o cobre de uma ponta à outra. Um mês de transição fica sem tom nenhum — que é
 * exatamente o que já acontecia a um mês sem períodos — porque não há aqui meio
 * tom que diga a verdade sobre metade dele.
 */
function monthTint(month: YearMonth): string {
    return containedPeriod(month) === null ? '' : PERIOD_TINT;
}

function monthPeriodContext(month: YearMonth): string {
    return periodContext(month, periodsOf(month));
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

    return `${props.academicYear.label} · ${props.periodsCountLabel} · ${assessmentsLabel(props.assessmentsTotal)} · ${eventsLabel(props.eventsTotal)}`;
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
                    :class="PERIOD_TINT"
                >
                    <!--
                        O nome do período já diz a espécie — «1.º Semestre», «2.º
                        Período» — e repeti-la a seguir não acrescenta nada.
                    -->
                    <span class="text-sm">
                        <span class="font-medium">{{ period.label }}</span>
                        <span class="opacity-80">
                            · {{ periodRange(period) }}</span
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
                    :class="monthTint(month)"
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

                    <!--
                        UM MÊS INTEIRAMENTE DENTRO DE UM PERÍODO diz só o nome
                        dele: é o caso simples, e não precisa de mais nada. Um
                        mês de transição — dois períodos, ou um que começa ou
                        acaba a meio — diz onde é que ele realmente começa ou
                        acaba, porque o tom sozinho diria que o mês é todo dele.
                    -->
                    <span
                        v-if="periodsOf(month).length > 0"
                        class="text-xs opacity-80"
                        >{{
                            containedPeriod(month)
                                ? periodsOf(month)
                                      .map((period) => period.label)
                                      .join(' · ')
                                : monthPeriodContext(month)
                        }}</span
                    >

                    <!--
                        Uma contagem escrita, nunca a lista: os acontecimentos
                        entram aqui pela MESMA regra da Fase 5.2 — uma contagem,
                        e nunca os nomes: a esta escala o que se procura é a
                        forma do ano, e ler os acontecimentos um a um é o que a
                        vista de Mês faz, a um clique deste mesmo cartão.

                        Os traços que aqui estavam ao lado do número foram-se
                        embora: repetiam em desenho, e com um tecto de seis, o
                        número que já estava escrito a seu lado — não eram uma
                        repartição por período nem por espécie, não eram nada
                        que o número não dissesse melhor.
                    -->
                    <span class="mt-auto flex flex-wrap items-center gap-1.5">
                        <span
                            v-if="month.assessments_count > 0"
                            class="flex items-center gap-1.5 rounded-md border border-foreground/25 bg-background/80 px-2 py-0.5 text-xs font-medium"
                        >
                            <ClipboardCheck class="size-3" aria-hidden="true" />
                            {{ assessmentsLabel(month.assessments_count) }}
                        </span>
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
