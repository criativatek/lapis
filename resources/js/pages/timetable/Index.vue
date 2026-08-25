<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CalendarClock, CalendarX2, FileUp, Plus } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { capitalizeFirst } from '@/lib/text';

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

/**
 * Only the days the teacher actually teaches on. An empty Saturday column is
 * not information, and dropping it keeps a real week — easily fifteen or
 * twenty blocks — legible instead of spreading it over seven columns to make
 * a grid look complete.
 */
const days = computed(() => {
    const grouped = new Map<number, TimetableSlot[]>();

    for (const slot of props.slots) {
        grouped.set(slot.day_of_week, [
            ...(grouped.get(slot.day_of_week) ?? []),
            slot,
        ]);
    }

    return [...grouped.entries()]
        .sort(([a], [b]) => a - b)
        .map(([dayOfWeek, slots]) => ({
            dayOfWeek,
            label: capitalizeFirst(weekdays[dayOfWeek - 1] ?? `Dia ${dayOfWeek}`),
            slots,
        }));
});

const summary = computed(() => {
    const blocks = props.slots.length;
    const classCount = new Set(props.slots.map((slot) => slot.school_class.ulid))
        .size;

    return `${blocks} ${blocks === 1 ? 'aula' : 'aulas'} por semana · ${classCount} ${classCount === 1 ? 'turma' : 'turmas'}`;
});

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
        <section
            v-if="days.length === 0"
            class="rounded-xl border border-dashed p-8 text-center"
            aria-labelledby="timetable-empty-heading"
        >
            <CalendarX2 class="mx-auto size-8 text-muted-foreground" />
            <h2 id="timetable-empty-heading" class="mt-3 font-semibold">
                Ainda não tens horário configurado
            </h2>
            <p class="mx-auto mt-1 max-w-xl text-sm text-muted-foreground">
                Assim que as aulas recorrentes das tuas turmas estiverem
                definidas, a tua semana aparece aqui. Podes importar o PDF do
                horário da escola ou definir os blocos à mão, turma a turma.
            </p>
        </section>

        <!--
            Uma coluna por dia: em ecrã largo lê-se como uma semana; em ecrã
            estreito as colunas empilham-se e ficam uma lista agrupada por dia,
            que é como a vista semanal já resolve o mesmo problema.
        -->
        <section
            v-else
            class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5"
            aria-label="Horário semanal"
        >
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
                        >
                            <time class="text-sm font-medium tabular-nums"
                                >{{ slot.starts_at }}–{{ slot.ends_at }}</time
                            >
                            <span class="text-sm font-medium">{{
                                slot.school_class.label
                            }}</span>
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

                    <div
                        v-if="classes.length === 0"
                        class="rounded-lg border border-dashed border-border p-6 text-center"
                    >
                        <p class="text-sm text-muted-foreground">
                            Ainda não existem turmas para configurar.
                        </p>
                        <Button as-child class="mt-3">
                            <Link href="/classes/create"
                                ><Plus class="size-4" /> Nova turma</Link
                            >
                        </Button>
                    </div>

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
