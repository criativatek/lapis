<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CalendarDays,
    CalendarOff,
    CalendarPlus,
    CalendarRange,
    ChevronLeft,
    ChevronRight,
    Circle,
    ClipboardCheck,
    FileUp,
    LayoutGrid,
    MapPin,
    Sparkles,
    Trash2,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { capitalizeFirst } from '@/lib/text';
import type {
    CalendarAssessment,
    CalendarDay,
    CalendarEvent,
    CalendarEventType,
    CalendarException,
    CalendarPeriod,
    CalendarTeacherClass,
} from './calendar';
import {
    asDate,
    EXCEPTION_ACCENT,
    EXCEPTION_DAY_TINT,
    EXCEPTION_SURFACE,
    eventBadgeClasses,
    eventEntryClasses,
    eventTimeLabel,
    exceptionRange,
    exceptionsTouching,
    formatDay,
    fullyContainedPeriod,
    namesException,
    periodBoundaryNote,
    periodDayTint,
    periodRange,
    periodTint,
} from './calendar';

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
    /**
     * As exceções letivas que cruzam a GRELHA visível — nomeadas uma vez, na
     * faixa por cima dela, e não uma vez por cada dia que ocupam.
     */
    exceptions: CalendarException[];
    navigation: {
        previous: string;
        next: string;
        home: string;
        home_is_today: boolean;
    } | null;
    /**
     * The cap over EVERYTHING a day cell shows — avaliações and acontecimentos
     * counted together, never one mechanism each.
     */
    itemsPerDay: number;
    /** The turmas this teacher leciona, and only those. */
    classes: CalendarTeacherClass[];
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

// ------------------------------------------------ o que uma célula mostra

/**
 * Uma célula do mês mostra DUAS espécies de coisa, e a ordem entre elas é a
 * escada de peso: a avaliação primeiro, sempre, porque é o que tem
 * consequências; os acontecimentos a seguir.
 */
type DayItem =
    | { kind: 'assessment'; key: string; assessment: CalendarAssessment }
    | { kind: 'event'; key: string; event: CalendarEvent };

function itemsOf(day: CalendarDay): DayItem[] {
    return [
        ...day.assessments.map(
            (assessment): DayItem => ({
                kind: 'assessment',
                key: `assessment-${assessment.ulid}`,
                assessment,
            }),
        ),
        ...day.events.map(
            (event): DayItem => ({ kind: 'event', key: `event-${event.ulid}`, event }),
        ),
    ];
}

/**
 * Days a cell has stopped hiding. The cap keeps a test-heavy Friday from
 * turning its column into a page of its own; opening one is the teacher's
 * choice, and it re-closes.
 *
 * UM SÓ MECANISMO PARA AS DUAS ESPÉCIES: um dia com duas avaliações e três
 * acontecimentos está exatamente tão cheio como um dia com cinco avaliações, e
 * um limite que contasse só uma das espécies deixaria a célula crescer sem fim
 * assim que a outra aparecesse.
 */
const expanded = ref<Set<string>>(new Set());

function toggle(date: string): void {
    const next = new Set(expanded.value);

    if (!next.delete(date)) {
        next.add(date);
    }

    expanded.value = next;
}

function shown(day: CalendarDay): DayItem[] {
    const items = itemsOf(day);

    return expanded.value.has(day.date) ? items : items.slice(0, props.itemsPerDay);
}

function hiddenCount(day: CalendarDay): number {
    return Math.max(0, itemsOf(day).length - props.itemsPerDay);
}

/**
 * O ícone de cada espécie. NUNCA SÓ A COR: cada entrada traz sempre também o
 * seu ícone e a sua etiqueta escrita, para a página continuar legível num ecrã
 * monocromático e para quem não distingue as cores.
 */
const EVENT_ICONS: Record<CalendarEventType, Component> = {
    meeting: Users,
    activity: Sparkles,
    field_trip: MapPin,
    other: Circle,
};

function eventIcon(type: CalendarEventType): Component {
    return EVENT_ICONS[type];
}

/**
 * The narrow-viewport reading. A month grid squeezed into a phone is seven
 * illegible columns, so the same month is read as an agenda instead — the way
 * the weekly view already stacks its own days — and only the days that carry
 * something are listed: an empty Tuesday is not information.
 */
const agenda = computed(() =>
    props.days.filter(
        (day) =>
            day.in_month && (day.assessments.length > 0 || day.events.length > 0),
    ),
);

const assessmentsThisMonth = computed(() =>
    props.days.reduce(
        (total, day) => total + (day.in_month ? day.assessments.length : 0),
        0,
    ),
);

/**
 * Contado por acontecimento e não por célula: uma visita de estudo de três dias
 * aparece em três células do mês e continua a ser UM acontecimento.
 */
const eventsThisMonth = computed(() => {
    const seen = new Set<string>();

    for (const day of props.days) {
        if (!day.in_month) {
            continue;
        }

        for (const event of day.events) {
            seen.add(event.ulid);
        }
    }

    return seen.size;
});

/**
 * O QUE VEM A SEGUIR AO MÊS, e não o que o substitui. O mês que está a ser visto
 * é agora o título da página — grande, e o mais forte do cabeçalho — pelo que
 * escrevê-lo outra vez aqui era dizer duas vezes a mesma coisa. Fica o ano
 * letivo e as duas contagens, em texto esbatido, que é o peso que têm.
 */
const summary = computed(() => {
    if (!props.academicYear || !props.month) {
        return 'A estrutura do ano letivo, as avaliações e os teus acontecimentos, lado a lado.';
    }

    const count = assessmentsThisMonth.value;
    const avaliacoes = count === 1 ? '1 avaliação' : `${count} avaliações`;
    const events = eventsThisMonth.value;
    const acontecimentos =
        events === 1 ? '1 acontecimento' : `${events} acontecimentos`;

    return `${props.academicYear.label} · ${avaliacoes} · ${acontecimentos}`;
});

// -------------------------------------- a estrutura do ano, DESTE mês

/**
 * OS PERÍODOS DO MÊS QUE SE ESTÁ A VER, e não os da grelha. `props.periods` é
 * preenchido a partir do intervalo VISÍVEL — que inclui os últimos dias do mês
 * anterior e os primeiros do seguinte, para as linhas da grelha fecharem — e é
 * assim que tem de ser para cada célula saber o seu período. Mas a faixa
 * estrutural fala DO MÊS, e um período que só toca o dia 28 de setembro não é
 * estrutura de outubro nenhuma.
 */
const periodsThisMonth = computed(() => {
    const month = props.month;

    if (month === null) {
        return [];
    }

    return props.periods.filter(
        (period) =>
            period.starts_on <= month.ends_on &&
            period.ends_on >= month.starts_on,
    );
});

/**
 * A MESMA RESPOSTA QUE A VISTA DE ANO DÁ AO MESMO MÊS, porque é literalmente a
 * mesma função (`fullyContainedPeriod`, em calendar.ts) a respondê-la: um mês
 * inteiramente dentro de um período diz o período de uma ponta à outra
 * («1.º Semestre · 11/09 – 29/01»); um mês de transição diz onde é que o período
 * realmente abre ou fecha dentro dele («1.º Semestre · desde 11/09»), porque a
 * primeira frase, em setembro, seria verdadeira sobre o semestre e falsa sobre
 * os dez primeiros dias do mês.
 *
 * Dois períodos a tocarem o mesmo mês aparecem OS DOIS, nunca só o primeiro.
 */
const structuralBands = computed(() => {
    const month = props.month;

    if (month === null) {
        return [];
    }

    const touching = periodsThisMonth.value;
    const contained = fullyContainedPeriod(month, touching);

    return touching.map((period) => ({
        period,
        detail:
            contained !== null && contained.ulid === period.ulid
                ? periodRange(period)
                : // Um período que atravessa o mês inteiro sem abrir nem fechar
                  // lá dentro não tem fronteira nenhuma para dizer: fica com o
                  // seu intervalo verdadeiro, que é o que sobra por dizer.
                  periodBoundaryNote(month, period) || periodRange(period),
    }));
});

/**
 * O FUNDO DE UMA CÉLULA, e as duas coisas diferentes que ele pode estar a
 * dizer.
 *
 * O CINZENTO DE «FORA DO MÊS» GANHA SEMPRE. Um dia que a grelha só mostra para
 * a linha fechar — 28 de setembro numa grelha de outubro — pode muito bem estar
 * dentro de um período, e está: mas o que é preciso saber sobre ele, primeiro e
 * acima de tudo, é que não é deste mês. As duas tintas ao mesmo tempo não dizem
 * as duas coisas, dizem uma cor confusa.
 *
 * E O TOM DO PERÍODO É EXATO AO DIA. Sai do `day.period`, que o servidor já
 * calcula dia a dia — o período cujo `starts_on <= data <= ends_on` para AQUELA
 * data, e `null` quando não há nenhum. Num setembro que abre a 11, os dias 1 a
 * 10 ficam por pintar e o 11 é o primeiro pintado: nem um dia a mais, e nunca
 * o mês inteiro por causa de metade dele. O intervalo entre dois períodos fica
 * igualmente por pintar, que é o que ele é.
 */
function dayTint(day: CalendarDay): string {
    if (!day.in_month) {
        return 'bg-muted/30';
    }

    // E UM DIA NÃO LETIVO GANHA AO PERÍODO EM QUE CAI. 21 de dezembro está
    // dentro do 1.º Semestre E dentro da Interrupção de Natal, e as duas coisas
    // são verdade — mas o que é preciso saber sobre AQUELE dia é que não há
    // aula. Que ele seja do 1.º Semestre continua escrito, com todas as letras,
    // na faixa por cima da grelha, que é onde o período já se dizia; o que a
    // faixa não conseguia dizer é qual dos trinta e um dias é que é feriado.
    //
    // E não se somam as duas tintas: duas tintas ao mesmo tempo não dizem duas
    // coisas, dizem uma cor confusa — exatamente o mesmo raciocínio que o
    // cinzento de «fora do mês» aqui em cima já faz.
    if (day.exception !== null) {
        return EXCEPTION_DAY_TINT;
    }

    return day.period === null ? '' : periodDayTint(day.period);
}

// ----------------------------------- os dias em que não há aula, DESTE mês

/**
 * AS EXCEÇÕES DO MÊS QUE SE ESTÁ A VER, e não as da grelha — exatamente o mesmo
 * recorte que `periodsThisMonth` faz aos períodos, e pela mesma razão: a faixa
 * fala DO MÊS, e uma interrupção que só toca o dia 28 de setembro não é
 * estrutura de outubro nenhuma.
 */
const exceptionsThisMonth = computed(() =>
    props.month === null
        ? []
        : exceptionsTouching(props.month, props.exceptions),
);

/**
 * As datas em que a exceção que as cobre é NOMEADA — a primeira célula da grelha
 * de cada exceção, e mais nenhuma. Calculado uma vez sobre a grelha inteira, em
 * vez de por célula, porque a pergunta é sobre o dia ANTERIOR e as semanas são
 * fatias desta mesma lista.
 */
const exceptionNamingDates = computed(() => {
    const dates = new Set<string>();

    props.days.forEach((day, index) => {
        if (namesException(props.days, index)) {
            dates.add(day.date);
        }
    });

    return dates;
});

function namesExceptionOn(day: CalendarDay): boolean {
    return exceptionNamingDates.value.has(day.date);
}

function dayLabel(date: string): string {
    return capitalizeFirst(dayFormatter.format(asDate(date)));
}

function eventRange(event: CalendarEvent): string {
    return event.ends_on === event.starts_on
        ? formatDay(event.starts_on)
        : `${formatDay(event.starts_on)} – ${formatDay(event.ends_on)}`;
}

function goToMonth(month: string): void {
    router.get(
        '/calendar',
        { month },
        { preserveState: true, preserveScroll: true },
    );
}

// ------------------------------------------------------ os acontecimentos

/**
 * UM SÓ PAINEL para ver, editar e eliminar, e o MESMO formulário para criar —
 * pré-preenchido quando se abre a partir de um acontecimento que já existe.
 * Dois formulários diferentes para a mesma coisa é como se acaba com uma regra
 * aplicada na criação e esquecida na edição.
 */
const panelOpen = ref(false);
const editing = ref<CalendarEvent | null>(null);

/**
 * O painel a mostrar a pergunta em vez do formulário. Confirmar é confirmado
 * DENTRO do mesmo painel — com o mesmo desenho das outras confirmações
 * destrutivas desta aplicação (ver PasskeyItem.vue) — e não numa caixa do
 * navegador que não se parece com nada do resto da página. Não há aqui um
 * segundo Dialog: é o MESMO que já está aberto a trocar o que mostra.
 */
const confirmingDelete = ref(false);

/**
 * Fechar por qualquer via — o X do próprio Dialog, a tecla Escape, o
 * «Cancelar», ou uma gravação bem sucedida — deixa o painel pronto a abrir
 * outra vez no formulário, e nunca a meio de uma pergunta que já ninguém fez.
 */
watch(panelOpen, (open) => {
    if (!open) {
        confirmingDelete.value = false;
    }
});

function closePanel(): void {
    confirmingDelete.value = false;
    panelOpen.value = false;
}

const form = useForm<{
    type: CalendarEventType;
    title: string;
    starts_on: string;
    ends_on: string;
    starts_at: string;
    ends_at: string;
    description: string;
    school_class_ulids: string[];
}>({
    type: 'meeting',
    title: '',
    starts_on: '',
    ends_on: '',
    starts_at: '',
    ends_at: '',
    description: '',
    school_class_ulids: [],
});

const TYPE_OPTIONS: { value: CalendarEventType; label: string }[] = [
    { value: 'meeting', label: 'Reunião' },
    { value: 'activity', label: 'Atividade' },
    { value: 'field_trip', label: 'Visita de estudo' },
    { value: 'other', label: 'Outro' },
];

/** O primeiro dia do mês que está a ser visto — o palpite honesto quando o
 * professor carrega no botão em vez de carregar num dia. */
function defaultDate(): string {
    return props.month?.starts_on ?? '';
}

/**
 * O BOTÃO EXPLÍCITO. Funciona sozinho, sem que nenhum dia tenha sido carregado
 * — criar um acontecimento não pode estar escondido dentro de um gesto que é
 * preciso adivinhar.
 */
function openCreate(date?: string): void {
    editing.value = null;
    confirmingDelete.value = false;
    form.defaults({
        type: 'meeting',
        title: '',
        starts_on: date ?? defaultDate(),
        ends_on: '',
        starts_at: '',
        ends_at: '',
        description: '',
        school_class_ulids: [],
    });
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
}

function openEvent(event: CalendarEvent): void {
    editing.value = event;
    confirmingDelete.value = false;
    form.defaults({
        type: event.type,
        title: event.title,
        starts_on: event.starts_on,
        // Um acontecimento de um só dia mostra a data de fim vazia, que é o que
        // ela é: a data final é opcional, e igual à inicial quando não existe.
        ends_on: event.ends_on === event.starts_on ? '' : event.ends_on,
        starts_at: event.starts_at ?? '',
        ends_at: event.ends_at ?? '',
        description: event.description ?? '',
        school_class_ulids: event.school_classes.map(
            (schoolClass) => schoolClass.ulid,
        ),
    });
    form.reset();
    form.clearErrors();
    panelOpen.value = true;
}

function toggleClass(ulid: string): void {
    form.school_class_ulids = form.school_class_ulids.includes(ulid)
        ? form.school_class_ulids.filter((selected) => selected !== ulid)
        : [...form.school_class_ulids, ulid];
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            panelOpen.value = false;
        },
    };

    if (editing.value) {
        form.put(`/calendar/acontecimentos/${editing.value.ulid}`, options);
    } else {
        form.post('/calendar/acontecimentos', options);
    }
}

/**
 * Eliminar um acontecimento elimina UM ACONTECIMENTO: as avaliações, a
 * estrutura do ano letivo e o horário têm ciclos de vida inteiramente próprios
 * e não são tocados — e o texto da pergunta diz isso, para ninguém ter de o
 * adivinhar.
 */
function destroyEvent(event: CalendarEvent): void {
    confirmingDelete.value = false;

    useForm({}).delete(`/calendar/acontecimentos/${event.ulid}`, {
        preserveScroll: true,
        onSuccess: () => {
            panelOpen.value = false;
        },
    });
}
</script>

<template>
    <Head title="Calendário do Ano Letivo" />

    <main class="mx-auto w-full max-w-6xl space-y-6 p-4 pb-24 sm:p-6">
        <!--
            O MÊS QUE SE ESTÁ A VER É O CABEÇALHO DA PÁGINA. Antes, «Outubro de
            2026» estava enterrado a meio de uma linha esbatida de contexto —
            «Outubro de 2026 · 2026/2027 · 3 avaliações · 4 acontecimentos» — e a
            primeira pergunta de quem abre um calendário («que mês é este?») era
            a mais difícil de responder da página. Agora é a coisa mais forte do
            cabeçalho, com a navegação entre meses ao seu lado e não solta numa
            linha própria por baixo, e o resto do contexto ficou onde o seu peso
            manda: pequeno e esbatido, a seguir.
        -->
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
        >
            <div class="min-w-0 space-y-2">
                <!--
                    A IDENTIDADE DA PÁGINA continua escrita — não desapareceu,
                    trocou de lugar com o mês, que é o que muda de ecrã para
                    ecrã e o que se procura primeiro.
                -->
                <p
                    v-if="month"
                    class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                >
                    Calendário do Ano Letivo
                </p>

                <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                    <h2
                        v-if="month"
                        data-month-title
                        class="text-2xl font-bold tracking-tight sm:text-3xl"
                    >
                        {{ monthLabel }}
                    </h2>
                    <h2 v-else class="text-xl font-semibold tracking-tight">
                        Calendário do Ano Letivo
                    </h2>

                    <!--
                        ANDAR ENTRE MESES é uma coisa do MÊS, e por isso vive
                        colada ao título dele — agrupada dentro de uma só
                        moldura, em botões leves, para nunca se confundir com o
                        seletor de vista (Mês/Ano) ali ao lado, que é outra
                        pergunta inteiramente: essa troca de VISTA, esta anda
                        dentro da vista de Mês.
                    -->
                    <nav
                        v-if="navigation"
                        class="flex flex-wrap items-center gap-1 rounded-lg border p-1"
                        aria-label="Navegação entre meses"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            class="min-h-10"
                            @click="goToMonth(navigation.previous)"
                        >
                            <ChevronLeft class="size-4" /> Mês anterior
                        </Button>
                        <Button
                            variant="ghost"
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
                            variant="ghost"
                            size="sm"
                            class="min-h-10"
                            @click="goToMonth(navigation.next)"
                        >
                            Mês seguinte <ChevronRight class="size-4" />
                        </Button>
                    </nav>
                </div>

                <p class="text-sm text-muted-foreground">{{ summary }}</p>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <!--
                    AS DUAS VISTAS SÃO DOIS ENDEREÇOS, não um estado interno
                    desta página: cada uma pode ser guardada nos favoritos e o
                    botão de voltar do navegador funciona entre elas.
                -->
                <nav class="flex gap-2" aria-label="Vista do calendário">
                    <Button
                        variant="default"
                        size="sm"
                        class="min-h-10"
                        disabled
                    >
                        <LayoutGrid class="size-4" /> Mês
                    </Button>
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        class="min-h-10"
                    >
                        <Link href="/calendar/ano">
                            <CalendarRange class="size-4" /> Ano
                        </Link>
                    </Button>
                </nav>

                <!--
                    O BOTÃO EXPLÍCITO, sempre visível e sempre suficiente por si
                    só: criar um acontecimento não está escondido dentro de um
                    clique num dia que seja preciso adivinhar. Carregar num dia
                    da grelha é um atalho por cima disto, e não o caminho.
                -->
                <Button
                    v-if="month"
                    class="min-h-10"
                    size="sm"
                    data-new-event
                    @click="openCreate()"
                >
                    <CalendarPlus class="size-4" /> Novo acontecimento
                </Button>

                <!--
                    A PORTA PARA A IMPORTAÇÃO É O CALENDÁRIO, e não uma entrada
                    de menu nova (§24). Quem decide importar o calendário da
                    escola está a olhar para o calendário quando o decide — a
                    página onde a falta se nota é a página onde se resolve. Ao
                    lado de «Novo acontecimento» e não em vez dele: escrever uma
                    data à mão e carregar o ficheiro da escola são as duas
                    maneiras de encher esta página, e nenhuma substitui a outra.
                -->
                <Button
                    v-if="academicYear"
                    as-child
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    data-import-calendar
                >
                    <Link href="/academic-calendar-imports/create">
                        <FileUp class="size-4" /> Importar calendário da escola
                    </Link>
                </Button>
            </div>
        </header>

        <!--
            O QUE ESTA PÁGINA LÊ, e o que só ela escreve. Os períodos do ano e
            as avaliações são lidos onde já vivem, e continuam a ser criados e
            alterados nas suas próprias páginas. Os acontecimentos — a reunião,
            a atividade, a visita de estudo, o «outro» — são as únicas coisas
            datadas que não têm casa em mais lado nenhum, e por isso são as
            únicas que nascem aqui. As aulas não aparecem, de propósito: essa
            pergunta é a do «Horário do Professor», e já tem a sua página.
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
            <!--
                OS PERÍODOS DESTE MÊS NUMA FAIXA A SÉRIO — uma tira baixa e
                larga, do tamanho da grelha que fica logo por baixo dela, e não
                as pastilhas soltas que aqui estavam: pequenas, a flutuar num
                canto, e demasiado fáceis de não ver. Uma só faixa, também
                quando são dois os períodos que tocam o mês.

                E CONTINUA A NÃO SER NEM UM BOTÃO NEM UM ACONTECIMENTO: sem
                ícone, sem moldura de cor, sem sombra e sem nada em que carregar
                — só texto quieto sobre o bege da estrutura. A distinção nunca é
                só de cor: uma avaliação tem moldura, ícone e peso, e isto não
                tem nenhuma das três coisas.

                E DIZ O QUE É VERDADE SOBRE ESTE MÊS, não sobre a grelha: os
                períodos são filtrados pelos limites reais do mês e ditos com o
                mesmo «desde»/«até» que a vista de Ano já usava, pela mesma
                função. Um mês que nenhum período toca não diz nada — nem uma
                faixa, nem um período por omissão.

                DOIS NÍVEIS DE SEPARAÇÃO, E POR ISSO DOIS DESENHOS: o «·» separa
                o nome de um período das suas próprias datas; o traço vertical —
                mais pesado, e desenhado em vez de escrito — separa um período
                do outro. Com o mesmo «·» nos dois sítios, «1.º Período · até
                5/11 · 2.º Período · desde 15/11» lia-se como uma lista de
                quatro coisas todas ao mesmo nível.

                E O NOME DO PERÍODO JÁ DIZ A ESPÉCIE — «1.º Semestre», «2.º
                Período» — pelo que repeti-la a seguir («1.º Semestre ·
                Semestre») não acrescentava nada a ninguém. Fica o nome e as
                datas, que é o que aqui falta saber.

                E CADA PERÍODO TRAZ O SEU PRÓPRIO TOM PARA DENTRO DA FAIXA. A
                faixa era de uma cor só, e num mês de transição os dois períodos
                ficavam com o mesmo fundo — o que é a mesma tinta a apagar
                justamente a fronteira que aquele mês tem para mostrar. Continua
                a ser UMA faixa, com uma moldura só e a largura da grelha: o que
                mudou foi ela ficar repartida por dentro, cada segmento no tom
                que o seu período tem também nos dias da grelha logo abaixo. Com
                um período só — o caso normal — há um segmento, que ocupa a
                faixa inteira, e ela lê-se exatamente como antes.

                E O TOM NÃO É O QUE SEPARA OS DOIS: cada segmento diz o nome do
                seu período, e o traço entre eles está desenhado.
            -->
            <section
                v-if="structuralBands.length > 0"
                class="flex flex-wrap overflow-hidden rounded-lg border text-sm"
                aria-label="Períodos deste mês"
            >
                <span
                    v-for="(band, index) in structuralBands"
                    :key="band.period.ulid"
                    :data-period-band="band.period.ulid"
                    class="min-w-64 flex-1 px-4 py-2.5"
                    :class="[
                        periodTint(band.period),
                        index > 0 ? 'border-l' : '',
                    ]"
                >
                    <span class="font-medium">{{ band.period.label }}</span>
                    <span class="opacity-80"> · {{ band.detail }}</span>
                </span>
            </section>
            <p
                v-else-if="periods.length === 0"
                class="text-sm text-muted-foreground"
            >
                Este ano letivo ainda não tem períodos definidos. O calendário
                mostra os dias na mesma — os períodos aparecem assim que
                estiverem criados em «Estrutura do Ano Letivo».
            </p>

            <!--
                OS DIAS EM QUE NÃO HÁ AULA, DITOS UMA VEZ E COMO INTERVALOS.
                Uma interrupção de 21 a 31 de dezembro aparece aqui como UMA
                linha — «INTERRUPÇÃO · Interrupção de Natal · 21/12 – 31/12» —
                e não como onze cartões iguais espalhados por onze células. É a
                regra do §16 do enunciado, e é a diferença entre isto e um
                acontecimento de vários dias, que continua (com razão) a
                aparecer em cada dia que atravessa.

                E NÃO SE CONFUNDE COM A FAIXA DOS PERÍODOS, logo acima: aquela é
                sólida e de matiz, esta é tracejada e cinzenta, e esta traz ícone
                e uma palavra escrita («FERIADO», «INTERRUPÇÃO», «NÃO LETIVO»)
                que aquela nunca tem. Nem com um acontecimento: um acontecimento
                vive DENTRO de uma célula, com moldura de cor e hora; isto é
                estrutura, e vive por cima da grelha, onde a estrutura já vivia.

                E É A ÚNICA DAS DUAS QUE APARECE TAMBÉM EM ECRÃ ESTREITO: a
                agenda lista só os dias que têm alguma coisa marcada, e uma
                interrupção não «marca» nada em dia nenhum — é isto que a diz.
            -->
            <section
                v-if="exceptionsThisMonth.length > 0"
                class="flex flex-wrap items-center gap-x-4 gap-y-1.5 rounded-lg border px-4 py-2.5 text-sm"
                :class="EXCEPTION_SURFACE"
                aria-label="Dias não letivos deste mês"
            >
                <span
                    class="flex shrink-0 items-center gap-1.5 text-xs font-semibold tracking-wide uppercase opacity-80"
                >
                    <CalendarOff class="size-4" aria-hidden="true" />
                    Não letivo
                </span>
                <template
                    v-for="(exception, index) in exceptionsThisMonth"
                    :key="exception.ulid"
                >
                    <span
                        v-if="index > 0"
                        class="h-4 w-px shrink-0 bg-foreground/20"
                        aria-hidden="true"
                    />
                    <span :data-exception-band="exception.ulid">
                        <span class="font-medium">{{ exception.title }}</span>
                        <span class="opacity-80">
                            · {{ exception.type_label }} ·
                            {{ exceptionRange(exception) }}</span
                        >
                    </span>
                </template>
            </section>

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
                    <!--
                        A ESTRUTURA DO ANO VOLTA À GRELHA, A SUSSURRAR. A grelha
                        inteiramente branca era honesta e ilegível: o desenho do
                        ano letivo — onde é que um semestre abre, onde é que o
                        outro começa — desaparecia da única página feita para o
                        mostrar. O que voltou é um tom por período, de peso 50 a
                        70% de opacidade, e o que NÃO voltou é o banho de cor
                        antigo, que era de peso 100 e por mês inteiro.

                        E É EXATO AO DIA, tão exato como a faixa lá em cima. A
                        cor sai do `day.period` — o período daquela data, e não
                        do mês — pelo que num setembro que abre a 11 os dez
                        primeiros dias ficam por pintar. Pintar setembro inteiro
                        continuaria a ser dizer uma coisa falsa sobre eles.

                        E A CÉLULA CONTINUA A NÃO NOMEAR O PERÍODO. Nomeava-o
                        onde ele começava — a célula do dia 11 de setembro dizia
                        «1.º Semestre» — a um centímetro de uma faixa que já diz
                        «1.º Semestre · desde 11/09» por cima da grelha inteira e
                        com muito mais peso.
                    -->
                    <div
                        v-for="day in week"
                        :key="day.date"
                        class="min-h-28 border-r p-1.5 last:border-r-0"
                        :class="dayTint(day)"
                        :aria-label="dayLabel(day.date)"
                        :data-date="day.date"
                    >
                        <div class="flex items-baseline justify-between gap-1">
                            <!--
                                O número do dia é o atalho: carregar nele abre o
                                mesmo formulário já com esta data. É um botão a
                                sério — alcançável pelo teclado e com nome — e
                                não um `div` que responde ao rato.
                            -->
                            <button
                                type="button"
                                data-add-on-day
                                class="rounded-md text-xs tabular-nums transition-colors outline-none hover:bg-foreground/10 focus-visible:ring-2 focus-visible:ring-ring"
                                :class="[
                                    day.in_month
                                        ? 'font-medium'
                                        : 'text-muted-foreground',
                                    day.is_today
                                        ? 'bg-foreground px-1.5 py-0.5 text-background'
                                        : 'px-1',
                                ]"
                                :aria-label="`Novo acontecimento em ${dayLabel(day.date)}`"
                                @click="openCreate(day.date)"
                            >
                                {{ day.day }}
                            </button>
                        </div>

                        <!--
                            A EXCEÇÃO NOMEIA-SE ONDE COMEÇA, E SÓ AÍ. Nos
                            restantes dias que ela cobre fica o tom cinzento e o
                            tracejado da própria célula, que é o que já diz «não
                            há aula» — repetir «Interrupção de Natal» em onze
                            células seguidas era escrever onze vezes a mesma
                            frase e enterrar o que cada dia tem de próprio.

                            E NÃO É UM CARTÃO: sem moldura, sem fundo próprio,
                            sem nada em que carregar — texto quieto por cima do
                            tom da célula, exatamente o peso que a faixa dos
                            períodos tem lá em cima. Um acontecimento, na lista
                            aqui em baixo, tem moldura e é um botão; uma
                            avaliação tem moldura, fundo e peso. As três não se
                            confundem, e nenhuma delas depende da cor para isso:
                            esta traz sempre o seu ícone e a palavra da espécie.
                        -->
                        <p
                            v-if="day.exception && namesExceptionOn(day)"
                            class="mt-1 flex items-start gap-1 px-1 text-[0.7rem] leading-tight"
                            :class="EXCEPTION_ACCENT"
                            :data-exception-start="day.exception.ulid"
                            :title="`${day.exception.type_label} · ${day.exception.title} · ${exceptionRange(day.exception)}`"
                        >
                            <CalendarOff
                                class="mt-px size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-[0.6rem] font-semibold tracking-wide"
                                    >{{ day.exception.type_short_label }}</span
                                >
                                <span class="block truncate">{{
                                    day.exception.title
                                }}</span>
                            </span>
                        </p>

                        <ul class="mt-1 space-y-1">
                            <li v-for="item in shown(day)" :key="item.key">
                                <!--
                                    UMA AVALIAÇÃO: moldura sólida, ícone e peso
                                    de texto. É o tratamento mais forte da
                                    página e assim fica — nada do que se
                                    acrescentou lhe faz sombra.
                                -->
                                <Link
                                    v-if="item.kind === 'assessment'"
                                    :href="item.assessment.href"
                                    class="flex items-start gap-1 rounded-md border border-foreground/25 bg-background/80 px-1.5 py-1 text-[0.7rem] leading-tight font-medium transition-colors outline-none hover:border-foreground/50 focus-visible:ring-2 focus-visible:ring-ring"
                                    :title="`${item.assessment.title} · ${item.assessment.class_label} · ${item.assessment.type}`"
                                >
                                    <ClipboardCheck
                                        class="mt-px size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0">
                                        <span class="block truncate">{{
                                            item.assessment.title
                                        }}</span>
                                        <span
                                            class="block truncate font-normal opacity-75"
                                            >{{ item.assessment.class_label }} ·
                                            {{ item.assessment.type }}</span
                                        >
                                    </span>
                                </Link>

                                <!--
                                    UM ACONTECIMENTO: cor, MAIS ícone, MAIS
                                    etiqueta escrita da espécie. Nunca só a cor
                                    — a etiqueta e o ícone são o que mantém isto
                                    legível num ecrã monocromático e para quem
                                    não distingue as cores.
                                -->
                                <button
                                    v-else
                                    type="button"
                                    :data-event-ulid="item.event.ulid"
                                    :data-event-type="item.event.type"
                                    class="flex w-full items-start gap-1 rounded-md border px-1.5 py-1 text-left text-[0.7rem] leading-tight transition-colors outline-none hover:border-foreground/50 focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="eventEntryClasses(item.event.type)"
                                    :title="`${item.event.type_label} · ${item.event.title}`"
                                    @click="openEvent(item.event)"
                                >
                                    <component
                                        :is="eventIcon(item.event.type)"
                                        class="mt-px size-3 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span class="min-w-0">
                                        <!--
                                            A HORA DE INÍCIO ao lado da espécie,
                                            e SÓ quando ela existe: um
                                            acontecimento sem hora é um dia
                                            inteiro, e escrever-lhe uma hora
                                            qualquer seria inventá-la. A agenda
                                            de ecrã estreito continua a dar o
                                            intervalo inteiro, que é o que lá
                                            cabe.
                                        -->
                                        <span
                                            class="block truncate text-[0.6rem] font-semibold tracking-wide"
                                            :class="eventBadgeClasses(item.event.type)"
                                            >{{ item.event.type_short_label
                                            }}{{
                                                item.event.starts_at
                                                    ? ` · ${item.event.starts_at}`
                                                    : ''
                                            }}</span
                                        >
                                        <span class="block truncate">{{
                                            item.event.title
                                        }}</span>
                                    </span>
                                </button>
                            </li>
                        </ul>

                        <button
                            v-if="hiddenCount(day) > 0"
                            type="button"
                            data-overflow
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
                    Não há nada marcado em {{ monthLabel }}.
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
                        <!--
                            E, SE ESTE DIA NÃO É LETIVO, ISSO DITO NO PRÓPRIO
                            CABEÇALHO DO DIA — porque em ecrã estreito não há
                            célula nenhuma para tingir, e um teste listado por
                            baixo de um dia que afinal é feriado é exatamente a
                            coisa que o professor precisa de ver de imediato.
                            Com ícone e com a espécie escrita, e não só com uma
                            cor que aqui nem existe.
                        -->
                        <span
                            v-if="day.exception"
                            :data-agenda-exception="day.exception.ulid"
                            class="ml-1 inline-flex items-center gap-1 rounded-md border border-dashed px-1.5 py-0.5 align-middle text-xs font-medium"
                            :class="EXCEPTION_SURFACE"
                        >
                            <CalendarOff class="size-3" aria-hidden="true" />
                            {{ day.exception.type_label }} ·
                            {{ day.exception.title }}
                        </span>
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
                        <li v-for="event in day.events" :key="event.ulid">
                            <button
                                type="button"
                                :data-agenda-event-ulid="event.ulid"
                                class="flex w-full items-start gap-2 p-3 text-left transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                                @click="openEvent(event)"
                            >
                                <component
                                    :is="eventIcon(event.type)"
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span
                                        class="block text-xs font-semibold tracking-wide"
                                        :class="eventBadgeClasses(event.type)"
                                        >{{ event.type_short_label }}</span
                                    >
                                    <span class="block text-sm font-medium">{{
                                        event.title
                                    }}</span>
                                    <span
                                        class="block text-sm text-muted-foreground"
                                        >{{ eventTimeLabel(event) }} ·
                                        {{ eventRange(event) }}</span
                                    >
                                    <span
                                        v-if="event.school_classes.length > 0"
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            event.school_classes
                                                .map(
                                                    (schoolClass) =>
                                                        schoolClass.label,
                                                )
                                                .join(' · ')
                                        }}</span
                                    >
                                </span>
                            </button>
                        </li>
                    </ul>
                </section>
            </section>
        </template>

        <!--
            UM SÓ PAINEL: ver, editar e eliminar, com o MESMO formulário da
            criação, pré-preenchido. Um segundo formulário para a mesma coisa é
            como se acaba com uma regra aplicada ao criar e esquecida ao editar.
        -->
        <Dialog v-model:open="panelOpen">
            <DialogContent class="max-h-[85vh] max-w-2xl overflow-y-auto">
                <!--
                    A PERGUNTA, no MESMO painel e não num segundo Dialog por
                    cima deste: enquanto ela está no ar o formulário sai da
                    frente, e cancelar traz o formulário de volta exatamente
                    como estava, sem ter pedido nada ao servidor.
                -->
                <div v-if="confirmingDelete && editing" class="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Eliminar acontecimento?</DialogTitle>
                        <DialogDescription>
                            «{{ editing.title }}» será eliminada. As avaliações e
                            a estrutura do ano letivo não serão afetadas.
                        </DialogDescription>
                    </DialogHeader>

                    <DialogFooter class="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            data-cancel-delete
                            @click="confirmingDelete = false"
                        >
                            Cancelar
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            data-confirm-delete
                            @click="destroyEvent(editing)"
                        >
                            <Trash2 class="size-4" /> Eliminar
                        </Button>
                    </DialogFooter>
                </div>

                <form v-else class="space-y-4" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>{{
                            editing ? 'Acontecimento' : 'Novo acontecimento'
                        }}</DialogTitle>
                        <DialogDescription>
                            Regista uma reunião, atividade, visita de estudo ou
                            outro acontecimento relevante.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="event-type">Tipo</Label>
                            <select
                                id="event-type"
                                v-model="form.type"
                                class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                            >
                                <option
                                    v-for="option in TYPE_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <InputError :message="form.errors.type" />
                        </div>
                        <div class="grid gap-2 sm:col-span-2">
                            <Label for="event-title">Título</Label>
                            <Input
                                id="event-title"
                                v-model="form.title"
                                placeholder="Ex.: Reunião de conselho de turma"
                            />
                            <InputError :message="form.errors.title" />
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="event-starts-on">Data</Label>
                            <Input
                                id="event-starts-on"
                                v-model="form.starts_on"
                                type="date"
                            />
                            <InputError :message="form.errors.starts_on" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="event-ends-on">Data de fim</Label>
                            <Input
                                id="event-ends-on"
                                v-model="form.ends_on"
                                type="date"
                            />
                            <InputError :message="form.errors.ends_on" />
                            <p class="text-xs text-muted-foreground">
                                Em branco fica no mesmo dia.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="event-starts-at">Hora de início</Label>
                            <Input
                                id="event-starts-at"
                                v-model="form.starts_at"
                                type="time"
                            />
                            <InputError :message="form.errors.starts_at" />
                            <p class="text-xs text-muted-foreground">
                                Em branco é o dia inteiro.
                            </p>
                        </div>
                        <div class="grid gap-2">
                            <Label for="event-ends-at">Hora de fim</Label>
                            <Input
                                id="event-ends-at"
                                v-model="form.ends_at"
                                type="time"
                            />
                            <InputError :message="form.errors.ends_at" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="event-description">Notas</Label>
                        <textarea
                            id="event-description"
                            v-model="form.description"
                            rows="3"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm"
                        />
                        <InputError :message="form.errors.description" />
                    </div>

                    <div class="grid gap-2">
                        <span class="text-sm font-medium">Turmas</span>
                        <p
                            v-if="classes.length === 0"
                            class="text-xs text-muted-foreground"
                        >
                            Ainda não tens turmas para associar. O acontecimento
                            pode ficar sem turma nenhuma.
                        </p>
                        <div v-else class="flex flex-wrap gap-x-4 gap-y-2">
                            <Label
                                v-for="schoolClass in classes"
                                :key="schoolClass.ulid"
                                class="flex items-center gap-2 font-normal"
                            >
                                <Checkbox
                                    :model-value="
                                        form.school_class_ulids.includes(
                                            schoolClass.ulid,
                                        )
                                    "
                                    @update:model-value="
                                        toggleClass(schoolClass.ulid)
                                    "
                                />
                                <span
                                    >{{ schoolClass.label }} ·
                                    {{ schoolClass.subject }}</span
                                >
                            </Label>
                        </div>
                        <InputError :message="form.errors.school_class_ulids" />
                        <p class="text-xs text-muted-foreground">
                            Nenhuma, uma ou várias — só as turmas que lecionas.
                        </p>
                    </div>

                    <DialogFooter class="gap-2 sm:justify-between">
                        <!--
                            Eliminar PERGUNTA, e não elimina: o pedido só parte
                            depois da confirmação, que acontece neste mesmo
                            painel.
                        -->
                        <Button
                            v-if="editing"
                            type="button"
                            variant="ghost"
                            class="text-red-600 dark:text-red-500"
                            data-delete-event
                            @click="confirmingDelete = true"
                        >
                            <Trash2 class="size-4" /> Eliminar
                        </Button>
                        <span v-else />
                        <!--
                            SAIR ESCRITO, e não só o X do canto: fechar sem
                            guardar é uma escolha a sério, e uma escolha a sério
                            tem um botão com nome. O X continua a funcionar, e
                            faz exatamente o mesmo.
                        -->
                        <div class="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                data-cancel-event
                                @click="closePanel()"
                            >
                                Cancelar
                            </Button>
                            <Button type="submit" :disabled="form.processing">
                                {{
                                    editing
                                        ? 'Guardar alterações'
                                        : 'Adicionar ao calendário'
                                }}
                            </Button>
                        </div>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </main>
</template>
