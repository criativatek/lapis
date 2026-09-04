<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarClock, CalendarX2, FileUp, Plus } from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';
import type { TurmaTone } from './timetable';
import {
    assignTurmaTones,
    TURMA_TONES,
    turmaBadgeClass,
    turmaBarClass,
} from './timetable';

type TimetableSlot = {
    ulid: string;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    starts_on: string | null;
    ends_on: string | null;
    school_class: { ulid: string; label: string };
    subject: string;
};

type ClassOption = {
    ulid: string;
    label: string;
    subject: string;
};

const props = defineProps<{
    slots: TimetableSlot[];
    classes: ClassOption[];
}>();

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

const slotsByDay = computed(() => {
    const grouped = new Map<number, TimetableSlot[]>();

    for (const slot of props.slots) {
        grouped.set(slot.day_of_week, [
            ...(grouped.get(slot.day_of_week) ?? []),
            slot,
        ]);
    }

    return grouped;
});

/**
 * NADA É CRIADO AQUI. Um dia sem aulas é um rótulo desenhado e mais nada: não
 * há bloco nenhum por trás dele, nem passa a haver por ser mostrado.
 */
function dayColumn(dayOfWeek: number) {
    return {
        dayOfWeek,
        label: capitalizeFirst(weekdays[dayOfWeek - 1] ?? `Dia ${dayOfWeek}`),
        slots: slotsByDay.value.get(dayOfWeek) ?? [],
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
        .filter((dayOfWeek) => slotsByDay.value.has(dayOfWeek))
        .map((dayOfWeek) => dayColumn(dayOfWeek)),
);

/**
 * Em ecrã estreito, só os dias em que o professor dá mesmo aulas. Não há
 * semana nenhuma para ler de uma vez num telemóvel — há uma lista — e cinco
 * cartões «Sem aulas» empilhados seriam ecrã gasto a dizer que não há nada.
 */
const days = computed(() =>
    [...slotsByDay.value.keys()]
        .sort((a, b) => a - b)
        .map((dayOfWeek) => dayColumn(dayOfWeek)),
);

const summary = computed(() => {
    const blocks = props.slots.length;
    const classCount = new Set(props.slots.map((slot) => slot.school_class.ulid))
        .size;

    return `${blocks} ${blocks === 1 ? 'aula' : 'aulas'} por semana · ${classCount} ${classCount === 1 ? 'turma' : 'turmas'}`;
});

// ------------------------------------------------------ o tom de cada turma

/**
 * OS TONS DESTA PÁGINA, DECIDIDOS SOBRE AS TURMAS DESTA PÁGINA. As turmas
 * visíveis são as que têm mesmo blocos em `props.slots` — uma turma sem aula
 * nenhuma não está aqui e não entra na repartição — e é o conjunto delas, e não
 * cada `ulid` isolado, que decide os tons: enquanto forem seis ou menos, cada
 * uma leva um tom só seu.
 *
 * A conta é feita uma vez por renderização e lida nos três sítios onde a semana
 * se desenha (grelha, fim de semana e agenda), pelo que as três dizem sempre o
 * mesmo sobre a mesma turma.
 */
const turmaTones = computed(() =>
    assignTurmaTones(props.slots.map((slot) => slot.school_class.ulid)),
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

function validity(slot: TimetableSlot): string | null {
    if (!slot.starts_on && !slot.ends_on) {
        return null;
    }

    return `Vigência: ${slot.starts_on ?? 'início do ano'} a ${slot.ends_on ?? 'fim do ano'}`;
}
</script>

<template>
    <Head title="Horário do Professor" />

    <main class="mx-auto w-full max-w-6xl space-y-8 p-4 pb-24 sm:p-6">
        <Heading
            title="Horário do Professor"
            :description="
                slots.length
                    ? `A tua semana, de todas as turmas juntas — ${summary}.`
                    : 'A tua semana, de todas as turmas juntas.'
            "
        />

        <!--
            NOTHING IS CREATED BY OPENING ESTA PÁGINA. Ao contrário da vista
            semanal de «Aulas e Sumários», que materializa as aulas da semana
            que mostra, um horário é a regra e não as suas ocorrências: aqui só
            se lê o que já existe.
        -->
        <EmptyState
            v-if="slots.length === 0"
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
                            v-if="day.slots.length"
                            class="divide-y rounded-xl border bg-card"
                        >
                            <li v-for="slot in day.slots" :key="slot.ulid">
                                <Link
                                    :href="`/classes/${slot.school_class.ulid}#horario`"
                                    class="flex flex-col gap-1 p-3 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="barOf(slot.school_class.ulid)"
                                >
                                    <time
                                        class="text-sm font-medium tabular-nums"
                                        >{{ slot.starts_at }}–{{
                                            slot.ends_at
                                        }}</time
                                    >
                                    <span
                                        class="text-sm font-medium"
                                        :class="badgeOf(slot.school_class.ulid)"
                                        >{{ slot.school_class.label }}</span
                                    >
                                    <span
                                        class="text-sm text-muted-foreground"
                                        >{{ slot.subject }}</span
                                    >
                                    <span
                                        v-if="validity(slot)"
                                        class="text-xs text-muted-foreground"
                                        >{{ validity(slot) }}</span
                                    >
                                </Link>
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
                            <li v-for="slot in day.slots" :key="slot.ulid">
                                <Link
                                    :href="`/classes/${slot.school_class.ulid}#horario`"
                                    class="flex flex-col gap-1 p-3 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                                    :class="barOf(slot.school_class.ulid)"
                                >
                                    <time
                                        class="text-sm font-medium tabular-nums"
                                        >{{ slot.starts_at }}–{{
                                            slot.ends_at
                                        }}</time
                                    >
                                    <span
                                        class="text-sm font-medium"
                                        :class="badgeOf(slot.school_class.ulid)"
                                        >{{ slot.school_class.label }}</span
                                    >
                                    <span
                                        class="text-sm text-muted-foreground"
                                        >{{ slot.subject }}</span
                                    >
                                    <span
                                        v-if="validity(slot)"
                                        class="text-xs text-muted-foreground"
                                        >{{ validity(slot) }}</span
                                    >
                                </Link>
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
                        <li v-for="slot in day.slots" :key="slot.ulid">
                            <Link
                                :href="`/classes/${slot.school_class.ulid}#horario`"
                                class="flex flex-col gap-1 p-3 transition-colors outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring"
                                :class="barOf(slot.school_class.ulid)"
                            >
                                <time class="text-sm font-medium tabular-nums"
                                    >{{ slot.starts_at }}–{{
                                        slot.ends_at
                                    }}</time
                                >
                                <span
                                    class="text-sm font-medium"
                                    :class="badgeOf(slot.school_class.ulid)"
                                    >{{ slot.school_class.label }}</span
                                >
                                <span class="text-sm text-muted-foreground">{{
                                    slot.subject
                                }}</span>
                                <span
                                    v-if="validity(slot)"
                                    class="text-xs text-muted-foreground"
                                    >{{ validity(slot) }}</span
                                >
                            </Link>
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
                <h2 id="timetable-setup-heading" class="text-base font-semibold">
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

                    <EmptyState v-if="classes.length === 0" title="Ainda não existem turmas para configurar.">
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
    </main>
</template>
