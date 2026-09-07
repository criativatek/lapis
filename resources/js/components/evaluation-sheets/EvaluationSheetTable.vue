<script setup lang="ts">
import { computed } from 'vue';
import CoverageWarning from '@/components/CoverageWarning.vue';
import {
    appreciationTone,
    assignedLevel as readAssignedLevel,
    domainAppreciation,
    overallAppreciation,
} from '@/lib/appreciation';
import type { Appreciation, ToneableBand } from '@/lib/appreciation';
import { pct } from '@/lib/results';
import type {
    EvaluationSheetDomain,
    EvaluationSheetSelfAssessment,
    EvaluationSheetStudent,
    EvaluationSheetStudentDomain,
} from '@/types';

/**
 * A grelha da Pauta de Avaliação — UM ÚNICO componente.
 *
 * A pauta viva e a pauta guardada usam exatamente este componente. É isso que
 * garante que uma fotografia se lê com as mesmas colunas, as mesmas cores e a
 * mesma estrutura do ecrã de onde foi tirada; duas cópias do markup seriam
 * duas coisas a divergir em silêncio na primeira correção feita só de um lado.
 *
 * NÃO CALCULA NADA. Recebe domínios e alunos já resolvidos pelo servidor —
 * vivos num caso, congelados no outro — e limita-se a mostrá-los. Os três
 * toggles SÓ ESCONDEM: nenhum deles toca nas props recebidas.
 *
 * E NÃO DECIDE NADA. `decidable` liga a AÇÃO de atribuir, não a decisão em si:
 * a grelha emite quem o professor quer classificar e é o ecrã que abre o painel
 * e escreve. Desligado é o que a pauta guardada usa — uma fotografia não tem
 * botões, porque não há nada no passado por decidir.
 *
 * ISSO VALE AGORA TAMBÉM PARA CADA DOMÍNIO. A apreciação de «Leitura» é
 * clicável pela mesma razão que o «Nível atribuído» é: é uma decisão do
 * professor, e uma decisão toma-se onde a informação está. Não há um botão em
 * cada célula — o VALOR é o botão, e as trinta linhas continuam a ler-se como
 * uma tabela e não como um painel de controlo.
 *
 * COMO SE LÊ UMA MENÇÃO não é decidido aqui: vive em `@/lib/appreciation`,
 * porque a mesma regra vale para o domínio, para o global e para o nível
 * atribuído, e três cópias dela seriam três sítios a divergir.
 */

const props = withDefaults(
    defineProps<{
        domains: EvaluationSheetDomain[];
        students: EvaluationSheetStudent[];
        showQuantitative: boolean;
        showDomainDetail: boolean;
        showWarnings: boolean;
        /**
         * A autoavaliação, quando a pauta a tem. O ecrã decide se a mostra; a
         * grelha nunca a inventa nem a soma a coisa alguma (§15).
         */
        showSelfAssessment?: boolean;
        /** Só na pauta viva. A guardada mostra os mesmos valores, sem ações. */
        decidable?: boolean;
        /**
         * A escala configurada, para dar COR à apreciação pela posição do nível
         * nela (§24).
         *
         * VAZIA NA PAUTA GUARDADA, e de propósito. Abrir uma fotografia não
         * pode ir buscar nada ao presente — a escala pode ter mudado desde
         * então —, e uma cor tirada da escala de hoje seria uma afirmação sobre
         * dezembro feita com o que se sabe em junho (§15). Sem bandas não há
         * cor, e o texto guardado lê-se tal e qual.
         */
        scaleBands?: ToneableBand[];
    }>(),
    { showSelfAssessment: false, decidable: false, scaleBands: () => [] },
);

const emit = defineEmits<{
    decide: [student: EvaluationSheetStudent];
    decideDomain: [payload: { student: EvaluationSheetStudent; domain: EvaluationSheetDomain }];
}>();

const domainColumns = computed(() => props.domains.map((domain) => ({ id: domain.domain_id, name: domain.name })));

function studentDomain(student: EvaluationSheetStudent, domainId: number): EvaluationSheetStudentDomain | undefined {
    return student.domains.find((domain) => domain.domain_id === domainId);
}

/** A apreciação de um domínio, já resolvida para a vista que está ligada. */
function domainCell(student: EvaluationSheetStudent, domainId: number): Appreciation {
    return domainAppreciation(studentDomain(student, domainId), props.showQuantitative);
}

/**
 * A cor de uma apreciação — pela posição do nível na escala, nunca pelo número.
 *
 * A COR NUNCA É A ÚNICA INFORMAÇÃO (§25). O código ou o rótulo ficam escritos na
 * célula, e a frase inteira («Decisão do professor: 4 — Bom») já viajava no
 * `title` e no texto acessível antes de existir cor nenhuma.
 */
function toneOf(appreciation: Appreciation): string {
    return appreciationTone(appreciation, props.scaleBands);
}

function overallCell(student: EvaluationSheetStudent): Appreciation {
    return overallAppreciation(student.overall, props.showQuantitative);
}

function assignedLevel(student: EvaluationSheetStudent): Appreciation {
    return readAssignedLevel(student.classification, props.showQuantitative);
}

/**
 * Se a coluna final oferece uma ação a este aluno.
 *
 * A resposta é do SERVIDOR (`can_decide`) — quem sabe se uma classificação
 * ainda pode ser escrita é o estado da linha, não o ecrã. Sem essa resposta
 * (uma pauta guardada, uma linha sem morada) não há ação nenhuma a oferecer.
 */
function isDecidable(student: EvaluationSheetStudent): boolean {
    return props.decidable && student.can_decide === true && (student.enrollment_ulid ?? null) !== null;
}

/**
 * Se a apreciação de UM DOMÍNIO pode ser decidida aqui.
 *
 * DELIBERADAMENTE DIFERENTE DE `isDecidable`. A classificação global fecha com
 * a publicação; a apreciação de um domínio não tem publicação nenhuma e
 * continua aberta enquanto a pauta o estiver (§9). O que ela precisa é de duas
 * moradas — o aluno e o domínio —, e sem qualquer uma delas não há ação a
 * oferecer em vez de haver um sítio inventado para onde escrever.
 */
function isDomainDecidable(student: EvaluationSheetStudent, domain: EvaluationSheetDomain): boolean {
    return (
        props.decidable &&
        (student.enrollment_ulid ?? null) !== null &&
        (domain.domain_ulid ?? null) !== null
    );
}

/** Publicada: o valor fica, e deixa de haver o que alterar aqui. */
function isPublished(student: EvaluationSheetStudent): boolean {
    return student.classification?.status === 'published';
}

/**
 * O nome da ação, dito por inteiro para quem não vê a coluna.
 *
 * «Alterar» sozinho, lido por um leitor de ecrã numa tabela de trinta linhas,
 * não diz de quem — e a decisão errada seria escrita no aluno errado.
 */
function actionLabel(student: EvaluationSheetStudent): string {
    const level = assignedLevel(student);

    return level.origin === 'decided'
        ? `Alterar a classificação de ${student.name} — atualmente ${level.text}`
        : `Atribuir classificação a ${student.name}`;
}

/**
 * O mesmo, para um domínio: quem, qual domínio, e o que lá está agora.
 *
 * A frase diz sempre DE QUEM É O JUÍZO que está na célula. Sem isso, «Alterar
 * a apreciação de Leitura de Ana Marques — atualmente 4» não distinguiria uma
 * proposta que ninguém reviu de uma decisão já tomada, e as duas pedem coisas
 * diferentes a quem lê.
 */
function domainActionLabel(student: EvaluationSheetStudent, domain: EvaluationSheetDomain): string {
    const cell = domainCell(student, domain.domain_id);
    const who = `${domain.name} de ${student.name}`;

    if (cell.origin === 'none') {
        return `Atribuir apreciação de ${who}`;
    }

    return `Alterar a apreciação de ${who} — ${cell.description}`;
}

/**
 * A autoavaliação, dita por inteiro.
 *
 * O CÓDIGO É O QUE SE MOSTRA e a menção acompanha-o no `title`, exatamente como
 * um nível — é o mesmo tipo de juízo, escrito pelo aluno em vez do professor. A
 * frase começa por dizer DE QUEM É, porque um «4» solto numa linha ao lado do
 * nível atribuído seria lido como uma segunda nota.
 */
function selfAssessmentTitle(level: EvaluationSheetSelfAssessment | null | undefined, subject?: string): string | undefined {
    if (!level) {
        return undefined;
    }

    const what = subject === undefined ? 'Autoavaliação do aluno' : `Autoavaliação do aluno — ${subject}`;

    return `${what}: ${level.code} — ${level.label}`;
}

/** Fundo muito suave na cor do domínio — identidade visual, nunca desempenho. */
function domainHeaderStyle(color: string): Record<string, string> {
    return { backgroundColor: `${color}66` };
}

function domainCellStyle(color: string): Record<string, string> {
    return { backgroundColor: `${color}26` };
}
</script>

<template>
    <!-- `evaluation-sheet-grid` é um GANCHO DE IMPRESSÃO, não estilo. No papel
         não há scroll nem colunas fixas: o ecrã que imprime precisa de um nome
         estável para desligar o `max-h`/`overflow` e o `sticky` desta grelha,
         e um seletor pelas classes utilitárias partir-se-ia na primeira vez que
         alguém mudasse o `70vh`. -->
    <div class="evaluation-sheet-grid max-h-[70vh] overflow-auto rounded-lg border border-border">
        <!-- `min-w-full`, não `w-full`: com muitos domínios a tabela é mais
             larga do que o contentor e as colunas têm de manter a largura
             natural (senão o cabeçalho da coluna fixa é espremido e cortado).
             O contentor é que rola. -->
        <table class="min-w-full border-collapse text-sm">
            <thead>
                <!-- Fundos OPACOS em tudo o que é sticky. Um `bg-muted/50`
                     deixa passar o que desliza por baixo: numa turma de 20-30
                     alunos o cabeçalho fica ilegível sobre as linhas, e a
                     coluna fixa mistura-se com a apreciação que passa sob ela. -->
                <tr class="sticky top-0 z-20 bg-muted text-left text-xs">
                    <th rowspan="2" class="sticky left-0 z-30 border-b border-border bg-muted px-3 py-2 align-bottom font-medium shadow-[8px_0_8px_-6px_rgba(0,0,0,0.10)]">
                        Aluno
                    </th>
                    <template v-if="showDomainDetail">
                        <th
                            v-for="domain in domains"
                            :key="domain.domain_id"
                            :colspan="showQuantitative ? 2 : 1"
                            class="border-b border-l border-border px-3 py-1.5 text-center font-semibold"
                            :style="domainHeaderStyle(domain.color)"
                        >
                            {{ domain.name }}
                        </th>
                    </template>
                    <th
                        :colspan="showQuantitative ? 2 : 1"
                        class="border-b border-l-2 border-border bg-muted/70 px-3 py-1.5 text-center font-semibold"
                    >
                        Global
                    </th>
                    <!-- O QUE O ALUNO DISSE DE SI, entre o que a evidência diz e
                         o que o professor decide — é exatamente aí que ela serve
                         para alguma coisa. Uma síntese curta: o juízo global. O
                         detalhe por domínio vive nas células dos domínios, não
                         numa segunda tabela deitada de lado (§12). -->
                    <th
                        v-if="showSelfAssessment"
                        rowspan="2"
                        class="border-b border-l-2 border-border bg-muted px-3 py-2 text-center align-bottom font-medium"
                    >
                        Autoavaliação
                    </th>
                    <!-- Fixa à direita pela mesma razão que «Aluno» é fixa à
                         esquerda: é a coluna da DECISÃO. Numa turma com cinco
                         domínios a tabela é mais larga do que o ecrã, e a
                         coluna que não pode desaparecer no scroll é
                         precisamente esta (§11 — legibilidade é requisito). -->
                    <th
                        rowspan="2"
                        class="sticky right-0 z-30 border-b border-l-2 border-border bg-muted px-3 py-2 text-center align-bottom font-medium shadow-[-8px_0_8px_-6px_rgba(0,0,0,0.10)]"
                    >
                        <!-- Quebra deliberada em duas linhas: a coluna é
                             estreita e num ecrã de telemóvel «atribuído» ficava
                             cortado. O ESPAÇO DENTRO DO PRIMEIRO SPAN não é
                             descuido — sem ele o texto acessível da célula
                             lê-se «Nívelatribuído», uma palavra que não existe,
                             e é isso que um leitor de ecrã anuncia. Visualmente
                             não muda nada: um espaço no fim de uma linha
                             colapsa. -->
                        <span class="block">Nível </span>
                        <span class="block">atribuído</span>
                    </th>
                </tr>
                <tr class="sticky z-20 bg-muted text-left text-[11px] text-muted-foreground" style="top: 2.25rem">
                    <template v-if="showDomainDetail">
                        <template v-for="domain in domains" :key="`sub-${domain.domain_id}`">
                            <th v-if="showQuantitative" class="border-b border-l border-border px-2 py-1 text-center font-normal" :style="domainCellStyle(domain.color)">
                                Quant.
                            </th>
                            <th class="border-b border-border px-2 py-1 text-center font-normal" :class="showQuantitative ? '' : 'border-l'" :style="domainCellStyle(domain.color)">
                                Apreciação
                            </th>
                        </template>
                    </template>
                    <th v-if="showQuantitative" class="border-b border-l-2 border-border bg-muted/40 px-2 py-1 text-center font-normal">
                        Quant.
                    </th>
                    <th class="border-b border-border bg-muted/40 px-2 py-1 text-center font-normal" :class="showQuantitative ? 'border-l' : 'border-l-2'">
                        Apreciação
                    </th>
                </tr>
            </thead>
            <tbody>
                <tr
                    v-for="(student, index) in students"
                    :key="student.enrollment_id"
                    :class="index % 2 === 1 ? 'bg-muted/10' : ''"
                    class="hover:bg-muted/20"
                >
                    <!-- Sem a risca alternada nas duas colunas fixas: a risca é
                         `bg-muted/10` e ganharia ao `bg-background`, deixando a
                         célula translúcida — as linhas passariam por baixo dela.
                         A risca continua a ler-se em todas as colunas que rolam. -->
                    <td class="sticky left-0 z-10 border-b border-border bg-background px-3 py-2 whitespace-nowrap shadow-[8px_0_8px_-6px_rgba(0,0,0,0.10)]">
                        <span class="text-muted-foreground">{{ student.class_number ?? '—' }}</span>
                        <span class="ml-1.5 font-medium">{{ student.name }}</span>
                    </td>

                    <template v-if="showDomainDetail">
                        <template v-for="domain in domains" :key="`cell-${student.enrollment_id}-${domain.domain_id}`">
                            <td
                                v-if="showQuantitative"
                                class="border-b border-l border-border px-2 py-2 text-center tabular-nums"
                                :style="domainCellStyle(domain.color)"
                            >
                                <span :class="{ 'text-muted-foreground': studentDomain(student, domain.domain_id)?.normalized_value == null }">
                                    {{ pct(studentDomain(student, domain.domain_id)?.normalized_value ?? null) }}
                                </span>
                                <CoverageWarning
                                    v-if="showWarnings && studentDomain(student, domain.domain_id)?.has_coverage_warning"
                                    :coverage="studentDomain(student, domain.domain_id)!.coverage"
                                    :has-value="(studentDomain(student, domain.domain_id)?.normalized_value ?? null) !== null"
                                    :domains="domainColumns"
                                />
                            </td>
                            <td
                                class="border-b border-border px-2 py-2 text-center"
                                :class="showQuantitative ? '' : 'border-l'"
                                :style="domainCellStyle(domain.color)"
                            >
                                <!-- O VALOR É O BOTÃO. Um botão «alterar» em
                                     cada uma de cento e cinquenta células
                                     transformaria a pauta num painel de
                                     controlo; clicar na apreciação é a mesma
                                     ação sem o ruído.
                                     UMA PROPOSTA POR DOMÍNIO NÃO É UMA
                                     PENDÊNCIA. O itálico que ela levava dizia
                                     «isto ainda está por decidir», e não está:
                                     quem não altera nada aceitou-a, e ela
                                     vigora. Texto normal, portanto. O que
                                     continua a merecer marca é o CONTRÁRIO —
                                     quando o professor alterou a proposta, e aí
                                     é a decisão dele que se destaca.
                                     E porque nem negrito nem anel são
                                     informação para quem não os vê, a frase
                                     inteira («Decisão do professor: …. Proposta
                                     do Lapispro: …», «Proposta do Lapispro: … —
                                     vigente enquanto o professor não a
                                     alterar.») vai no texto acessível e não só
                                     no `title` (§8, §14). -->
                                <button
                                    v-if="isDomainDecidable(student, domain)"
                                    type="button"
                                    class="sheet-domain-cta rounded px-1.5 py-0.5 hover:ring-1 hover:ring-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                    :class="[
                                        toneOf(domainCell(student, domain.domain_id)),
                                        {
                                            'font-semibold ring-1 ring-primary/40': domainCell(student, domain.domain_id).origin === 'decided',
                                            'text-muted-foreground': domainCell(student, domain.domain_id).origin === 'none',
                                        },
                                    ]"
                                    :title="domainCell(student, domain.domain_id).description"
                                    :aria-label="domainActionLabel(student, domain)"
                                    :aria-haspopup="'dialog'"
                                    @click="emit('decideDomain', { student, domain })"
                                >
                                    {{ domainCell(student, domain.domain_id).text }}
                                </button>
                                <span
                                    v-else
                                    class="rounded px-1.5 py-0.5"
                                    :class="[
                                        toneOf(domainCell(student, domain.domain_id)),
                                        {
                                            'font-semibold': domainCell(student, domain.domain_id).origin === 'decided',
                                            'text-muted-foreground': domainCell(student, domain.domain_id).origin === 'none',
                                        },
                                    ]"
                                    :title="domainCell(student, domain.domain_id).description"
                                    :aria-label="domainCell(student, domain.domain_id).description"
                                >{{ domainCell(student, domain.domain_id).text }}</span>
                                <!-- A MARCA DE QUE HOUVE ALTERAÇÃO, e não só o
                                     negrito. Uma pauta guardada perde o anel do
                                     botão e ficaria a distinguir a decisão do
                                     professor apenas pela espessura da letra —
                                     que não é informação para quem não a vê
                                     (§25). Aparece só onde alguém alterou
                                     alguma coisa, que por desenho é raro. -->
                                <sup
                                    v-if="domainCell(student, domain.domain_id).origin === 'decided'"
                                    class="ml-0.5 rounded bg-primary/10 px-1 text-[10px] font-normal whitespace-nowrap text-primary"
                                    :title="domainCell(student, domain.domain_id).description"
                                    :aria-label="domainCell(student, domain.domain_id).description"
                                >prof.</sup>
                                <!-- O que o aluno disse SOBRE ESTE DOMÍNIO, em
                                     expoente e a meia-voz, com o «A» a dizer de
                                     quem é a voz — a mesma escrita do Quadro
                                     Síntese, para não haver duas convenções para
                                     a mesma coisa. -->
                                <sup
                                    v-if="showSelfAssessment && studentDomain(student, domain.domain_id)?.self_assessment"
                                    class="ml-0.5 rounded bg-background/70 px-1 text-[10px] font-normal text-muted-foreground"
                                    :title="selfAssessmentTitle(studentDomain(student, domain.domain_id)?.self_assessment, domain.name)"
                                    :aria-label="selfAssessmentTitle(studentDomain(student, domain.domain_id)?.self_assessment, domain.name)"
                                >A{{ studentDomain(student, domain.domain_id)?.self_assessment?.code }}</sup>
                                <CoverageWarning
                                    v-if="showWarnings && !showQuantitative && studentDomain(student, domain.domain_id)?.has_coverage_warning"
                                    :coverage="studentDomain(student, domain.domain_id)!.coverage"
                                    :has-value="(studentDomain(student, domain.domain_id)?.normalized_value ?? null) !== null"
                                    :domains="domainColumns"
                                />
                            </td>
                        </template>
                    </template>

                    <td v-if="showQuantitative" class="border-b border-l-2 border-border bg-muted/20 px-2 py-2 text-center font-medium tabular-nums">
                        <span :class="{ 'text-muted-foreground': student.overall.scale_value == null && student.overall.normalized_value == null }">
                            {{ student.overall.scale_value ?? pct(student.overall.normalized_value) }}
                        </span>
                        <CoverageWarning
                            v-if="showWarnings && student.overall.has_coverage_warning"
                            :coverage="student.coverage"
                            :has-value="student.overall.normalized_value !== null"
                            :domains="domainColumns"
                            scope="overall"
                        />
                    </td>
                    <td
                        class="border-b border-border bg-muted/20 px-2 py-2 text-center font-medium"
                        :class="showQuantitative ? '' : 'border-l-2'"
                    >
                        <span
                            class="rounded px-1.5 py-0.5"
                            :class="[
                                toneOf(overallCell(student)),
                                { 'text-muted-foreground': overallCell(student).origin === 'none' },
                            ]"
                            :title="overallCell(student).description"
                            :aria-label="overallCell(student).description"
                        >{{ overallCell(student).text }}</span>
                        <CoverageWarning
                            v-if="showWarnings && !showQuantitative && student.overall.has_coverage_warning"
                            :coverage="student.coverage"
                            :has-value="student.overall.normalized_value !== null"
                            :domains="domainColumns"
                            scope="overall"
                        />
                    </td>

                    <!-- A perceção do aluno. INFORMAÇÃO DE APOIO: não determina
                         a classificação, e por isso não tem o peso visual de uma
                         — nem o «—» dela é um zero, é uma pergunta sem resposta. -->
                    <td
                        v-if="showSelfAssessment"
                        class="border-b border-l-2 border-border px-2 py-2 text-center"
                    >
                        <span
                            v-if="student.self_assessment"
                            class="rounded bg-muted px-1.5 py-0.5 text-xs"
                            :title="selfAssessmentTitle(student.self_assessment)"
                        >
                            {{ student.self_assessment.code }}
                        </span>
                        <span
                            v-else
                            class="text-muted-foreground"
                            title="O aluno não respondeu à autoavaliação global deste momento."
                        >—</span>
                    </td>

                    <td
                        class="sticky right-0 z-10 border-b border-l-2 border-border bg-background px-3 py-2 text-center whitespace-nowrap shadow-[-8px_0_8px_-6px_rgba(0,0,0,0.10)]"
                    >
                        <!-- DECIDIDA E AINDA ABERTA: o valor É o botão. Clicar
                             abre a mesma decisão para a rever — «Alterar», nunca
                             «editar a proposta», que é outra coisa e não se faz. -->
                        <button
                            v-if="isDecidable(student) && assignedLevel(student).origin === 'decided'"
                            type="button"
                            class="rounded bg-primary/10 px-2 py-0.5 font-bold text-primary hover:ring-1 hover:ring-primary focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                            :title="assignedLevel(student).description"
                            :aria-label="actionLabel(student)"
                            :aria-haspopup="'dialog'"
                            @click="emit('decide', student)"
                        >
                            {{ assignedLevel(student).text }}
                        </button>

                        <!-- POR DECIDIR: a proposta continua à vista, em itálico
                             e sem força, e ao lado a ação que falta. Uma
                             proposta nunca é apresentada como se fosse uma nota. -->
                        <span v-else-if="isDecidable(student)" class="inline-flex items-center gap-1.5">
                            <span
                                v-if="assignedLevel(student).origin === 'proposed'"
                                class="text-muted-foreground italic"
                                :title="assignedLevel(student).description"
                                :aria-label="assignedLevel(student).description"
                            >
                                {{ assignedLevel(student).text }}
                            </span>
                            <button
                                type="button"
                                class="sheet-decision-cta rounded border border-dashed border-border px-2 py-0.5 text-xs hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none"
                                :aria-label="actionLabel(student)"
                                :aria-haspopup="'dialog'"
                                @click="emit('decide', student)"
                            >
                                Atribuir
                            </button>
                        </span>

                        <!-- Sem ação: uma pauta guardada, ou uma classificação
                             já publicada. O valor lê-se na mesma. -->
                        <span
                            v-else-if="assignedLevel(student).origin === 'decided'"
                            class="rounded bg-primary/10 px-2 py-0.5 font-bold text-primary"
                            :title="isPublished(student) && decidable
                                ? `${assignedLevel(student).description} Já publicada.`
                                : assignedLevel(student).description"
                            :aria-label="assignedLevel(student).description"
                        >
                            {{ assignedLevel(student).text }}
                        </span>
                        <span
                            v-else-if="assignedLevel(student).origin === 'proposed'"
                            class="rounded px-2 py-0.5 text-muted-foreground italic"
                            :title="assignedLevel(student).description"
                            :aria-label="assignedLevel(student).description"
                        >
                            {{ assignedLevel(student).text }}
                        </span>
                        <span v-else class="text-muted-foreground">—</span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
