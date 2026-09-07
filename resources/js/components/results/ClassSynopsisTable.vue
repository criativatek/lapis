<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronDown, ChevronRight, CircleAlert, Camera } from '@lucide/vue';
import { computed, ref } from 'vue';
import { CONTINUOUS, CONTINUOUS_EXPLANATION } from '@/lib/readings';
import {
    appreciationClasses,
    appreciationTitle,
    percent,
    trendGlyph,
    trendTitle,
    trendTone
    
    
    
    
    
    
    
    
} from '@/lib/synopsis';
import type {
    ScaleBand,
    Synopsis,
    SynopticDomainCell,
    SynopticElement,
    SynopticElementResult,
    SynopticMoment,
    SynopticReadingRow,
    SynopticStudent,
} from '@/lib/synopsis';

/**
 * O QUADRO SÍNTESE AO LONGO DO ANO — momentos, domínios, elementos.
 *
 * TRÊS NÍVEIS, E POR OMISSÃO SÓ O PRIMEIRO (§12). Uma turma de trinta alunos com
 * cinco domínios, quatro momentos e vinte elementos são milhares de células; uma
 * grelha que as abrisse todas de uma vez não é uma grelha, é um muro. O que se
 * vê ao chegar é a apreciação vigente de cada aluno em cada momento e a média
 * contínua ao fim; os domínios e os elementos abrem-se quando alguém os quer.
 *
 * A DISTINÇÃO ENTRE FOTOGRAFIA E MOMENTO FORMAL ESTÁ ESCRITA, e não só pintada
 * (§9, §25): o cabeçalho de um momento intercalar leva o ícone de máquina
 * fotográfica E a palavra no `title`, e a coluna da avaliação contínua diz por
 * baixo quais as unidades que a compõem.
 *
 * ESTA GRELHA NÃO É UM RELATÓRIO DO ALUNO (§14). O nome de cada aluno é uma
 * ligação para o Relatório que já existe; o que aqui se lê é a turma.
 */

const props = defineProps<{
    classUlid: string;
    scaleBands: ScaleBand[];
    canViewStudentProgress: boolean;
    showQuantitative: boolean;
    synopsis: Synopsis;
}>();

// ------------------------------------------------------------------- filtros

const query = ref('');
const expandedMoments = ref<Set<string>>(new Set());
const expandedStudents = ref<Set<number>>(new Set());

const students = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase('pt-PT');

    if (needle === '') {
        return props.synopsis.students;
    }

    // Procura pelo nome E pelo número: numa pauta chama-se tanto por um como
    // pelo outro, e uma caixa que só aceitasse um deles falharia metade das
    // vezes que alguém a usa.
    return props.synopsis.students.filter(
        (student) =>
            student.name.toLocaleLowerCase('pt-PT').includes(needle) ||
            String(student.class_number ?? '').includes(needle),
    );
});

function toggleMoment(key: string): void {
    const next = new Set(expandedMoments.value);

    if (next.has(key)) {
        next.delete(key);
    } else {
        next.add(key);
    }

    expandedMoments.value = next;
}

function toggleStudent(enrollmentId: number): void {
    const next = new Set(expandedStudents.value);

    if (next.has(enrollmentId)) {
        next.delete(enrollmentId);
    } else {
        next.add(enrollmentId);
    }

    expandedStudents.value = next;
}

const isMomentOpen = (key: string): boolean => expandedMoments.value.has(key);
const isStudentOpen = (id: number): boolean => expandedStudents.value.has(id);

/** Quantas colunas um momento ocupa: fechado, a apreciação e a tendência. */
function momentColumns(moment: SynopticMoment): number {
    const base = props.showQuantitative ? 3 : 2;

    return isMomentOpen(moment.key) ? base + props.synopsis.domains.length : base;
}

const totalColumns = computed(
    () =>
        1 +
        props.synopsis.moments.reduce((total, moment) => total + momentColumns(moment), 0) +
        (props.showQuantitative ? 3 : 2),
);

function readingOf(student: SynopticStudent, key: string): SynopticReadingRow | undefined {
    return student.moments.find((reading) => reading.moment_key === key);
}

function domainCell(reading: SynopticReadingRow | undefined, domainId: number): SynopticDomainCell | undefined {
    return reading?.domains.find((cell) => cell.domain_id === domainId);
}

const elementsById = computed(() => {
    const map = new Map<number, SynopticElement>();
    props.synopsis.elements.forEach((element) => map.set(element.instrument_id, element));

    return map;
});

const periodLabels = computed(() => {
    const map = new Map<number, string>();
    props.synopsis.periods.forEach((period) => map.set(period.id, period.label));

    return map;
});

/** Os elementos de um aluno, agrupados pela unidade temporal a que pertencem. */
function elementGroups(student: SynopticStudent): { label: string; rows: { element: SynopticElement; result: SynopticElementResult }[] }[] {
    const groups = new Map<number, { element: SynopticElement; result: SynopticElementResult }[]>();

    student.elements.forEach((result) => {
        const element = elementsById.value.get(result.instrument_id);

        if (element === undefined) {
            return;
        }

        const rows = groups.get(element.academic_period_id) ?? [];
        rows.push({ element, result });
        groups.set(element.academic_period_id, rows);
    });

    return [...groups.entries()].map(([periodId, rows]) => ({
        label: periodLabels.value.get(periodId) ?? '—',
        rows,
    }));
}

function momentTitle(moment: SynopticMoment): string {
    if (moment.is_formal) {
        return `${moment.moment_label} — resultado formal da unidade. Entra na avaliação contínua.`;
    }

    if (moment.snapshot === null) {
        return `${moment.moment_label} — fotografia informativa. Ainda não foi guardada nenhuma pauta deste momento.`;
    }

    return `${moment.moment_label} — fotografia guardada a ${moment.snapshot.kept_at}, com data de referência ${moment.snapshot.effective_at ?? '—'}. Não entra na avaliação contínua.`;
}

/**
 * O QUE A COLUNA DA AVALIAÇÃO CONTÍNUA DIZ DE SI PRÓPRIA.
 *
 * A frase canónica primeiro — a mesma em toda a aplicação e no Excel — e as
 * unidades concretas desta turma a seguir. Uma explicação genérica sem as
 * unidades deixaria o professor a adivinhar quais entraram; as unidades sem a
 * explicação não diriam que as intercalares ficam de fora.
 */
const continuousUnitsSentence = computed(() => {
    const units = props.synopsis.continuous.units;

    if (units.length === 0) {
        return `${CONTINUOUS}. Sem unidades formais configuradas.`;
    }

    return `${CONTINUOUS}. ${CONTINUOUS_EXPLANATION} Nesta turma: ${units.map((unit) => unit.label).join(' e ')}.`;
});
</script>

<template>
    <div class="space-y-3">
        <div class="print-hide flex flex-wrap items-center gap-3">
            <label class="flex items-center gap-2 text-sm">
                <span class="text-muted-foreground">Procurar aluno</span>
                <input
                    v-model="query"
                    type="search"
                    placeholder="Nome ou número"
                    class="w-52 rounded-md border border-border bg-background px-2.5 py-1 text-sm"
                />
            </label>
            <p v-if="query.trim() !== ''" class="text-xs text-muted-foreground">
                {{ students.length }} de {{ synopsis.students.length }} alunos.
            </p>
        </div>

        <div class="synopsis-grid max-h-[75vh] overflow-auto rounded-lg border border-border">
            <table class="w-max min-w-full border-collapse text-sm">
                <thead>
                    <tr class="sticky top-0 z-20 bg-muted text-left text-xs">
                        <th
                            class="sticky left-0 z-30 border-r border-b border-border bg-muted px-3 py-2 align-bottom font-medium"
                            scope="col"
                        >
                            Aluno
                        </th>

                        <th
                            v-for="moment in synopsis.moments"
                            :key="moment.key"
                            :colspan="momentColumns(moment)"
                            class="border-b border-l-2 border-border px-3 py-1.5 text-center align-bottom text-xs font-semibold"
                            :class="moment.is_formal ? 'bg-muted' : 'bg-muted/50'"
                            scope="colgroup"
                        >
                            <button
                                type="button"
                                class="inline-flex items-center gap-1 rounded px-1 hover:underline focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                :title="momentTitle(moment)"
                                :aria-label="`${momentTitle(moment)} ${isMomentOpen(moment.key) ? 'Fechar' : 'Abrir'} o detalhe por domínio.`"
                                :aria-expanded="isMomentOpen(moment.key)"
                                @click="toggleMoment(moment.key)"
                            >
                                <ChevronDown v-if="isMomentOpen(moment.key)" class="size-3" />
                                <ChevronRight v-else class="size-3" />
                                <!-- A máquina fotográfica diz o que a cor
                                     sozinha não pode dizer: este momento é uma
                                     fotografia informativa (§9, §25). -->
                                <Camera v-if="!moment.is_formal" class="size-3 opacity-70" aria-hidden="true" />
                                <span class="uppercase">{{ moment.label }}</span>
                            </button>
                        </th>

                        <!-- O INDICADOR FORMAL, e a barra que o separa de tudo
                             o resto é mais firme por isso. É daqui que sai a
                             proposta de nível; os momentos à esquerda são o
                             caminho, e o desempenho acumulado — quando o
                             professor o quiser ver — vive na outra vista, como
                             leitura complementar. -->
                        <th
                            :colspan="showQuantitative ? 3 : 2"
                            class="border-b border-l-4 border-l-primary border-b-border bg-primary/15 px-3 py-1.5 text-center align-bottom text-xs font-semibold uppercase"
                            :title="continuousUnitsSentence"
                            :aria-label="continuousUnitsSentence"
                            scope="colgroup"
                        >
                            {{ CONTINUOUS }}
                        </th>
                    </tr>

                    <tr class="sticky top-[34px] z-20 bg-muted/80 text-left text-[11px]">
                        <th class="sticky left-0 z-30 border-r border-b border-border bg-muted px-3 py-1" scope="col">
                            <span class="sr-only">Nome do aluno</span>
                        </th>

                        <template v-for="moment in synopsis.moments" :key="`sub-${moment.key}`">
                            <th v-if="showQuantitative" class="border-b border-l-2 border-border px-2 py-1 text-center font-medium" scope="col">
                                Quant.
                            </th>
                            <th
                                class="border-b border-border px-2 py-1 text-center font-medium"
                                :class="showQuantitative ? '' : 'border-l-2'"
                                scope="col"
                            >
                                Apreciação
                            </th>
                            <th class="border-b border-border px-2 py-1 text-center font-medium" scope="col">Tend.</th>

                            <th
                                v-for="domain in isMomentOpen(moment.key) ? synopsis.domains : []"
                                :key="`sub-${moment.key}-${domain.domain_id}`"
                                class="border-b border-border px-2 py-1 text-center font-medium"
                                :style="{ backgroundColor: `${domain.color}66` }"
                                :title="`${domain.name} — ${moment.label}`"
                                scope="col"
                            >
                                {{ domain.name }}
                            </th>
                        </template>

                        <th v-if="showQuantitative" class="border-b border-l-4 border-border px-2 py-1 text-center font-medium" scope="col">
                            Média
                        </th>
                        <th
                            class="border-b border-border px-2 py-1 text-center font-medium"
                            :class="showQuantitative ? '' : 'border-l-4'"
                            scope="col"
                        >
                            Proposta
                        </th>
                        <th class="border-b border-border px-2 py-1 text-center font-medium" scope="col">Decisão</th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    <template v-for="student in students" :key="student.enrollment_id">
                        <tr class="hover:bg-muted/20">
                            <th
                                scope="row"
                                class="sticky left-0 z-10 border-r border-border bg-background px-3 py-2 text-left font-medium whitespace-nowrap"
                            >
                                <span class="flex items-center gap-1.5">
                                    <button
                                        type="button"
                                        class="print-hide rounded p-0.5 text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                        :aria-expanded="isStudentOpen(student.enrollment_id)"
                                        :aria-label="`${isStudentOpen(student.enrollment_id) ? 'Fechar' : 'Abrir'} os elementos de avaliação de ${student.name}`"
                                        @click="toggleStudent(student.enrollment_id)"
                                    >
                                        <ChevronDown v-if="isStudentOpen(student.enrollment_id)" class="size-3.5" />
                                        <ChevronRight v-else class="size-3.5" />
                                    </button>
                                    <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                                    <!-- A ligação para o Relatório do aluno, que
                                         já existe e não é repetido aqui (§14). -->
                                    <Link
                                        v-if="canViewStudentProgress && student.enrollment_ulid"
                                        :href="`/classes/${classUlid}/evolucao/${student.enrollment_ulid}`"
                                        class="hover:underline"
                                        :title="`Abrir o Relatório do aluno — ${student.name}`"
                                    >{{ student.name }}</Link>
                                    <span v-else>{{ student.name }}</span>
                                </span>
                            </th>

                            <template v-for="moment in synopsis.moments" :key="`${student.enrollment_id}-${moment.key}`">
                                <td v-if="showQuantitative" class="border-l-2 border-border px-2 py-1.5 text-center tabular-nums">
                                    <span :class="{ 'text-muted-foreground': (readingOf(student, moment.key)?.overall?.normalized_value ?? null) === null }">
                                        {{ percent(readingOf(student, moment.key)?.overall?.normalized_value ?? null) }}
                                    </span>
                                    <CircleAlert
                                        v-if="readingOf(student, moment.key)?.overall?.has_coverage_warning"
                                        class="ml-0.5 inline size-3 text-amber-500"
                                        title="Cobertura parcial — houve avaliação, mas nem todos os elementos previstos foram realizados."
                                    />
                                </td>
                                <td
                                    class="px-2 py-1.5 text-center whitespace-nowrap"
                                    :class="showQuantitative ? '' : 'border-l-2 border-border'"
                                >
                                    <span
                                        v-if="readingOf(student, moment.key)?.overall?.current?.text"
                                        class="rounded px-1.5 py-0.5 text-xs"
                                        :class="[
                                            appreciationClasses(readingOf(student, moment.key)!.overall!.current, scaleBands),
                                            readingOf(student, moment.key)!.overall!.current!.origin === 'decided' ? 'font-semibold' : 'italic',
                                        ]"
                                        :title="appreciationTitle(readingOf(student, moment.key)!.overall!.current)"
                                        :aria-label="appreciationTitle(readingOf(student, moment.key)!.overall!.current)"
                                    >{{ readingOf(student, moment.key)!.overall!.current!.text }}</span>
                                    <span
                                        v-else-if="!moment.is_formal && moment.source === 'none'"
                                        class="text-muted-foreground"
                                        title="Ainda não foi guardada nenhuma pauta deste momento intercalar."
                                    >—</span>
                                    <span v-else class="text-muted-foreground" title="Sem apreciação neste momento.">—</span>
                                </td>
                                <td class="px-2 py-1.5 text-center">
                                    <span
                                        v-if="readingOf(student, moment.key)?.trend"
                                        :class="trendTone(readingOf(student, moment.key)!.trend)"
                                        :title="trendTitle(readingOf(student, moment.key)!.trend)"
                                        :aria-label="trendTitle(readingOf(student, moment.key)!.trend)"
                                    >{{ trendGlyph(readingOf(student, moment.key)!.trend) }}</span>
                                    <span v-else class="text-muted-foreground" aria-label="Sem termo de comparação anterior.">·</span>
                                </td>

                                <td
                                    v-for="domain in isMomentOpen(moment.key) ? synopsis.domains : []"
                                    :key="`${student.enrollment_id}-${moment.key}-${domain.domain_id}`"
                                    class="px-2 py-1.5 text-center whitespace-nowrap"
                                    :style="{ backgroundColor: `${domain.color}26` }"
                                >
                                    <span
                                        v-if="domainCell(readingOf(student, moment.key), domain.domain_id)?.current?.text"
                                        class="rounded px-1.5 py-0.5 text-xs"
                                        :class="[
                                            appreciationClasses(domainCell(readingOf(student, moment.key), domain.domain_id)!.current, scaleBands),
                                            domainCell(readingOf(student, moment.key), domain.domain_id)!.current!.origin === 'decided'
                                                ? 'font-semibold'
                                                : 'italic',
                                        ]"
                                        :title="appreciationTitle(domainCell(readingOf(student, moment.key), domain.domain_id)!.current, domain.name)"
                                        :aria-label="appreciationTitle(domainCell(readingOf(student, moment.key), domain.domain_id)!.current, domain.name)"
                                    >{{ domainCell(readingOf(student, moment.key), domain.domain_id)!.current!.text }}</span>
                                    <span v-else class="text-muted-foreground">—</span>
                                    <span
                                        v-if="showQuantitative && domainCell(readingOf(student, moment.key), domain.domain_id)?.normalized_value"
                                        class="ml-1 text-[10px] text-muted-foreground tabular-nums"
                                    >{{ percent(domainCell(readingOf(student, moment.key), domain.domain_id)!.normalized_value) }}</span>
                                    <!-- O QUE O ALUNO DISSE SOBRE ESTE DOMÍNIO,
                                         em expoente e a meia-voz. É informação
                                         de apoio: não entra em cálculo nenhum e
                                         nunca determina a classificação (§15,
                                         §61).
                                         «A3» OBRIGAVA A DECIFRAR — o «A» podia
                                         ser um nível, uma alínea ou um aviso, e
                                         uma legenda no fundo da página não
                                         acompanha quem está a ler a célula.
                                         «Auto 3» diz-se sozinho, e o dado por
                                         baixo é exatamente o mesmo. -->
                                    <sup
                                        v-if="domainCell(readingOf(student, moment.key), domain.domain_id)?.self_assessment"
                                        class="ml-0.5 rounded bg-background/70 px-1 text-[10px] font-normal whitespace-nowrap text-muted-foreground"
                                        :title="`Autoavaliação do aluno — ${domain.name}: ${domainCell(readingOf(student, moment.key), domain.domain_id)!.self_assessment!.code} — ${domainCell(readingOf(student, moment.key), domain.domain_id)!.self_assessment!.label}`"
                                        :aria-label="`Autoavaliação do aluno — ${domain.name}: ${domainCell(readingOf(student, moment.key), domain.domain_id)!.self_assessment!.code} — ${domainCell(readingOf(student, moment.key), domain.domain_id)!.self_assessment!.label}`"
                                    >Auto {{ domainCell(readingOf(student, moment.key), domain.domain_id)!.self_assessment!.code }}</sup>
                                    <span
                                        v-if="domainCell(readingOf(student, moment.key), domain.domain_id)?.trend"
                                        class="ml-0.5 text-[10px]"
                                        :class="trendTone(domainCell(readingOf(student, moment.key), domain.domain_id)!.trend)"
                                        :title="trendTitle(domainCell(readingOf(student, moment.key), domain.domain_id)!.trend)"
                                        :aria-label="trendTitle(domainCell(readingOf(student, moment.key), domain.domain_id)!.trend)"
                                    >{{ trendGlyph(domainCell(readingOf(student, moment.key), domain.domain_id)!.trend) }}</span>
                                </td>
                            </template>

                            <td v-if="showQuantitative" class="border-l-4 border-border bg-primary/5 px-2 py-1.5 text-center tabular-nums">
                                <span :class="{ 'text-muted-foreground': (student.continuous?.normalized_value ?? null) === null }">
                                    {{ percent(student.continuous?.normalized_value ?? null) }}
                                </span>
                            </td>
                            <td
                                class="bg-primary/5 px-2 py-1.5 text-center whitespace-nowrap"
                                :class="showQuantitative ? '' : 'border-l-4 border-border'"
                            >
                                <span
                                    v-if="student.continuous?.level"
                                    class="rounded px-1.5 py-0.5 text-xs italic"
                                    :class="appreciationClasses(
                                        {
                                            origin: 'proposed',
                                            code: student.continuous.level.code,
                                            label: student.continuous.level.label,
                                            text: student.continuous.level.code,
                                            sequence: student.continuous.level.sequence,
                                            is_negative: student.continuous.level.is_negative,
                                        },
                                        scaleBands,
                                    )"
                                    :title="`Proposta do Lapispro para a avaliação contínua: ${student.continuous.level.code} — ${student.continuous.level.label}. ${continuousUnitsSentence}`"
                                >{{ student.continuous.level.code }}</span>
                                <span
                                    v-else-if="student.continuous?.proposal?.value"
                                    class="rounded bg-muted px-1.5 py-0.5 text-xs italic"
                                >{{ student.continuous.proposal.value }}</span>
                                <span v-else class="text-muted-foreground" title="Sem resultados formais para calcular uma média contínua.">—</span>
                            </td>
                            <td class="bg-primary/5 px-2 py-1.5 text-center whitespace-nowrap">
                                <span
                                    v-if="student.continuous?.decision?.final"
                                    class="rounded px-1.5 py-0.5 text-xs font-semibold"
                                    :title="`Decisão do professor: ${student.continuous.decision.final.code} — ${student.continuous.decision.final.label}`"
                                >{{ student.continuous.decision.final.code }}</span>
                                <span
                                    v-else
                                    class="text-muted-foreground"
                                    title="Ainda por decidir — o Lapispro propõe, o professor decide."
                                >—</span>
                            </td>
                        </tr>

                        <!-- NÍVEL 3: os elementos que sustentam tudo o que está
                             em cima. Numa linha própria e a largura toda, porque
                             é uma lista e não mais colunas (§11, §12). -->
                        <tr v-if="isStudentOpen(student.enrollment_id)" class="bg-muted/10">
                            <td :colspan="totalColumns" class="px-3 py-3">
                                <p class="mb-2 text-xs font-medium">
                                    Elementos de avaliação de {{ student.name }}
                                </p>
                                <p v-if="student.elements.length === 0" class="text-xs text-muted-foreground">
                                    Ainda não há elementos de avaliação nesta turma.
                                </p>
                                <div v-for="group in elementGroups(student)" :key="group.label" class="mb-3">
                                    <p class="mb-1 text-[11px] font-semibold text-muted-foreground uppercase">{{ group.label }}</p>
                                    <table class="w-full text-xs">
                                        <thead>
                                            <tr class="text-left text-muted-foreground">
                                                <th class="py-1 pr-3 font-medium" scope="col">Data</th>
                                                <th class="py-1 pr-3 font-medium" scope="col">Elemento</th>
                                                <th class="py-1 pr-3 font-medium" scope="col">Domínios</th>
                                                <th class="py-1 pr-3 text-right font-medium" scope="col">Peso</th>
                                                <th v-if="showQuantitative" class="py-1 pr-3 text-right font-medium" scope="col">Resultado</th>
                                                <th class="py-1 pr-3 font-medium" scope="col">Nível</th>
                                                <th class="py-1 font-medium" scope="col">Estado</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y divide-border/60">
                                            <tr v-for="row in group.rows" :key="row.element.instrument_id">
                                                <td class="py-1 pr-3 whitespace-nowrap tabular-nums">{{ row.element.applied_on }}</td>
                                                <td class="py-1 pr-3">
                                                    {{ row.element.title }}
                                                    <span
                                                        v-if="!row.element.counts_toward_classification"
                                                        class="ml-1 rounded bg-muted px-1 text-[10px] text-muted-foreground"
                                                        title="Este elemento não conta para a classificação."
                                                    >não conta</span>
                                                </td>
                                                <td class="py-1 pr-3">
                                                    <span
                                                        v-for="domain in row.element.domains"
                                                        :key="domain.domain_id"
                                                        class="mr-1 inline-block rounded px-1.5 py-0.5 text-[10px]"
                                                        :style="{
                                                            backgroundColor: `${synopsis.domains.find((candidate) => candidate.domain_id === domain.domain_id)?.color ?? '#F3F4F6'}66`,
                                                        }"
                                                    >{{ domain.name }}</span>
                                                    <span v-if="row.element.domains.length === 0" class="text-muted-foreground">—</span>
                                                </td>
                                                <!-- Vazio é «não foi declarado»,
                                                     e nunca «pesa zero». -->
                                                <td class="py-1 pr-3 text-right tabular-nums">{{ row.element.weight ?? '—' }}</td>
                                                <!-- O RESULTADO DO ELEMENTO SEGUE O MESMO INTERRUPTOR do resto
                                                     da grelha (§60). Um professor que desligou os números não
                                                     os quer de volta ao abrir o detalhe; o nível ao lado continua
                                                     a dizer o que aconteceu. -->
                                                <td v-if="showQuantitative" class="py-1 pr-3 text-right tabular-nums">{{ percent(row.result.normalized_value) }}</td>
                                                <td class="py-1 pr-3 whitespace-nowrap">
                                                    <span
                                                        v-if="row.result.level"
                                                        class="rounded px-1.5 py-0.5"
                                                        :class="appreciationClasses(
                                                            {
                                                                origin: 'proposed',
                                                                code: row.result.level.code,
                                                                label: row.result.level.label,
                                                                text: row.result.level.code,
                                                                sequence: row.result.level.sequence,
                                                                is_negative: row.result.level.is_negative,
                                                            },
                                                            scaleBands,
                                                        )"
                                                        :title="`${row.result.level.code} — ${row.result.level.label}`"
                                                    >{{ row.result.level.code }}</span>
                                                    <span v-else class="text-muted-foreground">—</span>
                                                </td>
                                                <td class="py-1 text-muted-foreground">{{ row.result.state_label }}</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    </template>

                    <tr v-if="students.length === 0">
                        <td :colspan="totalColumns" class="px-3 py-6 text-center text-sm text-muted-foreground">
                            Nenhum aluno corresponde a «{{ query }}».
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
