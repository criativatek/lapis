<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

/**
 * `new` · `exists` · `correspondence` · `changed` · `conflict` · `needs_choice`
 * · `out_of_year` — todos decididos no servidor, e todos voltados a decidir lá
 * na confirmação. O que esta página faz com eles é explicá-los e impedir que se
 * marque o que não se pode gravar; nunca é ela a garantia.
 *
 * `correspondence` SÓ APARECE EM FERIADOS E INTERRUPÇÕES: é o mesmo dia já
 * gravado com outro nome. Os períodos não o têm — a designação de um período é a
 * sua chave de emparelhamento, e um período com o mesmo nome e datas diferentes
 * continua a ser `changed`, exatamente como sempre foi.
 */
type State =
    | 'new'
    | 'exists'
    | 'correspondence'
    | 'changed'
    | 'conflict'
    | 'needs_choice'
    | 'out_of_year';

type EndCandidate = {
    value: string;
    cohort: string | null;
    raw_text: string | null;
    in_year: boolean;
    keep_current: boolean;
};

type CurrentPeriod = {
    ulid: string;
    label: string;
    kind_label: string;
    starts_on: string;
    ends_on: string;
};

type SemesterProposal = {
    key: string;
    label: string;
    kind: string;
    sequence: number;
    starts_on: string | null;
    raw_start: string;
    ends_on: string | null;
    end_candidates: EndCandidate[];
    state: State;
    current: CurrentPeriod | null;
    include: boolean;
};

type CurrentException = {
    ulid: string;
    type_label: string;
    title: string;
    starts_on: string;
    ends_on: string;
    source_label: string;
};

/**
 * PARA ONDE A LINHA VAI, que é a única diferença que resta entre uma data e
 * outra: `academic_calendar_exception` é a estrutura do ano — um dia em que NÃO
 * HÁ AULA —, `calendar_event` é o calendário do professor, que não retira aula
 * nenhuma a ninguém.
 */
type Destination = 'academic_calendar_exception' | 'calendar_event';

/**
 * Uma data com nome lida do documento — feriado, interrupção, reunião, atividade
 * ou uma data que o documento marca sem dizer o que é.
 *
 * UM TIPO SÓ PARA AS DUAS ESPÉCIES, e é isso que o ecrã passou a mostrar: eram
 * dois («feriados» e «outros acontecimentos») quando tudo o que o documento
 * marcasse era escrito como feriado. `destination` diz para onde vai e
 * `type_label` diz o que é; nenhuma linha volta a dizer «Feriado» por o servidor
 * não ter sabido responder.
 *
 * `type_short_label` só existe nas exceções e `explanation` só nos
 * acontecimentos — cada um vem de um lado do servidor e nenhum dos dois se
 * inventa aqui quando falta.
 */
type DatedItem = {
    key: string;
    destination: Destination;
    type: string;
    type_label: string;
    type_short_label?: string;
    title: string;
    starts_on: string;
    ends_on: string;
    note: string | null;
    raw_text: string;
    explanation?: string;
    state: State;
    current: CurrentException | null;
    include: boolean;
};

const props = defineProps<{
    semesters: SemesterProposal[];
    schoolBreaks: DatedItem[];
    datedItems: DatedItem[];
    counts: Record<string, number>;
    academicYear: {
        ulid: string;
        label: string;
        starts_on: string;
        ends_on: string;
    };
    schoolName: string | null;
    fileAcademicYear: string | null;
    yearMismatch: boolean;
}>();

const stateLabels: Record<State, string> = {
    new: 'Novo',
    exists: 'Já existe',
    correspondence: 'Designação diferente',
    changed: 'Alterado',
    conflict: 'Conflito',
    needs_choice: 'Requer escolha',
    out_of_year: 'Fora do ano',
};

const form = useForm({
    academic_year_ulid: props.academicYear.ulid,
    semesters: props.semesters.map((semester) => ({
        include: semester.include,
        // O ulid do período que já existe com este nome, quando existe: é ele que
        // faz a diferença entre ATUALIZAR o semestre que lá está e criar um
        // segundo com o mesmo nome ao lado do primeiro.
        ulid: semester.current?.ulid ?? null,
        label: semester.label,
        kind: semester.kind,
        sequence: semester.sequence,
        starts_on: semester.starts_on,
        // NULO quando o documento dá mais do que uma data de fim, e assim fica
        // até alguém escolher. Nenhuma das três vem pré-escolhida.
        ends_on: semester.ends_on,
    })),
    breaks: props.schoolBreaks.map((row) => ({
        include: row.include,
        destination: row.destination,
        type: row.type,
        title: row.title,
        starts_on: row.starts_on,
        ends_on: row.ends_on,
        note: row.note,
    })),
    datedItems: props.datedItems.map((row) => ({
        include: row.include,
        destination: row.destination,
        type: row.type,
        title: row.title,
        starts_on: row.starts_on,
        ends_on: row.ends_on,
        note: row.note,
    })),
});

/**
 * UMA SECÇÃO PARA QUEM LÊ, DUAS LISTAS PARA QUEM GRAVA.
 *
 * «Datas e eventos escolares» é uma lista só porque é assim que um calendário se
 * lê — por data, e não por tabela de destino. Mas as duas espécies gravam-se em
 * sítios diferentes e sob validações diferentes, e por isso separam-se aqui, no
 * último momento, por `destination` — que veio do servidor e não de um palpite
 * desta página.
 */
form.transform((data) => ({
    academic_year_ulid: data.academic_year_ulid,
    semesters: data.semesters,
    exceptions: [
        ...data.breaks,
        ...data.datedItems.filter(
            (row) => row.destination === 'academic_calendar_exception',
        ),
    ],
    events: data.datedItems.filter(
        (row) => row.destination === 'calendar_event',
    ),
}));

/**
 * Em que posição da lista submetida é que cada data desta secção vai parar — o
 * que é preciso para pôr o erro do servidor debaixo da linha CERTA.
 *
 * As interrupções ocupam o início de `exceptions`, e por isso as exceções desta
 * secção começam a contar depois delas. Os acontecimentos têm lista própria e
 * contam do zero.
 */
const submittedIndexes = computed(() => {
    let exceptionIndex = form.breaks.length;
    let eventIndex = 0;

    return props.datedItems.map((row) =>
        row.destination === 'academic_calendar_exception'
            ? exceptionIndex++
            : eventIndex++,
    );
});

const errors = computed(
    () => form.errors as unknown as Record<string, string | undefined>,
);

function semesterError(index: number): string | undefined {
    return (
        errors.value[`semesters.${index}.ends_on`] ??
        errors.value[`semesters.${index}.starts_on`] ??
        errors.value[`semesters.${index}.ulid`]
    );
}

function breakError(index: number): string | undefined {
    return (
        errors.value[`exceptions.${index}.ends_on`] ??
        errors.value[`exceptions.${index}.starts_on`]
    );
}

function datedItemError(index: number): string | undefined {
    const row = props.datedItems[index];
    const group =
        row.destination === 'academic_calendar_exception'
            ? 'exceptions'
            : 'events';
    const at = submittedIndexes.value[index];

    return (
        errors.value[`${group}.${at}.ends_on`] ??
        errors.value[`${group}.${at}.starts_on`]
    );
}

/**
 * Uma linha que já existe tal e qual, ou que cai fora do ano letivo, não tem nada
 * para fazer e não se pode marcar. Uma linha que exige escolha só se pode marcar
 * DEPOIS de a escolha estar feita — é este o bloqueio de que o §32 fala, e é aqui
 * que ele se vê; o servidor recusa-a na mesma, e é lá que ele vale.
 */
function selectable(state: State, endsOn: string | null): boolean {
    if (state === 'exists' || state === 'out_of_year') {
        return false;
    }

    return state !== 'needs_choice' || endsOn !== null;
}

const dateFormatter = new Intl.DateTimeFormat('pt-PT', {
    day: 'numeric',
    month: 'long',
    year: 'numeric',
});

function longDate(date: string | null): string {
    if (!date) {
        return '—';
    }

    return dateFormatter.format(new Date(`${date}T00:00:00`));
}

function range(startsOn: string, endsOn: string): string {
    return startsOn === endsOn
        ? longDate(startsOn)
        : `${longDate(startsOn)} – ${longDate(endsOn)}`;
}

/**
 * O QUE A CAIXA DAQUELA LINHA FAZ, dito por palavras.
 *
 * Numa linha nova é «cria». Numa linha de designação diferente não há nada para
 * criar — o dia já lá está — e por isso ela quer dizer OUTRA coisa: adotar o nome
 * do documento na linha que já existe. Duas ações diferentes atrás da mesma caixa
 * exigem que a caixa diga qual é, e não que o professor a descubra depois.
 */
function includeLabel(row: DatedItem): string {
    return row.state === 'correspondence'
        ? `Adotar a designação «${row.title}»`
        : `Incluir ${row.title}`;
}

function candidateLabel(candidate: EndCandidate): string {
    if (candidate.keep_current) {
        return `Manter a data atual · ${longDate(candidate.value)}`;
    }

    return candidate.cohort
        ? `${candidate.cohort} · ${longDate(candidate.value)}`
        : longDate(candidate.value);
}

const selectedCount = computed(
    () =>
        form.semesters.filter((row) => row.include).length +
        form.breaks.filter((row) => row.include).length +
        form.datedItems.filter((row) => row.include).length,
);

const unresolvedChoices = computed(
    () =>
        props.semesters.filter(
            (semester, index) =>
                semester.state === 'needs_choice' &&
                form.semesters[index].ends_on === null,
        ).length,
);

/**
 * «DATAS E EVENTOS ESCOLARES» E JÁ NÃO «FERIADOS».
 *
 * O título anterior era uma afirmação sobre coisas que a aplicação não tinha
 * verificado: o documento marca também reuniões, apresentações, atividades e
 * convívios, e todos apareciam aqui debaixo da palavra «Feriados» — que nesta
 * aplicação não é um rótulo, é a declaração de que naquele dia não há aula.
 * O texto de apoio diz agora o que a lista é de facto, e cada linha diz o que é.
 */
const sections = computed(() => [
    {
        key: 'breaks' as const,
        title: 'Interrupções letivas',
        description:
            'Cada uma é um intervalo em que não há aula. Vão para a estrutura do ano, ao lado dos períodos.',
        empty: 'O documento não traz nenhuma.',
        rows: props.schoolBreaks,
        model: form.breaks,
    },
    {
        key: 'dated' as const,
        title: 'Datas e eventos escolares',
        description:
            'Selecione as datas e eventos que pretende importar. Alguns correspondem a feriados; outros podem representar reuniões, atividades, apresentações ou outros acontecimentos do calendário escolar.',
        empty: 'O documento não marca nenhuma.',
        rows: props.datedItems,
        model: form.datedItems,
    },
]);

/** O erro daquela linha, seja qual for a lista em que ela vai ser gravada. */
function rowError(
    sectionKey: 'breaks' | 'dated',
    index: number,
): string | undefined {
    return sectionKey === 'breaks'
        ? breakError(index)
        : datedItemError(index);
}

function submit(): void {
    form.post('/academic-calendar-imports/confirm');
}
</script>

<template>
    <Head title="Confirmar importação do calendário" />

    <div class="mx-auto w-full max-w-4xl space-y-6 p-4">
        <Heading
            title="Confirmar importação do calendário"
            :description="
                schoolName
                    ? `Calendário de ${schoolName}. Revê cada linha — nada é gravado até confirmares.`
                    : 'Revê cada linha — nada é gravado até confirmares.'
            "
        />

        <div
            v-if="yearMismatch"
            class="flex gap-3 rounded-lg border border-amber-500/40 bg-amber-500/10 p-4 text-sm"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0 text-amber-600" />
            <p>
                O ficheiro parece ser de
                <span class="font-medium">{{ fileAcademicYear }}</span>
                , mas estás a importar para
                <span class="font-medium">{{ academicYear.label }}</span>
                . Podes continuar, se for mesmo isso que pretendes — o ano
                letivo selecionado não é alterado. As linhas cujas datas caiam
                fora deste ano aparecem como «Fora do ano» e não podem ser
                gravadas.
            </p>
        </div>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- ─────────────────────────────────── a estrutura do ano ────── -->
            <section
                v-if="props.semesters.length > 0"
                class="space-y-4 rounded-lg border border-border p-4"
            >
                <div>
                    <h2 class="text-base font-semibold">Estrutura do ano</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        As datas dos períodos, tal como o quadro do documento as
                        descreve. Vão para «Estrutura do Ano Letivo», onde já
                        vivem os períodos deste ano.
                    </p>
                </div>

                <div
                    v-for="(semester, index) in props.semesters"
                    :key="semester.key"
                    class="space-y-3 rounded-md border border-border/70 p-3"
                >
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <label class="flex min-w-0 items-start gap-2.5">
                            <input
                                v-model="form.semesters[index].include"
                                type="checkbox"
                                class="mt-1"
                                :disabled="
                                    !selectable(
                                        semester.state,
                                        form.semesters[index].ends_on,
                                    )
                                "
                                :aria-label="`Incluir ${semester.label}`"
                            />
                            <span class="min-w-0">
                                <span class="block font-medium">{{
                                    semester.label
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    No documento: {{ semester.raw_start }}
                                </span>
                            </span>
                        </label>
                        <Badge
                            :variant="
                                semester.state === 'new'
                                    ? 'secondary'
                                    : 'outline'
                            "
                        >
                            {{ stateLabels[semester.state] }}
                        </Badge>
                    </div>

                    <dl class="grid gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Início (documento)
                            </dt>
                            <dd>{{ longDate(semester.starts_on) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Fim (documento)
                            </dt>
                            <dd>
                                {{
                                    semester.state === 'needs_choice' &&
                                    form.semesters[index].ends_on === null
                                        ? 'Por escolher'
                                        : longDate(
                                              form.semesters[index].ends_on,
                                          )
                                }}
                            </dd>
                        </div>
                    </dl>

                    <!--
                        AS DUAS VERSÕES LADO A LADO, e nunca só a nova: aceitar
                        «alterado» é sobrescrever a estrutura de um ano, e isso
                        não se faz sem se ver o que lá estava.
                    -->
                    <div
                        v-if="semester.current"
                        class="rounded-md bg-muted/40 p-3 text-sm"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            Já existe neste ano letivo
                        </p>
                        <p class="mt-1">
                            {{ semester.current.label }} ·
                            {{
                                range(
                                    semester.current.starts_on,
                                    semester.current.ends_on,
                                )
                            }}
                        </p>
                    </div>

                    <!--
                        A ESCOLHA DAS TRÊS DATAS (§32). O documento diz que o 2.º
                        Semestre acaba a 4, a 11 e a 30 de junho — uma data por
                        coorte —, e um período tem UM `ends_on` e nenhuma noção de
                        ano de escolaridade. Não há heurística que acerte nisto, e
                        por isso não há nenhuma: as opções ficam todas por
                        escolher, e a linha não se pode marcar enquanto uma delas
                        não for escolhida.
                    -->
                    <fieldset
                        v-if="semester.end_candidates.length > 1"
                        class="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/5 p-3"
                    >
                        <legend class="px-1 text-xs font-medium">
                            O documento dá
                            {{ semester.end_candidates.length }} datas de fim
                            para este período. Escolhe uma.
                        </legend>
                        <p class="text-xs text-muted-foreground">
                            As datas são diferentes por ano de escolaridade.
                            Esta aplicação guarda uma só data de fim por
                            período, por isso a escolha é tua — nada é decidido
                            por aproximação.
                        </p>
                        <label
                            v-for="candidate in semester.end_candidates"
                            :key="`${semester.key}-${candidate.value}-${candidate.keep_current}`"
                            class="flex items-center gap-2.5 text-sm"
                        >
                            <input
                                v-model="form.semesters[index].ends_on"
                                type="radio"
                                :name="`${semester.key}-ends-on`"
                                :value="candidate.value"
                                :disabled="!candidate.in_year"
                            />
                            <span
                                :class="candidate.in_year ? '' : 'opacity-50'"
                            >
                                {{ candidateLabel(candidate) }}
                                <span
                                    v-if="candidate.raw_text"
                                    class="text-xs text-muted-foreground"
                                >
                                    · «{{ candidate.raw_text }}»
                                </span>
                                <span
                                    v-if="!candidate.in_year"
                                    class="text-xs text-muted-foreground"
                                >
                                    · fora do ano letivo
                                </span>
                            </span>
                        </label>
                    </fieldset>

                    <p
                        v-if="semester.state === 'out_of_year'"
                        class="text-xs text-muted-foreground"
                    >
                        Estas datas caem fora de {{ academicYear.label }} ({{
                            range(academicYear.starts_on, academicYear.ends_on)
                        }}), por isso esta linha não pode ser gravada.
                    </p>

                    <InputError :message="semesterError(index)" />
                </div>
            </section>

            <!-- ──────── interrupções letivas · datas e eventos escolares ──── -->
            <section
                v-for="section in sections"
                :key="section.key"
                class="space-y-3 rounded-lg border border-border p-4"
            >
                <div>
                    <h2 class="text-base font-semibold">{{ section.title }}</h2>
                    <p class="mt-1 text-xs text-muted-foreground">
                        {{ section.description }}
                    </p>
                </div>

                <p
                    v-if="section.rows.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ section.empty }}
                </p>

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="(row, index) in section.rows"
                        :key="row.key"
                        class="space-y-2 py-3"
                    >
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <label class="flex min-w-0 items-start gap-2.5">
                                <input
                                    v-model="section.model[index].include"
                                    type="checkbox"
                                    class="mt-1"
                                    :disabled="
                                        !selectable(row.state, row.ends_on)
                                    "
                                    :aria-label="includeLabel(row)"
                                />
                                <span class="min-w-0 text-sm">
                                    <span class="block font-medium">{{
                                        row.title
                                    }}</span>
                                    <span class="block text-muted-foreground">
                                        {{ range(row.starts_on, row.ends_on) }}
                                    </span>
                                    <span
                                        class="block text-xs text-muted-foreground"
                                    >
                                        No documento: «{{ row.raw_text }}» ·
                                        {{ row.type_label }}
                                    </span>
                                    <span
                                        v-if="row.note"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ row.note }}
                                    </span>
                                    <!--
                                        PORQUE É QUE ESTA DATA NÃO É UM DIA SEM
                                        AULA, dito na própria linha. Só as que
                                        vão para o calendário do professor a
                                        trazem, e é o servidor que a escreve —
                                        esta página não tem opinião sobre o
                                        assunto.
                                    -->
                                    <span
                                        v-if="row.explanation"
                                        class="block text-xs text-muted-foreground"
                                    >
                                        {{ row.explanation }}
                                    </span>
                                    <!--
                                        O QUE ESTA CAIXA FAZ, ao lado da caixa.
                                        Numa linha de designação diferente ela
                                        não cria nada — o dia já lá está — e por
                                        isso tem de dizer o que faz de facto.
                                    -->
                                    <span
                                        v-if="row.state === 'correspondence'"
                                        class="mt-1 block text-xs font-medium"
                                        :data-correspondence-hint="row.key"
                                    >
                                        Marcar muda a designação da linha que já
                                        existe para «{{ row.title }}». As datas,
                                        a observação e a proveniência ficam como
                                        estão; nenhuma linha nova é criada.
                                    </span>
                                </span>
                            </label>
                            <Badge
                                :variant="
                                    row.state === 'new'
                                        ? 'secondary'
                                        : 'outline'
                                "
                            >
                                {{ stateLabels[row.state] }}
                            </Badge>
                        </div>

                        <!--
                            AS DUAS VERSÕES LADO A LADO, tal como um período
                            «alterado» já as mostra — e aqui pela mesma razão:
                            uma escolha entre dois nomes só é uma escolha se os
                            dois nomes estiverem à vista ao mesmo tempo.
                        -->
                        <div
                            v-if="row.current"
                            class="rounded-md bg-muted/40 p-3 text-sm"
                            :data-current-exception="row.key"
                        >
                            <p
                                class="text-xs font-medium text-muted-foreground"
                            >
                                {{
                                    row.state === 'exists'
                                        ? 'Já está no calendário'
                                        : row.state === 'correspondence'
                                          ? 'O mesmo dia já está no calendário, com outra designação'
                                          : 'Sobrepõe-se ao que já está no calendário'
                                }}
                            </p>

                            <dl
                                v-if="row.state === 'correspondence'"
                                class="mt-2 grid gap-3 sm:grid-cols-2"
                            >
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Designação atual
                                    </dt>
                                    <dd class="font-medium">
                                        {{ row.current.title }}
                                    </dd>
                                    <dd class="text-xs text-muted-foreground">
                                        {{ row.current.source_label }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-xs text-muted-foreground">
                                        Designação do documento
                                    </dt>
                                    <dd class="font-medium">{{ row.title }}</dd>
                                    <dd class="text-xs text-muted-foreground">
                                        {{
                                            range(
                                                row.current.starts_on,
                                                row.current.ends_on,
                                            )
                                        }}
                                        — as mesmas datas
                                    </dd>
                                </div>
                            </dl>

                            <p v-else class="mt-1">
                                {{ row.current.title }} ·
                                {{
                                    range(
                                        row.current.starts_on,
                                        row.current.ends_on,
                                    )
                                }}
                                <span class="text-xs text-muted-foreground">
                                    · {{ row.current.source_label }}
                                </span>
                            </p>
                        </div>

                        <p
                            v-if="row.state === 'out_of_year'"
                            class="text-xs text-muted-foreground"
                        >
                            Estas datas caem fora de {{ academicYear.label }},
                            por isso esta linha não pode ser gravada.
                        </p>

                        <InputError :message="rowError(section.key, index)" />
                    </li>
                </ul>
            </section>

            <div
                class="space-y-3 rounded-lg border border-border bg-muted/20 p-4"
            >
                <p class="text-sm text-muted-foreground">
                    {{ counts.new }} nova(s) · {{ counts.exists }} já
                    existente(s) · {{ counts.correspondence }} com designação
                    diferente · {{ counts.changed }} alterada(s) ·
                    {{ counts.conflict }} em conflito ·
                    {{ counts.needs_choice }} a exigir escolha ·
                    {{ counts.out_of_year }} fora do ano
                </p>

                <p
                    v-if="unresolvedChoices > 0"
                    class="text-sm text-amber-700 dark:text-amber-500"
                >
                    Há {{ unresolvedChoices }} período(s) com a data de fim por
                    escolher. Escolhe uma das datas para os poderes incluir.
                </p>

                <InputError :message="errors.academic_year_ulid" />

                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        type="submit"
                        :disabled="form.processing || selectedCount === 0"
                    >
                        {{
                            form.processing
                                ? 'A importar…'
                                : `Importar ${selectedCount} linha(s)`
                        }}
                    </Button>
                    <Button as-child variant="ghost">
                        <Link href="/academic-calendar-imports/create">
                            <ArrowLeft class="size-4" /> Escolher outro ficheiro
                        </Link>
                    </Button>
                </div>
            </div>
        </form>
    </div>
</template>
