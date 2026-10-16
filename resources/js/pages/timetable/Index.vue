<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    CalendarDays,
    CalendarX2,
    ChevronLeft,
    ChevronRight,
    FileUp,
    Plus,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import ScheduleChangeNotice from '@/components/timetable/ScheduleChangeNotice.vue';
import TimetableActivityDialog from '@/components/timetable/TimetableActivityDialog.vue';
import TimetableBlock from '@/components/timetable/TimetableBlock.vue';
import TimetableSlotDialog from '@/components/timetable/TimetableSlotDialog.vue';
import type {
    TimetableActivity,
    TimetableActivityKindOption,
    TimetableClassOption,
    TimetableSlot,
} from '@/components/timetable/types';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';
import type { TurmaTone } from './timetable';
import {
    resolveTurmaTones,
    TURMA_TONES,
    turmaBadgeClass,
    turmaBarClass,
} from './timetable';

type ConsultedWeek = { start: string; end: string; is_current: boolean };

const props = withDefaults(
    defineProps<{
        slots: TimetableSlot[];
        classes: TimetableClassOption[];
        /** As atividades não letivas do professor em vigor nesta semana (0.168.0). */
        activities?: TimetableActivity[];
        activityKinds?: TimetableActivityKindOption[];
        week?: ConsultedWeek;
        today?: string;
    }>(),
    { activities: () => [], activityKinds: () => [] },
);

// ------------------------------------------------------ a semana consultada

/**
 * O HORÁRIO É DE UMA SEMANA CONCRETA. O servidor só envia os blocos em vigor
 * nessa semana — de turmas ainda não arquivadas no dia de cada ocorrência —,
 * por isso uma semana passada mostra o que vigorava então e a atual já não
 * mostra configurações obsoletas. Navegar é só pedir outra semana: nada é
 * criado nem alterado.
 */
const shortDate = new Intl.DateTimeFormat('pt-PT', {
    day: '2-digit',
    month: '2-digit',
    timeZone: 'UTC',
});

function formatShort(date: string): string {
    return shortDate.format(new Date(`${date}T00:00:00Z`));
}

const weekLabel = computed(() =>
    props.week
        ? `${formatShort(props.week.start)} – ${formatShort(props.week.end)}`
        : null,
);

function goToWeek(week: string): void {
    router.get(
        '/timetable',
        { week },
        { preserveState: true, preserveScroll: true },
    );
}

function navigate(offsetDays: number): void {
    if (!props.week) {
        return;
    }

    const date = new Date(`${props.week.start}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + offsetDays);
    goToWeek(date.toISOString().slice(0, 10));
}

function goToCurrentWeek(): void {
    if (props.today) {
        goToWeek(props.today);
    }
}

/** A data de cada dia da semana consultada, para o cabeçalho da coluna. */
function dateOfDay(dayOfWeek: number): string | null {
    if (!props.week) {
        return null;
    }

    const date = new Date(`${props.week.start}T12:00:00Z`);
    date.setUTCDate(date.getUTCDate() + dayOfWeek - 1);

    return formatShort(date.toISOString().slice(0, 10));
}

/**
 * Written out rather than derived from Intl: a recurring slot has a weekday
 * and no date, so there is no day to format. Lower case, as Portuguese writes
 * them — the first letter is raised on the string itself (capitalizeFirst),
 * never by the CSS `capitalize` class, which would also raise the half after
 * the hyphen.
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

/** Os cinco dias úteis, sempre, e por esta ordem. */
const weekdayColumns = [1, 2, 3, 4, 5];

/** Fim de semana: raro, e por isso nunca uma coluna reservada de antemão. */
const weekendDaysOfWeek = [6, 7];

/**
 * O QUE SE DESENHA NA GRELHA: os tempos de aula e as atividades não letivas do
 * professor, juntos e por hora de início. Duas coisas diferentes na mesma
 * coluna — a aula leva o tom da turma, a atividade é neutra e tracejada.
 */
type DayItem =
    | { key: string; startsAt: string; slot: TimetableSlot }
    | { key: string; startsAt: string; activity: TimetableActivity };

const itemsByDay = computed(() => {
    const grouped = new Map<number, DayItem[]>();

    const items: Array<[number, DayItem]> = [
        ...props.slots.map((slot): [number, DayItem] => [
            slot.day_of_week,
            { key: `slot-${slot.ulid}`, startsAt: slot.starts_at, slot },
        ]),
        ...props.activities.map((activity): [number, DayItem] => [
            activity.day_of_week,
            {
                key: `activity-${activity.ulid}`,
                startsAt: activity.starts_at,
                activity,
            },
        ]),
    ];

    for (const [dayOfWeek, item] of items) {
        grouped.set(dayOfWeek, [...(grouped.get(dayOfWeek) ?? []), item]);
    }

    for (const dayItems of grouped.values()) {
        dayItems.sort((a, b) => a.startsAt.localeCompare(b.startsAt));
    }

    return grouped;
});

/**
 * NADA É CRIADO AQUI. Um dia sem aulas é um rótulo desenhado e mais nada: não
 * há bloco nenhum por trás dele, nem passa a haver por ser mostrado.
 */
function dayColumn(dayOfWeek: number) {
    const name = capitalizeFirst(weekdays[dayOfWeek - 1] ?? `Dia ${dayOfWeek}`);
    const date = dateOfDay(dayOfWeek);

    return {
        dayOfWeek,
        label: date ? `${name}, ${date}` : name,
        items: itemsByDay.value.get(dayOfWeek) ?? [],
    };
}

/**
 * Em ecrã largo a semana é uma semana: cinco colunas fixas, de segunda a
 * sexta, sempre pela mesma ordem e sempre nas mesmas posições. Um número de
 * colunas que acompanhasse a largura fazia a sexta-feira «cair" para a linha
 * seguinte só porque a quinta estava vazia — e uma sexta-feira debaixo da
 * segunda não é a semana que o professor tem na cabeça. Por isso o dia vazio
 * fica cá, dito por escrito («Sem aulas»), a segurar a sua coluna.
 */
const desktopDays = computed(() =>
    weekdayColumns.map((dayOfWeek) => dayColumn(dayOfWeek)),
);

/**
 * Sábado e domingo não têm coluna garantida — teriam de a ter vazia em todas
 * as semanas de todos os professores para servir os poucos que lá dão aulas.
 * Quando existem mesmo, aparecem numa linha própria por baixo dos dias úteis,
 * alinhados pelas mesmas colunas.
 */
const weekendDays = computed(() =>
    weekendDaysOfWeek
        .filter((dayOfWeek) => itemsByDay.value.has(dayOfWeek))
        .map((dayOfWeek) => dayColumn(dayOfWeek)),
);

/**
 * Em ecrã estreito, só os dias em que o professor dá mesmo aulas. Não há
 * semana nenhuma para ler de uma vez num telemóvel — há uma lista — e cinco
 * cartões «Sem aulas» empilhados seriam ecrã gasto a dizer que não há nada.
 */
const days = computed(() =>
    [...itemsByDay.value.keys()]
        .sort((a, b) => a - b)
        .map((dayOfWeek) => dayColumn(dayOfWeek)),
);

const noItems = computed(
    () => props.slots.length === 0 && props.activities.length === 0,
);

const summary = computed(() => {
    const blocks = props.slots.length;
    const classCount = new Set(
        props.slots.map((slot) => slot.school_class.ulid),
    ).size;

    const base = `${blocks} ${blocks === 1 ? 'aula' : 'aulas'} por semana · ${classCount} ${classCount === 1 ? 'turma' : 'turmas'}`;
    const extra = props.activities.length;

    // As atividades não letivas não são aulas: dizem-se à parte.
    return extra > 0
        ? `${base} · ${extra} ${extra === 1 ? 'atividade não letiva' : 'atividades não letivas'}`
        : base;
});

// ------------------------------------------------------ corrigir o horário

const slotDialogOpen = ref(false);
const editingSlot = ref<TimetableSlot | null>(null);
const activityDialogOpen = ref(false);
const editingActivity = ref<TimetableActivity | null>(null);

function addLesson(): void {
    editingSlot.value = null;
    slotDialogOpen.value = true;
}

function editSlot(slot: TimetableSlot): void {
    editingSlot.value = slot;
    slotDialogOpen.value = true;
}

function addActivity(): void {
    editingActivity.value = null;
    activityDialogOpen.value = true;
}

function editActivity(activity: TimetableActivity): void {
    editingActivity.value = activity;
    activityDialogOpen.value = true;
}

function selectItem(item: DayItem): void {
    if ('slot' in item) {
        editSlot(item.slot);

        return;
    }

    editActivity(item.activity);
}

function slotOf(item: DayItem): TimetableSlot | undefined {
    return 'slot' in item ? item.slot : undefined;
}

function activityOf(item: DayItem): TimetableActivity | undefined {
    return 'activity' in item ? item.activity : undefined;
}

// ------------------------------------------------------ o tom de cada turma

/**
 * O TOM DE CADA TURMA É O GUARDADO (0.158.0): `class_teachers.identity_tone`,
 * atribuído uma vez a este professor e nunca recalculado — a mesma turma tem o
 * mesmo tom aqui e em Aulas e Sumários, e acrescentar, arquivar ou filtrar
 * turmas não muda o de nenhuma. Só uma relação sem tom guardado cai no tom de
 * recurso, decidido sobre o conjunto de turmas visíveis (`resolveTurmaTones`).
 *
 * Lido uma vez por renderização nos três sítios onde a semana se desenha
 * (grelha, fim de semana e agenda), que dizem por isso sempre o mesmo.
 */
const turmaTones = computed(() =>
    resolveTurmaTones(
        props.slots.map((slot) => ({
            ulid: slot.school_class.ulid,
            identity_tone:
                slot.school_class.identity_tone ??
                props.classes.find(
                    (option) => option.ulid === slot.school_class.ulid,
                )?.identity_tone ??
                null,
        })),
    ),
);

function toneOf(ulid: string): TurmaTone {
    return turmaTones.value.get(ulid) ?? (TURMA_TONES[0] as TurmaTone);
}

/** A barra na margem do bloco desta turma. */
function barOf(ulid: string): string {
    return turmaBarClass(toneOf(ulid));
}

/** A cápsula à volta do nome desta turma. */
function badgeOf(ulid: string): string {
    return turmaBadgeClass(toneOf(ulid));
}
</script>

<template>
    <Head title="Horário do Professor" />

    <main class="mx-auto w-full max-w-6xl space-y-8 p-4 pb-24 sm:p-6">
        <ScheduleChangeNotice />

        <div class="space-y-4">
            <Heading
                title="Horário do Professor"
                :description="
                    !noItems
                        ? `A tua semana, de todas as turmas juntas — ${summary}.`
                        : 'A tua semana, de todas as turmas juntas.'
                "
            />

            <nav
                v-if="week"
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
                    :disabled="week.is_current"
                    @click="goToCurrentWeek"
                    ><CalendarDays class="size-4" /> Semana atual</Button
                >
                <Button
                    variant="outline"
                    size="sm"
                    class="min-h-10"
                    @click="navigate(7)"
                    >Semana seguinte <ChevronRight class="size-4"
                /></Button>
                <p
                    class="w-full text-sm text-muted-foreground sm:ml-auto sm:w-auto"
                    aria-live="polite"
                >
                    Semana de {{ weekLabel
                    }}<span v-if="week.is_current"> · semana atual</span>
                </p>
            </nav>
        </div>

        <!--
            É AQUI QUE O HORÁRIO SE CORRIGE (0.168.0). Uma importação raramente
            sai perfeita: clica numa aula para mudar a turma, o dia, as horas ou
            a vigência, ou elimina-a; acrescenta as aulas que faltam e as
            atividades que não são aulas. Tudo isto fala com as mesmas rotas do
            editor da turma — o servidor decide o que é seguro mudar.
        -->
        <section class="space-y-2" aria-label="Corrigir o horário">
            <div class="flex flex-wrap gap-2">
                <Button
                    type="button"
                    class="min-h-10"
                    data-add-lesson
                    :disabled="classes.length === 0"
                    @click="addLesson"
                    ><Plus class="size-4" /> Adicionar aula</Button
                >
                <Button
                    type="button"
                    variant="outline"
                    class="min-h-10"
                    data-add-activity
                    @click="addActivity"
                    ><Plus class="size-4" /> Adicionar atividade não
                    letiva</Button
                >
            </div>
            <p class="text-sm text-muted-foreground">
                Clica num bloco para o corrigir. As atividades não letivas
                (direção de turma, apoios, reuniões) ficam no horário mas não
                geram aulas nem sumários.
            </p>
        </section>

        <!--
            NOTHING IS CREATED BY OPENING ESTA PÁGINA. Ao contrário da vista
            semanal de «Aulas e Sumários», que materializa as aulas da semana
            que mostra, um horário é a regra e não as suas ocorrências: aqui só
            se lê o que já existe.
        -->
        <EmptyState
            v-if="noItems && week && !week.is_current"
            title="Sem aulas nesta semana"
            description="Nenhum bloco do teu horário estava em vigor nesta semana para turmas ativas."
            :icon="CalendarX2"
        />
        <EmptyState
            v-else-if="noItems"
            title="Ainda não tens horário configurado"
            description="Assim que as aulas recorrentes das tuas turmas estiverem definidas, a tua semana aparece aqui. Podes importar o PDF do horário da escola ou definir os blocos à mão, turma a turma."
            :icon="CalendarX2"
        />

        <!--
            A MESMA SEMANA, DUAS LEITURAS, como a grelha do mês já faz: em ecrã
            largo cinco colunas fixas de segunda a sexta; em ecrã estreito uma
            agenda só com os dias que têm mesmo aulas.
        -->
        <template v-else>
            <section
                class="hidden space-y-4 lg:block"
                aria-label="Horário semanal"
            >
                <!--
                    CINCO COLUNAS, SEMPRE. Nunca um número de colunas que
                    acompanhe a largura: era isso que empurrava a sexta-feira
                    para a linha de baixo quando a quinta não tinha aulas.
                -->
                <div class="grid grid-cols-5 gap-4">
                    <section
                        v-for="day in desktopDays"
                        :key="day.dayOfWeek"
                        class="space-y-2"
                        :aria-label="day.label"
                    >
                        <h2 class="text-sm font-semibold">{{ day.label }}</h2>
                        <!--
                            O TOM DA TURMA, nos mesmos dois sítios em todas as
                            leituras da página: uma barra fina na margem do
                            bloco e uma cápsula à volta do nome da turma. Sai do
                            `ulid` da turma e do conjunto de `ulid`s que esta
                            página mostra (turmaTones/barOf/badgeOf, acima), e
                            por isso não depende da ordem em que os cartões
                            calham sair nem repete uma cor enquanto houver
                            alguma por usar. A hora, a disciplina e o fundo do
                            bloco ficam tão neutros como sempre foram — e o dia
                            sem aulas, que não tem turma nenhuma, não leva tom.
                        -->
                        <ul
                            v-if="day.items.length"
                            class="divide-y rounded-xl border bg-card"
                        >
                            <li v-for="item in day.items" :key="item.key">
                                <TimetableBlock
                                    :lesson-slot="slotOf(item)"
                                    :activity="activityOf(item)"
                                    :bar-class="
                                        'slot' in item
                                            ? barOf(item.slot.school_class.ulid)
                                            : undefined
                                    "
                                    :badge-class="
                                        'slot' in item
                                            ? badgeOf(
                                                  item.slot.school_class.ulid,
                                              )
                                            : undefined
                                    "
                                    @select="selectItem(item)"
                                />
                            </li>
                        </ul>
                        <p
                            v-else
                            class="rounded-xl border bg-card p-3 text-sm text-muted-foreground"
                        >
                            Sem aulas
                        </p>
                    </section>
                </div>

                <!--
                    Sábado e domingo, só se existirem, e numa linha à parte: a
                    garantia de cinco colunas é sobre os dias úteis.
                -->
                <div v-if="weekendDays.length" class="grid grid-cols-5 gap-4">
                    <section
                        v-for="day in weekendDays"
                        :key="day.dayOfWeek"
                        class="space-y-2"
                        :aria-label="day.label"
                    >
                        <h2 class="text-sm font-semibold">{{ day.label }}</h2>
                        <ul class="divide-y rounded-xl border bg-card">
                            <li v-for="item in day.items" :key="item.key">
                                <TimetableBlock
                                    :lesson-slot="slotOf(item)"
                                    :activity="activityOf(item)"
                                    :bar-class="
                                        'slot' in item
                                            ? barOf(item.slot.school_class.ulid)
                                            : undefined
                                    "
                                    :badge-class="
                                        'slot' in item
                                            ? badgeOf(
                                                  item.slot.school_class.ulid,
                                              )
                                            : undefined
                                    "
                                    @select="selectItem(item)"
                                />
                            </li>
                        </ul>
                    </section>
                </div>
            </section>

            <!-- A mesma semana em ecrã estreito, lida como agenda. -->
            <section class="space-y-4 lg:hidden" aria-label="Agenda da semana">
                <section
                    v-for="day in days"
                    :key="day.dayOfWeek"
                    class="space-y-2"
                    :aria-label="day.label"
                >
                    <h2 class="text-sm font-semibold">{{ day.label }}</h2>
                    <ul class="divide-y rounded-xl border bg-card">
                        <li v-for="item in day.items" :key="item.key">
                            <TimetableBlock
                                :lesson-slot="slotOf(item)"
                                :activity="activityOf(item)"
                                :bar-class="
                                    'slot' in item
                                        ? barOf(item.slot.school_class.ulid)
                                        : undefined
                                "
                                :badge-class="
                                    'slot' in item
                                        ? badgeOf(item.slot.school_class.ulid)
                                        : undefined
                                "
                                @select="selectItem(item)"
                            />
                        </li>
                    </ul>
                </section>
            </section>
        </template>

        <!--
            OS MESMOS DOIS CAMINHOS que «Configurar horários» já oferece, e as
            mesmas rotas: o importador de PDF e a edição manual na página de
            cada turma. Nenhum dos dois mudou — o que é novo é poderem ser
            alcançados a partir do sítio onde o horário se lê.
        -->
        <section class="space-y-3" aria-labelledby="timetable-setup-heading">
            <div>
                <h2
                    id="timetable-setup-heading"
                    class="text-base font-semibold"
                >
                    Configurar o horário
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    Duas formas de preencher o horário das tuas turmas — escolhe
                    a que for mais rápida.
                </p>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <section
                    class="flex flex-col gap-3 rounded-lg border border-border p-4"
                >
                    <div>
                        <h3 class="text-sm font-semibold">
                            Importar horário do professor
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Carrega o PDF do teu horário e as aulas ficam
                            associadas às turmas certas, prontas a rever antes
                            de guardar.
                        </p>
                    </div>
                    <Button as-child class="mt-auto self-start">
                        <Link href="/timetable-imports/create"
                            ><FileUp class="size-4" /> Importar PDF</Link
                        >
                    </Button>
                </section>

                <section
                    class="flex flex-col gap-3 rounded-lg border border-border p-4"
                >
                    <div>
                        <h3 class="text-sm font-semibold">
                            Configurar manualmente
                        </h3>
                        <p class="mt-1 text-sm text-muted-foreground">
                            Define os dias e horas de cada turma diretamente na
                            sua página.
                        </p>
                    </div>

                    <EmptyState
                        v-if="classes.length === 0"
                        title="Ainda não existem turmas para configurar."
                    >
                        <template #action>
                            <Button as-child>
                                <Link href="/classes/create"
                                    ><Plus class="size-4" /> Nova turma</Link
                                >
                            </Button>
                        </template>
                    </EmptyState>

                    <div v-else class="space-y-2">
                        <Link
                            v-for="schoolClass in classes"
                            :key="schoolClass.ulid"
                            :href="`/classes/${schoolClass.ulid}#horario`"
                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 rounded-md border border-border px-3 py-2 text-sm transition-colors hover:border-primary/40"
                        >
                            <span>
                                <span class="font-medium">{{
                                    schoolClass.label
                                }}</span>
                                <span class="text-muted-foreground">
                                    · {{ schoolClass.subject }}</span
                                >
                            </span>
                            <span
                                class="flex shrink-0 items-center gap-1.5 text-muted-foreground"
                            >
                                <CalendarClock class="size-4" /> Configurar
                                horário
                            </span>
                        </Link>
                    </div>
                </section>
            </div>
        </section>

        <TimetableSlotDialog
            v-model:open="slotDialogOpen"
            :lesson-slot="editingSlot"
            :classes="classes"
        />
        <TimetableActivityDialog
            v-model:open="activityDialogOpen"
            :activity="editingActivity"
            :kinds="activityKinds"
            :classes="classes"
        />
    </main>
</template>
