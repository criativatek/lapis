<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { CircleAlert, FileSpreadsheet, Lock } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import Heading from '@/components/Heading.vue';
import AccumulatedBreakdownPanel from '@/components/results/AccumulatedBreakdownPanel.vue';
import ClassSynopsisTable from '@/components/results/ClassSynopsisTable.vue';
import FinalDomainDecisionDialog from '@/components/results/FinalDomainDecisionDialog.vue';
import { qualitativeToneClasses, qualitativeToneFor } from '@/lib/qualitativeTone';
import {
    ACCUMULATED,
    ACCUMULATED_EXPLANATION,
    ACCUMULATED_LONG,
    ACCUMULATED_NOT_AN_AVERAGE,
    ACCUMULATED_SHORT,
    CONTINUOUS,
    CONTINUOUS_EXPLANATION,
    CONTINUOUS_FINAL,
    FINAL_AVERAGE,
    FINAL_AVERAGE_EXPLANATION,
    FINAL_MENTION,
} from '@/lib/readings';
import { pct, TREND_SHAPE, trendArrow, trendClasses, trendPoints, trendTitle } from '@/lib/results';
import type { Evolution } from '@/lib/results';
import type { Synopsis } from '@/lib/synopsis';

/** A band of the profile's own scale. `code` is the value; `label` the mention. */
type Level = { code: string; label: string; sequence: number; is_negative: boolean } | null;

type Proposal = { value: string | null; state: string; is_percentage: boolean };

/**
 * The band the domain's accumulated figure falls in. Carries the scale's own
 * identity — id, code, rank — so a later export maps from the band and not from
 * the words shown here.
 */
type Mention = { scale_level_id: number; code: string; label: string; sequence: number; is_negative: boolean } | null;

type DomainCell = {
    domain_id: number;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    self_assessment: Level;
    mention: Mention;
};

type PeriodCell = {
    period_id: number;
    period_label: string;
    weighted_average: string | null;
    accumulated_average: string | null;
    coverage_warning: boolean;
    evolution: Evolution;
    domains: DomainCell[];
    self_assessment: Level;
    classification: {
        ulid: string;
        status: string;
        is_published: boolean;
        proposal: Proposal;
        proposed: Level;
        final: Level;
        differs_from_proposal: boolean;
    } | null;
};

type Student = {
    enrollment_id: number;
    enrollment_ulid: string;
    name: string;
    class_number: number | null;
    periods: PeriodCell[];
};

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        has_profile: boolean;
        scale_name: string | null;
    };
    decision: {
        label: string;
        classifies_by_level: boolean;
        levels: { id: number; code: string; label: string }[];
        min_value: string | null;
        max_value: string | null;
    };
    scaleBands: { code: string; label: string; sequence: number; is_negative: boolean }[];
    /** Whether this organization's plan includes the INOVAR export. */
    canExportToInovar: boolean;
    /** Whether the Relatório do aluno is reachable — presentation only (§14). */
    canViewStudentProgress: boolean;
    /** Se este professor pode concluir o ano num domínio — apresentação (§8.2). */
    canDecideDomains: boolean;
    progression: {
        periods: { id: number; ulid: string; label: string; sequence: number }[];
        domains: { id: number; ulid: string; name: string }[];
        students: Student[];
    };
    /** O ano visto pelos MOMENTOS — ver `BuildClassSynopsis`. */
    synopsis: Synopsis;
}>();

const periods = computed(() => props.progression.periods);
const domains = computed(() => props.progression.domains);
const students = computed(() => props.progression.students);

// -------------------------------------------------------------- as duas vistas
//
// DUAS LEITURAS DO MESMO ANO, e não duas páginas. «Ao longo do ano» é a leitura
// longitudinal por momentos — o que aconteceu, e quando; «Por domínio» é a
// leitura numérica que esta página sempre teve. Separá-las em dois ecrãs seria
// obrigar a escolher antes de saber o que se procura; juntá-las numa só tabela
// seria uma grelha com o dobro das colunas de que qualquer uma delas precisa.

type SummaryView = 'moments' | 'domains';

const view = ref<SummaryView>('moments');

/**
 * OS QUANTITATIVOS LIGAM-SE E DESLIGAM-SE, como na Pauta (§60).
 *
 * Uma pauta de 1.º ciclo lê-se por menções e os números só distraem; uma de 3.º
 * ciclo precisa deles. As cores e as tendências continuam a funcionar nos dois
 * modos, porque nenhuma delas depende de haver um número na célula.
 */
const showQuantitative = ref(true);

/**
 * ---------------------------------------------------- de onde vem aquele número
 *
 * O ACUMULADO DE CADA CÉLULA ABRE-SE. Um professor que veja 68,0 % num
 * semestre, 25,3 % no outro e 60 % de desempenho acumulado tem à frente um
 * número que não é a média de dois; o painel mostra-lhe a conta que o produziu
 * — os pontos de cada unidade, os elementos, e a fração que eles formam.
 *
 * A DECOMPOSIÇÃO NÃO VIAJA COM A PÁGINA. Trinta alunos × cinco domínios × duas
 * unidades são trezentas células; a decomposição pede-se ao servidor quando
 * alguém abre uma, e não trezentas vezes por precaução (§26).
 */
const breakdownUrl = ref<string | null>(null);
const breakdownStudent = ref('');

/**
 * A célula clicada, endereçada como o servidor a espera: turma, unidade,
 * matrícula e — quando é a de um domínio — o domínio. Sem domínio, é o número
 * global do bloco da síntese.
 */
function openBreakdown(student: Student, period: PeriodCell, domainUlid: string | null): void {
    const unit = props.progression.periods.find((candidate) => candidate.id === period.period_id);

    if (unit === undefined) {
        return;
    }

    breakdownStudent.value = student.name;
    breakdownUrl.value =
        `/classes/${props.schoolClass.ulid}/results/desempenho-acumulado/${unit.ulid}/${student.enrollment_ulid}` +
        (domainUlid === null ? '' : `/${domainUlid}`);
}

/** A última unidade do ano — a que o acumulado de um domínio responde por. */
const lastPeriodIndex = computed(() => periods.value.length - 1);

// Per domain: one column per period, an evolution column after every period but
// the first, then the accumulated figure and the mention it falls in.
//
// A CONCLUSÃO DO ANO JÁ NÃO VIVE AQUI. Enquanto a média final formal e a
// apreciação que dela sai eram duas colunas no fim do bloco de cada domínio,
// ninguém as lia como a conclusão do ano: liam-se como mais duas colunas de um
// domínio, encostadas ao desempenho acumulado — que é a OUTRA leitura. Foram
// para um bloco próprio, no fim da grelha, onde a pergunta «como é que este
// aluno terminou em Oralidade?» tem uma resposta e não uma dedução (§13, §14).
const domainColumns = computed(() => periods.value.length * 2 + 1);

/**
 * ------------------------------- a avaliação contínua final de cada domínio
 *
 * O QUE FALTAVA ERA UMA CONCLUSÃO. Cada bloco de domínio mostrava os resultados
 * de cada unidade e o desempenho acumulado, e nenhuma coluna dizia em que é que
 * o ano tinha dado NAQUELE domínio. O acumulado não serve de resposta: é a
 * outra leitura, a que reprocessa os elementos brutos.
 *
 * TRÊS CONCEITOS QUE NÃO SE MISTURAM (§14), e a grelha diz qual é qual pela
 * ordem e pela barra que os separa: primeiro os RESULTADOS FORMAIS de cada
 * unidade, depois o ANALÍTICO — desempenho acumulado e a sua menção —, e por
 * fim o FORMAL FINAL, que leva a cor do produto porque é dele que sairia uma
 * proposta de nível.
 *
 * NADA É CALCULADO AQUI. A média vem de `ContinuousAssessment`, pela mesma
 * classe que produz a global.
 */
function domainFinal(student: Student, domainId: number) {
    return continuousDomainsByEnrollment.value.get(student.enrollment_id)?.[domainId] ?? null;
}

const continuousDomainsByEnrollment = computed(() => {
    const map = new Map<number, Record<number, NonNullable<Synopsis['students'][number]['continuous']>>>();

    props.synopsis.students.forEach((student) => {
        map.set(student.enrollment_id, student.continuous_domains ?? {});
    });

    return map;
});

/**
 * A APRECIAÇÃO FINAL DE UM DOMÍNIO: a decisão do professor quando existe, a
 * proposta que sai da média quando não — a mesma aceitação tácita que vale em
 * toda a aplicação (§2).
 */
function domainFinalAppreciation(student: Student, domainId: number): {
    level: Level;
    decided: boolean;
    proposed: Level;
} | null {
    const reading = domainFinal(student, domainId);

    if (reading === null) {
        return null;
    }

    const proposed = reading.level;
    const decided = reading.decision?.final ?? null;

    if (decided !== null) {
        return {
            level: { code: decided.code, label: decided.label, sequence: 0, is_negative: false },
            decided: true,
            proposed,
        };
    }

    return proposed === null ? null : { level: proposed, decided: false, proposed };
}

/**
 * ------------------------------------------- decidir a conclusão de um domínio
 *
 * O VALOR É A PORTA, como em todo o resto do produto. Um botão «alterar» em
 * cada célula — trinta alunos × cinco domínios — transformaria a grelha num
 * painel de controlo; clicar na apreciação é a mesma acção sem o ruído (§5).
 *
 * ESCREVE NO ÂMBITO DO ANO. A unidade não vai no endereço: uma conclusão anual
 * escreve-se sempre na unidade que fecha o ano, e é o servidor que a deriva —
 * pela mesma função que a leitura usa. Uma decisão de um semestre continua a
 * ser outra coisa, noutra linha (§17).
 */
const decidingStudent = ref<Student | null>(null);
const decidingDomain = ref<{ id: number; ulid: string; name: string } | null>(null);
const decisionSaving = ref(false);
const decisionError = ref<string | null>(null);

const decidingReading = computed(() =>
    decidingStudent.value === null || decidingDomain.value === null
        ? null
        : domainFinal(decidingStudent.value, decidingDomain.value.id),
);

function openFinalDecision(student: Student, domain: { id: number; ulid: string; name: string }): void {
    if (!props.canDecideDomains) {
        return;
    }

    decisionError.value = null;
    decidingStudent.value = student;
    decidingDomain.value = domain;
}

function closeFinalDecision(): void {
    decidingStudent.value = null;
    decidingDomain.value = null;
    decisionError.value = null;
}

function saveFinalDecision(scaleLevelId: number | null): void {
    const student = decidingStudent.value;
    const domain = decidingDomain.value;

    if (student === null || domain === null) {
        return;
    }

    decisionSaving.value = true;
    decisionError.value = null;

    router.post(
        `/classes/${props.schoolClass.ulid}/results/quadro-sintese/dominios/${student.enrollment_ulid}/${domain.ulid}`,
        { scale_level_id: scaleLevelId },
        {
            preserveScroll: true,
            onSuccess: () => closeFinalDecision(),
            onError: (errors: Record<string, string>) => {
                decisionError.value = errors.scale_level_id ?? 'Não foi possível guardar esta apreciação.';
            },
            onFinish: () => {
                decisionSaving.value = false;
            },
        },
    );
}

/** «Alterar a apreciação final de Oralidade de Ana — …», dito por inteiro. */
function domainFinalActionLabel(student: Student, domain: { id: number; name: string }): string {
    const appreciation = domainFinalAppreciation(student, domain.id);
    const who = `${domain.name} de ${student.name}`;

    return appreciation === null
        ? `Atribuir a apreciação final de ${who}`
        : `Alterar a apreciação final de ${who} — ${domainFinalTitle(student, domain)}`;
}

/** A frase inteira, que é o que chega a quem não vê nem cor nem negrito (§25). */
function domainFinalTitle(student: Student, domain: { id: number; name: string }): string {
    const reading = domainFinal(student, domain.id);

    if (reading === null || reading.normalized_value === null) {
        return `${domain.name}: sem resultados formais para uma avaliação contínua final.`;
    }

    const average = `${domain.name} · ${CONTINUOUS_FINAL} — ${FINAL_AVERAGE}: ${pct(reading.normalized_value)}. ${continuousFormula.value}`;
    const appreciation = domainFinalAppreciation(student, domain.id);

    if (appreciation === null) {
        return average;
    }

    if (appreciation.decided) {
        const proposed = appreciation.proposed;

        return `${average} Decisão do professor: ${appreciation.level?.code} — ${appreciation.level?.label}.${
            proposed === null ? '' : ` Proposta do Lapispro: ${proposed.code} — ${proposed.label}.`
        }`;
    }

    return `${average} Proposta do Lapispro: ${appreciation.level?.code} — ${appreciation.level?.label} — vigente enquanto o professor não a alterar.`;
}

/**
 * ------------------------------------------------------ as três forças de traço
 *
 * A GRELHA TEM QUATRO GRANDES BLOCOS — os resultados por domínio, a síntese de
 * cada unidade temporal, e a avaliação contínua final —, e a fronteira entre
 * eles é a informação estrutural mais importante que a tabela tem. Vinha
 * desenhada ao contrário: o traço mais forte era um azul dentro do bloco de
 * cada domínio, a separar duas colunas internas, e as fronteiras entre blocos
 * eram mais fracas do que ele. Quem lia via a divisão errada em destaque.
 *
 * TRÊS FORÇAS, POR ORDEM: os grandes blocos, os grupos dentro deles (cada
 * domínio, cada domínio do bloco final), e as colunas, que se separam pelo
 * traço fino de toda a tabela e mais nada.
 *
 * E NEUTRO, SEMPRE. Um divisor estrutural não é um estado, uma seleção nem um
 * foco; o azul do produto é para ênfase FUNCIONAL — aqui, o fundo do bloco que
 * carrega o indicador formal — e não para dizer onde acaba uma coluna (§18).
 */
const BLOCK_RULE = 'border-l-4 border-l-foreground/25';

/**
 * WHERE ONE DOMAIN ENDS AND THE NEXT BEGINS.
 *
 * A slightly firmer rule at each group's first column, in the header and in
 * every row alike, so the grouping is read down the table and not only across
 * its heading. Structure, never colour — the domain's name and this separator
 * both carry it, so the blocks hold without either.
 */
const GROUP_RULE = 'border-l-2 border-l-border';

/**
 * Two very soft tones, alternating, on the domain HEADINGS only.
 *
 * The body stays neutral on purpose: the trend tint has meaning and must own
 * the only colour in a data cell (§15). These say nothing pedagogical — they
 * are there so the eye finds the edge of a block, and are deliberately not the
 * greens and reds that do mean something.
 */
function domainTone(index: number): string {
    return index % 2 === 0 ? 'bg-muted' : 'bg-muted/60';
}

// Per period of the síntese: the standalone average, its evolution (except the
// first), the accumulated, the proposal, the self-assessment and the decision.
function synthesisColumns(index: number): number {
    return index === 0 ? 5 : 6;
}

/**
 * ------------------------------------------- as larguras do bloco que fecha o ano
 *
 * CADA DOMÍNIO OCUPA DUAS COLUNAS — a média final e a menção que ela dá —, e o
 * GLOBAL ocupa três: a média do ano, a proposta formal e o nível atribuído.
 *
 * COM OS QUANTITATIVOS DESLIGADOS A MÉDIA SAI DA GRELHA, coluna incluída. Quem
 * desligou os números pediu uma pauta sem números, e uma célula vazia debaixo de
 * «Média final» não é uma pauta sem números — é uma pauta com um buraco. O que
 * fica é a menção, que é a resposta na língua que ele escolheu (§60).
 */
const finalDomainColumns = computed(() => (showQuantitative.value ? 2 : 1));
const finalGlobalColumns = computed(() => (showQuantitative.value ? 3 : 2));

const domainsBlockColumns = computed(() => domains.value.length * domainColumns.value);
const finalBlockColumns = computed(() => domains.value.length * finalDomainColumns.value + finalGlobalColumns.value);

/**
 * A régua com que um grupo do bloco final começa: a do bloco no primeiro
 * domínio — porque é aí que a Avaliação Contínua Final começa — e a de grupo em
 * cada domínio seguinte (§17).
 */
function finalGroupRule(index: number): string {
    return index === 0 ? BLOCK_RULE : GROUP_RULE;
}

/**
 * ------------------------------------------------- a avaliação contínua final
 *
 * O ANO FECHA NUM SÍTIO, E NÃO NA ÚLTIMA SÍNTESE ESTANQUE. Cada bloco «Síntese»
 * responde por UMA unidade temporal; nenhum deles responde pelo ano. A
 * avaliação contínua é essa resposta — a média dos resultados formais de todas
 * as unidades, com os pesos que a escola configurou —, e é dela, e nunca do
 * desempenho acumulado, que sai a proposta formal de nível (§13, §14).
 *
 * O NÚMERO NÃO É CALCULADO AQUI. Vem de `ContinuousAssessment`, através do
 * mesmo payload que a leitura por momentos já usa: uma segunda média feita no
 * browser seria uma segunda resposta à mesma pergunta.
 */
const continuousByEnrollment = computed(() => {
    const map = new Map<number, NonNullable<Synopsis['students'][number]['continuous']>>();

    props.synopsis.students.forEach((student) => {
        if (student.continuous !== null) {
            map.set(student.enrollment_id, student.continuous);
        }
    });

    return map;
});

/**
 * COMO A MÉDIA É FEITA, dito por palavras e com as unidades desta turma.
 *
 * Com pesos declarados escreve-se a conta; sem eles escreve-se a igualdade, que
 * é o que «média entre o 1.º e o 2.º semestre» quer dizer em português. Uma
 * frase só serviria mal os dois casos.
 */
const continuousFormula = computed(() => {
    const units = props.synopsis.continuous.units;

    if (units.length === 0) {
        return 'Sem unidades formais configuradas.';
    }

    if (!props.synopsis.continuous.weights_declared) {
        return `Média dos resultados formais, com peso igual: ${units.map((unit) => unit.label).join(' e ')}.`;
    }

    return `Média ponderada dos resultados formais: ${units
        .map((unit) => `${unit.label} × ${unit.weight_percent} %`)
        .join(' + ')}.`;
});

const continuousTitle = computed(
    () => `${CONTINUOUS} — indicador formal do ano. ${CONTINUOUS_EXPLANATION} ${continuousFormula.value}`,
);

/** DESEMPENHO, from the canonical resolver — never a colour chosen here. */
function levelClasses(level: Level): string {
    if (level === null) {
        return 'text-muted-foreground';
    }

    return qualitativeToneClasses[qualitativeToneFor(level, props.scaleBands)];
}

function domainCell(period: PeriodCell, domainId: number): DomainCell | undefined {
    return period.domains.find((domain) => domain.domain_id === domainId);
}

/**
 * O QUE O CABEÇALHO DE CADA COLUNA DO BLOCO FINAL DIZ DE SI PRÓPRIO.
 *
 * A abreviatura nunca é a única informação (§25): a coluna escreve «Média
 * final», e o `title` e o texto acessível dizem de que média se trata, de que
 * domínio, e de que resultados ela sai.
 */
function finalAverageHeaderTitle(domain: { name: string }): string {
    return `${domain.name} — ${FINAL_AVERAGE}. ${FINAL_AVERAGE_EXPLANATION} ${continuousFormula.value}`;
}

function finalMentionHeaderTitle(domain: { name: string }): string {
    return `${domain.name} — ${FINAL_MENTION}: a decisão do professor quando existe, a proposta que sai da ${FINAL_AVERAGE.toLowerCase()} quando não.`;
}

/** «Autoavaliação do aluno: 3 — Suficiente», for the discreet marker beside a domain's value. */
function selfAssessmentTitle(level: Level): string {
    return level === null ? '' : `Autoavaliação do aluno: ${level.code} — ${level.label}`;
}

function proposalText(proposal: Proposal | undefined): string {
    if (proposal === undefined || proposal.value === null) {
        return '—';
    }

    return proposal.is_percentage ? `${proposal.value}%` : proposal.value;
}
</script>

<template>
    <Head :title="`Quadro Síntese — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading :title="`Quadro Síntese — ${schoolClass.label}`" :description="schoolClass.subject" />
                <div class="flex gap-3 text-sm">
                    <Link :href="`/classes/${schoolClass.ulid}`" class="text-muted-foreground hover:underline">← Voltar à turma</Link>
                    <Link :href="`/classes/${schoolClass.ulid}/classifications`" class="text-primary hover:underline">
                        Gerir classificações →
                    </Link>
                    <!-- Only where the capability is there: a school that never
                         uses INOVAR never meets this. -->
                    <Link
                        v-if="canExportToInovar && periods.length"
                        :href="`/classes/${schoolClass.ulid}/exports/inovar/${periods[periods.length - 1].ulid}`"
                        class="text-primary hover:underline"
                    >
                        Exportar para INOVAR →
                    </Link>
                </div>
            </div>
            <!-- The real periods of the year, then this view. Neither their
                 names nor their number is known here (§2). -->
            <div v-if="periods.length" class="flex gap-1">
                <Link
                    v-for="period in periods"
                    :key="period.ulid"
                    :href="`/classes/${schoolClass.ulid}/results/${period.ulid}`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    {{ period.label }}
                </Link>
                <span class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm text-primary-foreground">Quadro Síntese</span>
                <Link
                    :href="`/classes/${schoolClass.ulid}/results/estatistica`"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                >
                    Estatística
                </Link>
            </div>
        </div>

        <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            Esta turma não tem perfil de avaliação associado, por isso não há resultados a sintetizar.
        </p>

        <EmptyState v-else-if="students.length === 0" title="Sem alunos nesta turma." />

        <template v-else>
            <!-- DUAS LEITURAS DO MESMO ANO. «Ao longo do ano» segue os momentos
                 — o que aconteceu e quando; «Por domínio» segue os números, e é
                 a leitura que esta página sempre teve. -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex overflow-hidden rounded-md border border-border" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="view === 'moments'"
                        class="px-3 py-1.5 text-sm"
                        :class="view === 'moments' ? 'bg-muted font-medium' : ''"
                        @click="view = 'moments'"
                    >
                        Ao longo do ano
                    </button>
                    <button
                        type="button"
                        role="tab"
                        :aria-selected="view === 'domains'"
                        class="px-3 py-1.5 text-sm"
                        :class="view === 'domains' ? 'bg-muted font-medium' : ''"
                        @click="view = 'domains'"
                    >
                        Por domínio
                    </button>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <!-- O INTERRUPTOR VALE NAS DUAS VISTAS. A leitura por
                         domínio passou a fechar cada bloco com uma apreciação
                         qualitativa, e uma pauta de 1.º ciclo lê-se por menções:
                         desligar os números tem de continuar a desligá-los
                         também aqui (§60). O que se apaga é o número, nunca o
                         juízo. -->
                    <label class="flex items-center gap-1.5 text-sm">
                        <input v-model="showQuantitative" type="checkbox" class="rounded border-border" />
                        <span>Mostrar quantitativos</span>
                    </label>
                    <!-- Ligação simples, e não uma visita Inertia: uma resposta
                         binária não volta por uma delas. -->
                    <a
                        :href="`/classes/${schoolClass.ulid}/results/quadro-sintese/xlsx`"
                        class="inline-flex items-center gap-1.5 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                        title="Exporta o quadro inteiro — momentos, domínios, elementos e a configuração — independentemente do que está visível no ecrã."
                    >
                        <FileSpreadsheet class="size-3.5" />
                        Exportar Excel
                    </a>
                </div>
            </div>

            <ClassSynopsisTable
                v-if="view === 'moments'"
                :class-ulid="schoolClass.ulid"
                :scale-bands="scaleBands"
                :can-view-student-progress="canViewStudentProgress"
                :show-quantitative="showQuantitative"
                :synopsis="synopsis"
            />
        </template>

        <!-- Wide on purpose: a year of a class does not fit a viewport, and
             shrinking it to fit would be hiding it (§4). The student stays put
             while everything else scrolls past. -->
        <div
            v-if="schoolClass.has_profile && students.length > 0 && view === 'domains'"
            class="max-h-[75vh] overflow-auto rounded-lg border border-border"
        >
            <table class="w-max min-w-full text-sm">
                <thead class="text-left">
                    <!-- OS QUATRO GRANDES BLOCOS, e é esta a linha que a grelha
                         existe para se deixar ler: o que aconteceu em cada
                         DOMÍNIO, o que fechou cada UNIDADE TEMPORAL, e em que é
                         que o ano deu — a AVALIAÇÃO CONTÍNUA FINAL. A fronteira
                         entre eles é o traço mais forte da tabela (§17). -->
                    <tr>
                        <th
                            rowspan="3"
                            class="sticky top-0 left-0 z-30 border-r border-b border-border bg-muted px-3 py-2 align-bottom font-medium"
                            scope="col"
                        >
                            Aluno
                        </th>
                        <th
                            :colspan="domainsBlockColumns"
                            class="sticky top-0 z-20 h-8 border-b border-border bg-muted px-3 text-center text-xs font-semibold tracking-wide uppercase"
                            title="O ano de cada domínio: o resultado formal de cada unidade temporal, o movimento entre elas, e o desempenho acumulado dos elementos de avaliação."
                            scope="colgroup"
                        >
                            Resultados por domínio
                        </th>
                        <!-- Cada síntese responde por UMA unidade temporal, e
                             nenhuma delas responde pelo ano — é por isso que são
                             blocos separados e não colunas de um só. -->
                        <th
                            v-for="(period, index) in periods"
                            :key="`bloco-sintese-${period.id}`"
                            rowspan="2"
                            :colspan="synthesisColumns(index)"
                            class="sticky top-0 z-20 border-b border-border bg-muted/70 px-3 text-center text-xs font-semibold tracking-wide uppercase"
                            :class="BLOCK_RULE"
                            scope="colgroup"
                        >
                            Síntese · {{ period.label }}
                        </th>
                        <!-- O ANO, DEPOIS DAS UNIDADES. É o indicador formal, e
                             é o único bloco com a cor do produto — ênfase
                             FUNCIONAL, e não um divisor: se as sínteses
                             levassem a mesma tinta, teriam o mesmo peso e a
                             hierarquia entre as leituras deixaria de existir
                             (§17, §18). -->
                        <th
                            :colspan="finalBlockColumns"
                            class="sticky top-0 z-20 h-8 border-b border-border bg-primary/10 px-3 text-center text-xs font-semibold tracking-wide uppercase"
                            :class="BLOCK_RULE"
                            :title="continuousTitle"
                            :aria-label="continuousTitle"
                            scope="colgroup"
                        >
                            {{ CONTINUOUS_FINAL }}
                        </th>
                    </tr>

                    <!-- OS GRUPOS DENTRO DE CADA BLOCO: um domínio de cada vez,
                         à esquerda; e, no bloco final, o mesmo domínio outra
                         vez, para que a resposta de fim de ano se leia por baixo
                         do nome dele e não de uma abreviatura (§21). -->
                    <tr>
                        <!-- The domain's name, made the most evident thing in
                             the heading: the grouping is read from the block,
                             not by tracing the columns under it (§8, §11). -->
                        <th
                            v-for="(domain, domainIndex) in domains"
                            :key="domain.id"
                            :colspan="domainColumns"
                            class="sticky top-8 z-20 h-8 border-b border-border px-3 text-center text-xs font-semibold tracking-wide text-foreground uppercase"
                            :class="[domainTone(domainIndex), GROUP_RULE]"
                            scope="colgroup"
                        >
                            {{ domain.name }}
                        </th>
                        <th
                            v-for="(domain, domainIndex) in domains"
                            :key="`final-${domain.id}`"
                            :colspan="finalDomainColumns"
                            class="sticky top-8 z-20 h-8 border-b border-border bg-primary/5 px-3 text-center text-xs font-semibold tracking-wide text-foreground uppercase"
                            :class="finalGroupRule(domainIndex)"
                            :title="`${CONTINUOUS_FINAL} — ${domain.name}. ${FINAL_AVERAGE_EXPLANATION}`"
                            scope="colgroup"
                        >
                            {{ domain.name }}
                        </th>
                        <!-- O GLOBAL FECHA O BLOCO, e fecha-o uma vez só: a
                             média do ano não se repete em dois sítios (§15). -->
                        <th
                            :colspan="finalGlobalColumns"
                            class="sticky top-8 z-20 h-8 border-b border-border bg-primary/5 px-3 text-center text-xs font-semibold tracking-wide text-foreground uppercase"
                            :class="GROUP_RULE"
                            :title="continuousTitle"
                            scope="colgroup"
                        >
                            Global
                        </th>
                    </tr>

                    <tr>
                        <template v-for="domain in domains" :key="`sub-${domain.id}`">
                            <template v-for="(period, index) in periods" :key="`sub-${domain.id}-${period.id}`">
                                <!-- O NOME REAL DA UNIDADE, e não «P1». O ano
                                     letivo desta turma tem as unidades que a
                                     escola lhe configurou — semestres, períodos
                                     ou módulos — e cada uma tem o nome que ela
                                     lhe deu. «P1» era uma abreviatura que este
                                     ecrã inventava e que não correspondia a
                                     nada escrito em lado nenhum (§14). -->
                                <th
                                    class="sticky top-16 z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                                    :class="index === 0 ? GROUP_RULE : ''"
                                    :title="`${period.label} — ${domain.name}`"
                                    :aria-label="`${period.label} — ${domain.name}`"
                                    scope="col"
                                >
                                    {{ period.label }}
                                </th>
                                <th
                                    v-if="index > 0"
                                    class="sticky top-16 z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                    :title="`Evolução face a ${periods[index - 1].label} — ${domain.name}`"
                                    :aria-label="`Evolução face a ${periods[index - 1].label} — ${domain.name}`"
                                    scope="col"
                                >
                                    Evol.
                                </th>
                            </template>
                            <!-- «Desemp.» sozinho não dizia desempenho de quê —
                                 podia ser lido como o desempenho do período,
                                 que é outro número na mesma linha. O nome
                                 inteiro e a explicação continuam no `title` e
                                 no texto acessível: a abreviatura nunca é a
                                 única informação (§25). -->
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                                :title="`${ACCUMULATED_LONG} — ${domain.name}. ${ACCUMULATED_EXPLANATION}`"
                                :aria-label="`${ACCUMULATED_LONG} — ${domain.name}. ${ACCUMULATED_EXPLANATION}`"
                                scope="col"
                            >
                                {{ ACCUMULATED_SHORT }}
                            </th>
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/30 px-2 py-1 text-center text-xs font-medium"
                                :title="`Menção qualitativa acumulada — ${domain.name}`"
                                :aria-label="`Menção qualitativa acumulada — ${domain.name}`"
                                scope="col"
                            >
                                Menção
                            </th>
                        </template>

                        <template v-for="(period, index) in periods" :key="`sub-sintese-${period.id}`">
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium"
                                :class="BLOCK_RULE"
                                :title="`Média Ponderada — ${period.label}`"
                                :aria-label="`Média Ponderada — ${period.label}`"
                                scope="col"
                            >
                                MP
                            </th>
                            <th
                                v-if="index > 0"
                                class="sticky top-16 z-20 border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium"
                                :title="`Evolução face a ${periods[index - 1].label}`"
                                :aria-label="`Evolução face a ${periods[index - 1].label}`"
                                scope="col"
                            >
                                Evol.
                            </th>
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                                :title="`${ACCUMULATED_LONG} — ${period.label}. ${ACCUMULATED_EXPLANATION}`"
                                :aria-label="`${ACCUMULATED_LONG} — ${period.label}. ${ACCUMULATED_EXPLANATION}`"
                                scope="col"
                            >
                                {{ ACCUMULATED_SHORT }}
                            </th>
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium"
                                :title="`Proposta do Lapispro — ${period.label}`"
                                :aria-label="`Proposta do Lapispro — ${period.label}`"
                                scope="col"
                            >
                                Prop.
                            </th>
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium"
                                :title="`Autoavaliação global do aluno — ${period.label}`"
                                :aria-label="`Autoavaliação global do aluno — ${period.label}`"
                                scope="col"
                            >
                                Autoav.
                            </th>
                            <th
                                class="sticky top-16 z-20 border-r border-b border-border bg-muted/40 px-2 py-1 text-center text-xs font-medium"
                                :title="`${decision.label} — ${period.label}`"
                                :aria-label="`${decision.label} — ${period.label}`"
                                scope="col"
                            >
                                {{ decision.classifies_by_level ? 'Nível' : 'Classif.' }}
                            </th>
                        </template>

                        <!-- CADA DOMÍNIO FECHA O ANO POR SI, e a resposta lê-se
                             debaixo do nome dele: a média ponderada final e a
                             menção que dela sai. «Final» sozinho não dizia de
                             que é que era o final, e «Aprec.» não se dizia
                             sozinho (§9, §10). -->
                        <template v-for="(domain, domainIndex) in domains" :key="`sub-final-${domain.id}`">
                            <th
                                v-if="showQuantitative"
                                class="sticky top-16 z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                                :class="finalGroupRule(domainIndex)"
                                :title="finalAverageHeaderTitle(domain)"
                                :aria-label="finalAverageHeaderTitle(domain)"
                                scope="col"
                            >
                                {{ FINAL_AVERAGE }}
                            </th>
                            <th
                                class="sticky top-16 z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                                :class="showQuantitative ? '' : finalGroupRule(domainIndex)"
                                :title="finalMentionHeaderTitle(domain)"
                                :aria-label="finalMentionHeaderTitle(domain)"
                                scope="col"
                            >
                                {{ FINAL_MENTION }}
                            </th>
                        </template>

                        <th
                            v-if="showQuantitative"
                            class="sticky top-16 z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium whitespace-nowrap"
                            :class="GROUP_RULE"
                            :title="continuousTitle"
                            :aria-label="continuousTitle"
                            scope="col"
                        >
                            {{ FINAL_AVERAGE }}
                        </th>
                        <th
                            class="sticky top-16 z-20 border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                            :class="showQuantitative ? '' : GROUP_RULE"
                            :title="`Proposta formal do Lapispro para o ano — sai da ${CONTINUOUS.toLowerCase()}, nunca do ${ACCUMULATED.toLowerCase()}.`"
                            :aria-label="`Proposta formal do Lapispro para o ano — sai da ${CONTINUOUS.toLowerCase()}, nunca do ${ACCUMULATED.toLowerCase()}.`"
                            scope="col"
                        >
                            Prop.
                        </th>
                        <th
                            class="sticky top-16 z-20 border-r border-b border-border bg-primary/5 px-2 py-1 text-center text-xs font-medium"
                            :title="`${decision.label} do ano, atribuído pelo professor.`"
                            :aria-label="`${decision.label} do ano, atribuído pelo professor.`"
                            scope="col"
                        >
                            {{ decision.classifies_by_level ? 'Nível' : 'Classif.' }}
                        </th>
                    </tr>
                </thead>

                <tbody class="divide-y divide-border">
                    <tr v-for="student in students" :key="student.enrollment_id" class="hover:bg-muted/20">
                        <th
                            scope="row"
                            class="sticky left-0 z-10 border-r border-border bg-background px-3 py-2 text-left font-medium whitespace-nowrap"
                        >
                            <span class="mr-1.5 text-muted-foreground">{{ student.class_number ?? '—' }}</span>{{ student.name }}
                        </th>

                        <!-- One block per domain of the profile version. -->
                        <template v-for="domain in domains" :key="`${student.enrollment_id}-${domain.id}`">
                            <template v-for="(period, index) in student.periods" :key="`${student.enrollment_id}-${domain.id}-${period.period_id}`">
                                <td class="px-2 py-1.5 text-center tabular-nums" :class="index === 0 ? GROUP_RULE : ''">
                                    <span :class="{ 'text-muted-foreground': (domainCell(period, domain.id)?.weighted_average ?? null) === null }">
                                        {{ pct(domainCell(period, domain.id)?.weighted_average ?? null) }}
                                    </span>
                                    <CircleAlert
                                        v-if="domainCell(period, domain.id)?.coverage_warning"
                                        class="ml-0.5 inline size-3 text-amber-500"
                                        title="Cobertura parcial — o resultado assenta apenas em parte dos elementos aplicáveis."
                                    />
                                    <!-- What the student said about this domain,
                                         beside what the evidence says (§7).
                                         «A3» OBRIGAVA A DECIFRAR: o «A» podia
                                         ser um nível, uma alínea ou um aviso, e
                                         uma legenda no fundo da página não
                                         acompanha quem está a ler a célula.
                                         «Auto 3» diz-se sozinho, e continua a
                                         ser informação de apoio que não entra
                                         em cálculo nenhum (§15, §61). -->
                                    <sup
                                        v-if="domainCell(period, domain.id)?.self_assessment"
                                        class="ml-0.5 rounded bg-muted px-1 text-[10px] font-normal whitespace-nowrap text-muted-foreground"
                                        :title="selfAssessmentTitle(domainCell(period, domain.id)?.self_assessment ?? null)"
                                        :aria-label="selfAssessmentTitle(domainCell(period, domain.id)?.self_assessment ?? null)"
                                    >Auto {{ domainCell(period, domain.id)?.self_assessment?.code }}</sup>
                                </td>
                                <td
                                    v-if="index > 0"
                                    class="px-2 py-1.5 text-center text-xs tabular-nums"
                                    :title="trendTitle(domainCell(period, domain.id)?.evolution ?? null, null)"
                                >
                                    <span :class="[TREND_SHAPE, trendClasses(domainCell(period, domain.id)?.evolution ?? null)]">
                                        <span :class="{ 'text-muted-foreground': (domainCell(period, domain.id)?.evolution ?? null) === null }">
                                            {{ trendPoints(domainCell(period, domain.id)?.evolution ?? null) }}
                                        </span>
                                        <span>{{ trendArrow(domainCell(period, domain.id)?.evolution ?? null) }}</span>
                                    </span>
                                </td>
                            </template>
                            <!-- O NÚMERO ABRE A CONTA QUE O PRODUZIU. É um
                                 botão e não um ícone ao lado: uma coluna de
                                 ícones em todas as células tornaria a grelha
                                 mais pesada do que a explicação que oferece
                                 (§5). Uma célula sem valor não abre nada — não
                                 há conta nenhuma para mostrar. -->
                            <td class="bg-muted/20 px-2 py-1.5 text-center tabular-nums">
                                <button
                                    v-if="(domainCell(student.periods[lastPeriodIndex], domain.id)?.accumulated_average ?? null) !== null"
                                    type="button"
                                    class="rounded px-1 underline decoration-dotted underline-offset-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                    :title="`Ver de onde vem este valor — ${ACCUMULATED_LONG} de ${domain.name}. ${ACCUMULATED_NOT_AN_AVERAGE}`"
                                    :aria-label="`Ver a decomposição do ${ACCUMULATED.toLowerCase()} de ${student.name} em ${domain.name}`"
                                    @click="openBreakdown(student, student.periods[lastPeriodIndex], domain.ulid)"
                                >
                                    {{ pct(domainCell(student.periods[lastPeriodIndex], domain.id)?.accumulated_average ?? null) }}
                                </button>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <!-- The band that accumulated figure falls in, on the
                                 profile's own scale. Never a threshold decided
                                 here, and «—» where the scale has no band for it. -->
                            <td class="bg-muted/20 px-2 py-1.5 text-center whitespace-nowrap">
                                <span
                                    v-if="domainCell(student.periods[student.periods.length - 1], domain.id)?.mention"
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="levelClasses(domainCell(student.periods[student.periods.length - 1], domain.id)?.mention ?? null)"
                                    :title="`Menção qualitativa acumulada — ${domain.name}`"
                                >{{ domainCell(student.periods[student.periods.length - 1], domain.id)?.mention?.label }}</span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>

                        </template>

                        <!-- …then the year read whole, period by period. -->
                        <template v-for="(period, index) in student.periods" :key="`${student.enrollment_id}-sintese-${period.period_id}`">
                            <td
                                class="px-2 py-1.5 text-center font-medium tabular-nums"
                                :class="BLOCK_RULE"
                            >
                                <span :class="{ 'text-muted-foreground': period.weighted_average === null }">{{ pct(period.weighted_average) }}</span>
                                <CircleAlert
                                    v-if="period.coverage_warning"
                                    class="ml-0.5 inline size-3 text-amber-500"
                                    title="Cobertura parcial — o resultado assenta apenas em parte dos elementos aplicáveis."
                                />
                            </td>
                            <td
                                v-if="index > 0"
                                class="px-2 py-1.5 text-center text-xs tabular-nums"
                                :title="trendTitle(period.evolution, period.accumulated_average)"
                            >
                                <span :class="[TREND_SHAPE, trendClasses(period.evolution)]">
                                    <span :class="{ 'text-muted-foreground': period.evolution === null }">{{ trendPoints(period.evolution) }}</span>
                                    <span>{{ trendArrow(period.evolution) }}</span>
                                </span>
                            </td>
                            <!-- O mesmo número, e a mesma porta — mas aqui o
                                 acumulado é GLOBAL, e o que a decomposição
                                 mostra são os domínios e os seus pesos, porque
                                 é disso que este número é feito. -->
                            <td class="bg-muted/20 px-2 py-1.5 text-center tabular-nums">
                                <button
                                    v-if="period.accumulated_average !== null"
                                    type="button"
                                    class="rounded px-1 underline decoration-dotted underline-offset-2 hover:bg-muted focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                    :title="`Ver de onde vem este valor — ${ACCUMULATED_LONG} até ao fim do ${period.period_label}. ${ACCUMULATED_NOT_AN_AVERAGE}`"
                                    :aria-label="`Ver a decomposição do ${ACCUMULATED.toLowerCase()} de ${student.name} até ao fim do ${period.period_label}`"
                                    @click="openBreakdown(student, period, null)"
                                >
                                    {{ pct(period.accumulated_average) }}
                                </button>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span v-if="period.classification?.proposal.value" class="rounded bg-muted px-1.5 py-0.5">
                                    {{ proposalText(period.classification.proposal) }}
                                </span>
                                <span v-else class="text-muted-foreground">—</span>
                            </td>
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span
                                    v-if="period.self_assessment"
                                    class="rounded px-1.5 py-0.5"
                                    :class="levelClasses(period.self_assessment)"
                                    :title="`Autoavaliação do aluno: ${period.self_assessment.code} — ${period.self_assessment.label}`"
                                >{{ period.self_assessment.code }}</span>
                                <span v-else class="text-muted-foreground" title="O aluno não respondeu à autoavaliação global deste período.">—</span>
                            </td>
                            <!-- The decision of THAT period, kept as it was: a
                                 quadro síntese that showed only the latest would
                                 be hiding the year it exists to show (§10). -->
                            <td class="px-2 py-1.5 text-center tabular-nums">
                                <span
                                    v-if="period.classification?.final"
                                    class="inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 font-bold"
                                    :class="levelClasses(period.classification.final)"
                                    :title="`${period.classification.final.code} — ${period.classification.final.label}`"
                                >
                                    {{ period.classification.final.code }}
                                    <Lock v-if="period.classification.is_published" class="size-3 font-normal opacity-60" />
                                </span>
                                <span v-else class="text-muted-foreground" title="Ainda por atribuir — o Lapispro propõe, o professor decide.">—</span>
                            </td>
                        </template>

                        <!-- ============ A AVALIAÇÃO CONTÍNUA FINAL ============
                             Primeiro cada domínio — «como é que este aluno
                             terminou em Oralidade?» —, e só depois o ano
                             inteiro. Os números são exatamente os mesmos que
                             estavam nas colunas «Final» e «Aprec.» de cada
                             bloco de domínio: o que mudou foi o sítio onde se
                             leem, que é o que faltava para se perceberem
                             (§2, §6).

                             SEM TINTA DE ORGANIZAÇÃO NAS CÉLULAS DE DADOS
                             (§15): a hierarquia deste bloco está no cabeçalho e
                             nas barras que o separam, e é lá que tem de ficar.
                             Uma cor de fundo aqui competiria com a tinta da
                             tendência, que é a única num corpo de tabela que
                             significa alguma coisa. -->
                        <template v-for="(domain, domainIndex) in domains" :key="`${student.enrollment_id}-final-${domain.id}`">
                            <td
                                v-if="showQuantitative"
                                class="px-2 py-1.5 text-center font-medium tabular-nums"
                                :class="finalGroupRule(domainIndex)"
                            >
                                <span
                                    :class="{ 'text-muted-foreground': (domainFinal(student, domain.id)?.normalized_value ?? null) === null }"
                                    :title="domainFinalTitle(student, domain)"
                                >{{ pct(domainFinal(student, domain.id)?.normalized_value ?? null) }}</span>
                            </td>

                            <!-- A MENÇÃO QUE VALE no fim do ano para este
                                 domínio: a decisão do professor quando existe,
                                 a proposta que sai da média quando não — e a
                                 proposta não é uma pendência (§2). -->
                            <td
                                class="px-2 py-1.5 text-center whitespace-nowrap"
                                :class="showQuantitative ? '' : finalGroupRule(domainIndex)"
                            >
                                <!-- O VALOR É A PORTA. Clicar na menção final
                                     abre o painel que a decide; sem autorização
                                     não há botão nenhum, e o valor lê-se na
                                     mesma. -->
                                <component
                                    :is="canDecideDomains && domainFinalAppreciation(student, domain.id) ? 'button' : 'span'"
                                    v-if="domainFinalAppreciation(student, domain.id)"
                                    :type="canDecideDomains ? 'button' : undefined"
                                    class="rounded px-1.5 py-0.5 text-xs"
                                    :class="[
                                        levelClasses(domainFinalAppreciation(student, domain.id)!.proposed),
                                        domainFinalAppreciation(student, domain.id)!.decided ? 'font-semibold' : '',
                                        canDecideDomains
                                            ? 'hover:ring-1 hover:ring-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none'
                                            : '',
                                    ]"
                                    :title="domainFinalTitle(student, domain)"
                                    :aria-label="canDecideDomains ? domainFinalActionLabel(student, domain) : domainFinalTitle(student, domain)"
                                    :aria-haspopup="canDecideDomains ? 'dialog' : undefined"
                                    @click="canDecideDomains && openFinalDecision(student, domain)"
                                >{{
                                    showQuantitative
                                        ? domainFinalAppreciation(student, domain.id)!.level?.code
                                        : domainFinalAppreciation(student, domain.id)!.level?.label
                                }}</component>
                                <sup
                                    v-if="domainFinalAppreciation(student, domain.id)?.decided"
                                    class="ml-0.5 rounded bg-muted px-1 text-[10px] font-normal whitespace-nowrap text-muted-foreground"
                                    :title="domainFinalTitle(student, domain)"
                                    :aria-label="domainFinalTitle(student, domain)"
                                >prof.</sup>
                                <span
                                    v-if="!domainFinalAppreciation(student, domain.id)"
                                    class="text-muted-foreground"
                                    :title="domainFinalTitle(student, domain)"
                                >—</span>
                            </td>
                        </template>

                        <!-- …e o ano inteiro, no fim, uma vez só. A média
                             formal, a proposta que sai dela, e a decisão do
                             professor. A autoavaliação NÃO aparece aqui de
                             propósito: o aluno autoavalia-se em cada unidade, e
                             não existe uma autoavaliação do ano. Repetir aqui a
                             da última unidade seria dar-lhe um significado que
                             ela não tem (§14). -->
                        <td v-if="showQuantitative" class="px-2 py-1.5 text-center font-medium tabular-nums" :class="GROUP_RULE">
                            <span
                                :class="{
                                    'text-muted-foreground':
                                        (continuousByEnrollment.get(student.enrollment_id)?.normalized_value ?? null) === null,
                                }"
                                :title="continuousTitle"
                            >{{ pct(continuousByEnrollment.get(student.enrollment_id)?.normalized_value ?? null) }}</span>
                        </td>
                        <!-- COM OS QUANTITATIVOS DESLIGADOS, A PALAVRA. Um «3»
                             onde devia estar «Suficiente» não é uma pauta sem
                             números — é a mesma pauta com o número disfarçado
                             de menção (§60). O código continua no `title`. -->
                        <td class="px-2 py-1.5 text-center tabular-nums" :class="showQuantitative ? '' : GROUP_RULE">
                            <span
                                v-if="continuousByEnrollment.get(student.enrollment_id)?.level"
                                class="rounded px-1.5 py-0.5 italic"
                                :class="levelClasses(continuousByEnrollment.get(student.enrollment_id)!.level)"
                                :title="`Proposta do Lapispro para a ${CONTINUOUS.toLowerCase()}: ${continuousByEnrollment.get(student.enrollment_id)!.level!.code} — ${continuousByEnrollment.get(student.enrollment_id)!.level!.label}. ${continuousFormula}`"
                            >{{
                                showQuantitative
                                    ? continuousByEnrollment.get(student.enrollment_id)!.level!.code
                                    : continuousByEnrollment.get(student.enrollment_id)!.level!.label
                            }}</span>
                            <span
                                v-else-if="continuousByEnrollment.get(student.enrollment_id)?.proposal?.value"
                                class="rounded bg-muted px-1.5 py-0.5 italic"
                                :title="continuousFormula"
                            >{{ continuousByEnrollment.get(student.enrollment_id)!.proposal.value }}</span>
                            <span
                                v-else
                                class="text-muted-foreground"
                                title="Sem resultados formais suficientes para uma média contínua."
                            >—</span>
                        </td>
                        <td class="px-2 py-1.5 text-center tabular-nums">
                            <span
                                v-if="continuousByEnrollment.get(student.enrollment_id)?.decision?.final"
                                class="rounded px-1.5 py-0.5 font-bold"
                                :title="`Decisão do professor para o ano: ${continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.code} — ${continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.label}`"
                            >{{
                                showQuantitative
                                    ? continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.code
                                    : continuousByEnrollment.get(student.enrollment_id)!.decision!.final!.label
                            }}</span>
                            <span
                                v-else
                                class="text-muted-foreground"
                                title="Ainda por decidir — o Lapispro propõe, o professor decide."
                            >—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- A LEGENDA DA LEITURA POR MOMENTOS. Nada aqui é decorativo: cada
             frase existe porque alguma coisa na tabela é dita por uma cor, uma
             seta ou uma inclinação, e nenhuma delas pode ser a única informação
             que uma pessoa recebe (§25, §27). -->
        <div
            v-if="schoolClass.has_profile && students.length > 0 && view === 'moments'"
            class="space-y-2 text-xs text-muted-foreground"
        >
            <p class="flex items-start gap-2">
                <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    Cada coluna é um <strong>momento estruturante</strong> do ano. Os
                    <strong>momentos formais</strong> — o que fecha cada
                    {{ synopsis.periods[0]?.kind_label.toLowerCase() ?? 'unidade' }} — são o resultado da unidade e
                    são os únicos que entram na <strong>avaliação contínua</strong>. Os
                    <strong>momentos intercalares</strong> são fotografias informativas: mostram o que era verdade no
                    dia em que a pauta foi guardada, servem para ler evolução, e <strong>não entram na média</strong>.
                    Um momento intercalar que ninguém guardou aparece vazio — não é recalculado com os números de hoje.
                </span>
            </p>
            <p class="flex items-start gap-2">
                <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    A <strong>apreciação</strong> de cada célula é a que está a valer: a
                    <span class="font-semibold">decisão do professor</span> quando existe, a
                    <span class="italic">proposta do Lapispro</span> quando o professor não alterou nada — e uma
                    proposta vale sem precisar de ser aprovada. A cor vem da <strong>posição do nível na escala</strong>
                    <template v-if="schoolClass.scale_name"> ({{ schoolClass.scale_name }})</template>, nunca do número
                    que ele tem; <strong>↑ Evolução</strong>, <strong>→ Manutenção</strong> e
                    <strong>↓ Regressão</strong> comparam essa apreciação com a do momento estruturante anterior.
                    Nenhuma cor e nenhuma seta está sozinha: o código, o nível e a frase inteira vão sempre no texto da
                    célula ou na sua descrição.
                </span>
            </p>
            <p class="flex items-start gap-2">
                <CircleAlert class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    <strong>Auto 1</strong>, <strong>Auto 2</strong>… em expoente, ao abrir um momento, são a
                    <strong>autoavaliação do aluno</strong> nesse domínio: o número é o nível que ele próprio se
                    atribuiu. É informação de apoio: não entra em cálculo nenhum. E «—» significa
                    <strong>sem elementos</strong>, nunca zero.
                </span>
            </p>
            <p v-if="scaleBands.length > 0" class="flex flex-wrap items-center gap-1.5">
                <span>Escala:</span>
                <span
                    v-for="band in scaleBands"
                    :key="band.sequence"
                    class="rounded px-1.5 py-0.5"
                    :class="levelClasses(band)"
                >{{ band.code }} — {{ band.label }}</span>
            </p>
        </div>

        <!-- A LEGENDA SEGUE OS BLOCOS DA GRELHA, e não a ordem por que as
             colunas foram sendo acrescentadas. Depois de a tabela passar a
             dizer-se a si própria — quatro blocos, cada um com o seu título —,
             a legenda deixou de precisar de reconstruir a estrutura por
             palavras: o que aqui fica é o que a UI não consegue mostrar, que é
             a diferença entre as duas leituras e o significado das marcas
             discretas (§31). -->
        <dl v-if="view === 'domains'" class="grid gap-x-6 gap-y-2 text-xs text-muted-foreground sm:grid-cols-2">
            <div>
                <dt class="font-semibold text-foreground">Resultados por domínio</dt>
                <dd>
                    Por unidade<template v-if="periods.length"> ({{ periods.map((period) => period.label).join(', ') }})</template>,
                    a <strong>Média Ponderada</strong> dessa unidade e a <strong>Evol.</strong> face à anterior —
                    sempre valores da própria unidade. <strong>{{ ACCUMULATED_SHORT }}</strong> é o
                    {{ ACCUMULATED_LONG.toLowerCase() }} e a <strong>Menção</strong> é a banda em que ele cai;
                    <strong>clique no valor</strong> para ver a conta que o produziu.
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-foreground">Síntese de cada unidade</dt>
                <dd>
                    O aluno inteiro nessa unidade: Média Ponderada, evolução, {{ ACCUMULATED_SHORT.toLowerCase() }},
                    <strong>Prop.</strong>, a <strong>Autoav.</strong> global e o
                    <strong>{{ decision.label }}</strong>. Cada síntese responde por uma unidade temporal — nenhuma
                    responde pelo ano.
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-foreground">{{ CONTINUOUS_FINAL }}</dt>
                <dd>
                    Em que é que o ano deu, domínio a domínio e no conjunto. A <strong>{{ FINAL_AVERAGE }}</strong>
                    é a média dos resultados formais das unidades, e só deles: {{ continuousFormula }} As
                    fotografias intercalares não entram, e o {{ ACCUMULATED.toLowerCase() }} também não — é a outra
                    leitura do ano. A <strong>{{ FINAL_MENTION }}</strong> é a que vale: a proposta que sai dessa
                    média, ou a <strong>decisão do professor</strong> quando ele a alterou, a negrito e marcada com
                    «prof.». Uma proposta que ninguém alterou <strong>vigora</strong>; não é uma pendência.
                </dd>
            </div>
            <div>
                <dt class="font-semibold text-foreground">As marcas</dt>
                <dd>
                    <strong>Auto</strong> em expoente é a autoavaliação do aluno, e não entra em cálculo nenhum. Um
                    fundo <span class="rounded bg-emerald-50 px-1 dark:bg-emerald-950/40">verde</span> ou
                    <span class="rounded bg-rose-50 px-1 dark:bg-rose-950/40">vermelho</span> indica
                    <strong>tendência</strong> (↑ ↓), independente da cor do nível, que indica
                    <strong>desempenho</strong> na escala do
                    perfil<template v-if="schoolClass.scale_name"> ({{ schoolClass.scale_name }})</template>.
                    «—» significa sem elementos, nunca zero.
                </dd>
            </div>
        </dl>

        <!-- AS DUAS LEITURAS DO ANO, ditas lado a lado e por extenso. Elas
             respondem a perguntas diferentes e dão números diferentes; um
             professor que veja as duas sem esta frase pode razoavelmente supor
             que são a mesma coisa somada de outra maneira. -->
        <div
            v-if="schoolClass.has_profile && students.length > 0"
            class="grid gap-3 rounded-lg border border-border bg-muted/10 px-4 py-3 text-xs text-muted-foreground sm:grid-cols-2"
        >
            <p>
                <strong class="text-foreground">{{ CONTINUOUS }}</strong> — indicador formal.
                {{ CONTINUOUS_EXPLANATION }} É desta que sai a proposta de nível.
            </p>
            <p>
                <strong>{{ ACCUMULATED_LONG }}</strong> ({{ ACCUMULATED }}) — leitura complementar.
                {{ ACCUMULATED_EXPLANATION }} {{ ACCUMULATED_NOT_AN_AVERAGE }}
                <template v-if="view === 'domains'">Clique num valor acumulado para ver a conta que o produziu.</template>
            </p>
        </div>

        <!-- DE ONDE VEM AQUELE NÚMERO. Fora da tabela, porque é um painel sobre
             ela — e montado uma vez só, porque a pergunta faz-se sobre uma
             célula de cada vez. -->
        <AccumulatedBreakdownPanel
            :url="breakdownUrl"
            :student-name="breakdownStudent"
            :has-declared-period-weights="synopsis.continuous.weights_declared"
            @close="breakdownUrl = null"
        />

        <!-- A CONCLUSÃO DO ANO NUM DOMÍNIO, decidida. Montado uma vez só: a
             decisão toma-se sobre uma célula de cada vez. -->
        <FinalDomainDecisionDialog
            :student-name="decidingStudent?.name ?? null"
            :domain-name="decidingDomain?.name ?? null"
            :reading="decidingReading"
            :decision="decision"
            :saving="decisionSaving"
            :error="decisionError"
            @close="closeFinalDecision"
            @save="saveFinalDecision"
        />
    </div>
</template>
