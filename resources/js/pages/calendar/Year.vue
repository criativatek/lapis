<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarOff,
    CalendarRange,
    ClipboardCheck,
    LayoutGrid,
} from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';
import type { CalendarException, CalendarPeriod } from './calendar';
import {
    asDate,
    EXCEPTION_SURFACE,
    exceptionRange,
    fullyContainedPeriod,
    periodContext,
    periodRange,
    periodTint,
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
    /** Exceções CRUZANDO este mês — a mesma semântica dos períodos. */
    exception_ulids: string[];
    /**
     * QUANTOS DIAS DESTE MÊS SÃO MESMO NÃO LETIVOS — dias distintos, contados no
     * servidor e recortados ao mês. Não é o número de exceções, de propósito:
     * «2 exceções em dezembro» pode ser um feriado mais onze dias de interrupção,
     * e a esta escala o que se pergunta a um mês é quantos dias é que ele perde.
     */
    non_teaching_days_count: number;
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
    /**
     * As exceções letivas do ano inteiras — para os cartões dos meses poderem
     * NOMEAR as suas, e não só contá-las. Continua a não haver aqui um cartão
     * por exceção: os cartões são os meses.
     */
    exceptions: CalendarException[];
    assessmentsTotal: number;
    eventsTotal: number;
    /** Dias distintos não letivos no ano inteiro, contados no servidor. */
    nonTeachingDaysTotal: number;
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
 * tom que diga a verdade sobre metade dele. Isso não mudou, e não é isso que
 * esta função decide.
 *
 * O QUE MUDOU É QUAL É O TOM: o do período que cobre o mês, e não um só para
 * todos. É o mesmo `periodTint` que a faixa da vista de Mês usa e o mesmo que a
 * célula de cada dia lá em baixo lava — os dois semestres de um ano deixam de
 * ser a mesma mancha, e cada um é a mesma cor em todo o lado onde aparece.
 */
function monthTint(month: YearMonth): string {
    const contained = containedPeriod(month);

    return contained === null ? '' : periodTint(contained);
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

function exceptionsOf(month: YearMonth): CalendarException[] {
    return props.exceptions.filter((exception) =>
        month.exception_ulids.includes(exception.ulid),
    );
}

/**
 * «11 dias não letivos» — DIAS, e nunca «2 exceções».
 *
 * Um feriado e uma interrupção de duas semanas são duas exceções e treze dias,
 * e é o segundo número que responde à pergunta que se faz a um mês a esta
 * escala. E a palavra «não letivo» vai escrita, sempre: é o que mantém isto
 * distinto de «2 avaliações» e de «3 acontecimentos» sem depender de cor nem
 * de ícone nenhum.
 */
function nonTeachingDaysLabel(count: number): string {
    return count === 1 ? '1 dia não letivo' : `${count} dias não letivos`;
}

/** «Feriado: Implantação da República · 5/10», para o título da etiqueta. */
function exceptionsTitle(month: YearMonth): string {
    return exceptionsOf(month)
        .map(
            (exception) =>
                `${exception.type_label}: ${exception.title} · ${exceptionRange(exception)}`,
        )
        .join(' · ');
}

const description = computed(() => {
    if (!props.academicYear) {
        return 'O ano letivo inteiro, de uma vez.';
    }

    // Os dias não letivos entram nesta linha só quando existem, ao contrário
    // das duas contagens acima: «0 avaliações» é uma resposta a uma pergunta
    // que se faz sempre, «0 dias não letivos» é ruído num ano a que ninguém
    // ainda escreveu um único feriado.
    const nonTeaching =
        props.nonTeachingDaysTotal > 0
            ? ` · ${nonTeachingDaysLabel(props.nonTeachingDaysTotal)}`
            : '';

    return `${props.academicYear.label} · ${props.periodsCountLabel} · ${assessmentsLabel(props.assessmentsTotal)} · ${eventsLabel(props.eventsTotal)}${nonTeaching}`;
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

                E CADA UM NO SEU TOM, que é o MESMO tom com que os seus meses
                aparecem aqui em baixo e com que os seus dias aparecem na vista
                de Mês: a faixa do 1.º Semestre e os meses do 1.º Semestre são a
                mesma cor, e é assim que a lista serve de legenda à grelha. E o
                nome está escrito na faixa, como sempre esteve — a cor nunca é o
                que diz qual é o período.
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
                    :class="periodTint(period)"
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
                        E QUAIS SÃO OS DIAS QUE ESTE MÊS PERDE, pelo nome. É a
                        única coisa desta vista que é nomeada e não apenas
                        contada, e a razão é que um número sozinho não a diz: «11
                        dias não letivos» em dezembro pode ser a interrupção do
                        Natal ou onze feriados espalhados, e são duas formas de
                        ano completamente diferentes.

                        UMA LINHA, E NUNCA UMA LISTA DE CARTÕES: os nomes vão
                        seguidos, separados por «·», e a linha corta-se ao fim de
                        duas — a esta escala procura-se a forma do ano, e um mês
                        com oito feriados não pode passar a ser oito vezes mais
                        alto do que os outros. A leitura um a um é a da vista de
                        Mês, a um clique deste mesmo cartão.
                    -->
                    <span
                        v-if="exceptionsOf(month).length > 0"
                        :data-month-exceptions="month.value"
                        class="line-clamp-2 text-xs opacity-80"
                        >{{
                            exceptionsOf(month)
                                .map((exception) => exception.title)
                                .join(' · ')
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

                        <!--
                            E OS DIAS NÃO LETIVOS, distintos das duas etiquetas
                            ao lado por TRÊS coisas ao mesmo tempo e não só pela
                            cor: um ícone que só eles têm (`CalendarOff`), as
                            palavras «não letivo» escritas por extenso, e o
                            cinzento neutro que os separa tanto do tom do
                            período que pinta o cartão como do tracejado
                            incolor dos acontecimentos. Em DIAS e não em
                            exceções — ver nonTeachingDaysLabel().
                        -->
                        <span
                            v-if="month.non_teaching_days_count > 0"
                            :data-non-teaching-days="
                                month.non_teaching_days_count
                            "
                            class="flex items-center gap-1.5 rounded-md border px-2 py-0.5 text-xs font-medium"
                            :class="EXCEPTION_SURFACE"
                            :title="exceptionsTitle(month)"
                        >
                            <CalendarOff class="size-3" aria-hidden="true" />
                            {{
                                nonTeachingDaysLabel(
                                    month.non_teaching_days_count,
                                )
                            }}
                        </span>
                    </span>
                </Link>
            </section>
        </template>
    </main>
</template>
