<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Download, ListChecks, Printer, Table2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import EvaluationSheetDecisionDialog from '@/components/evaluation-sheets/EvaluationSheetDecisionDialog.vue';
import EvaluationSheetDomainDecisionDialog from '@/components/evaluation-sheets/EvaluationSheetDomainDecisionDialog.vue';
import EvaluationSheetReadinessPanel from '@/components/evaluation-sheets/EvaluationSheetReadinessPanel.vue';
import EvaluationSheetTable from '@/components/evaluation-sheets/EvaluationSheetTable.vue';
import EvaluationSheetViewControls from '@/components/evaluation-sheets/EvaluationSheetViewControls.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type {
    EvaluationSheet,
    EvaluationSheetDecisionScale,
    EvaluationSheetDomain,
    EvaluationSheetPeriod,
    EvaluationSheetReadiness,
    EvaluationSheetSaveDefaults,
    EvaluationSheetStudent,
} from '@/types';

/**
 * Pautas de Avaliação — UMA ÚNICA VISTA.
 *
 * Abre com tudo visível: quantitativo, apreciação qualitativa por domínio, e
 * classificação sugerida vs. decidida. Os toggles SÓ ESCONDEM — nunca
 * recalculam nada nem alteram o payload recebido do servidor, que permanece
 * intacto em `props.sheet` do início ao fim da visita.
 *
 * A grelha é o MESMO componente que a pauta guardada usa: é isso que garante
 * que uma fotografia se lê com as mesmas colunas, cores e estrutura do ecrã de
 * onde foi tirada.
 *
 * E É AQUI QUE SE DECIDE. A informação toda já está reunida neste ecrã; mandar
 * o professor a outro sítio para atribuir o nível seria mandá-lo decidir longe
 * do que acabou de ver. O que este ecrã NÃO faz é guardar: a decisão viaja para
 * `classifications.decide`, o mesmo caminho canónico que Classificações e
 * Resultados usam — um serviço, uma validação, um rasto (§3.3).
 */

const props = defineProps<{
    schoolClass: {
        ulid: string;
        label: string;
        subject: string;
        academic_year: string;
        has_profile: boolean;
        scale_name?: string | null;
    };
    periods: EvaluationSheetPeriod[];
    sheet: EvaluationSheet | null;
    /** A escala em que a decisão é tomada — a mesma que Resultados recebe. */
    decision: EvaluationSheetDecisionScale;
    saveDefaults?: EvaluationSheetSaveDefaults | null;
    /** Apresentação apenas: a rota está atrás de `module:inovar_export` no servidor. */
    canExportToInovar?: boolean;
    /** «Preparar fecho» — a leitura de preparação sobre esta mesma pauta. Null sem pauta ou sem alunos. */
    readiness?: EvaluationSheetReadiness | null;
}>();

const page = usePage();

const selectedPeriod = computed<EvaluationSheetPeriod | null>(
    () => props.periods.find((period) => period.selected) ?? null,
);

// ---------------------------------------------------------------- a decisão
//
// O ecrã abre o painel e envia; NÃO decide e NÃO guarda. Quem escreve é o
// endpoint canónico das classificações, com a sua autorização, a sua validação,
// o seu bloqueio de linha e o seu rasto de auditoria — exatamente o mesmo que
// se a decisão tivesse sido tomada no ecrã de Classificações (§34).

const editing = ref<EvaluationSheetStudent | null>(null);
const saving = ref(false);

const decisionError = computed<string | null>(
    () => ((page.props.errors as Record<string, string> | undefined)?.final_value ?? null),
);

const canDecideHere = computed(() => props.schoolClass.has_profile && selectedPeriod.value !== null);

function openDecision(student: EvaluationSheetStudent): void {
    if (!canDecideHere.value) {
        return;
    }

    editing.value = student;
}

function closeDecision(): void {
    editing.value = null;
}

function postDecision(data: { final_scale_level_id: number | null; final_value: string | null }): void {
    const student = editing.value;
    const period = selectedPeriod.value;

    if (student === null || period === null || !student.enrollment_ulid) {
        return;
    }

    // Endereçada pelo ALUNO e pelo PERÍODO, nunca pela linha que a guarda: um
    // período sem propostas geradas não tem linha nenhuma, e a classificação do
    // professor não pode ficar à espera de uma.
    router.post(
        `/classes/${props.schoolClass.ulid}/classifications/${period.ulid}/${student.enrollment_ulid}/decide`,
        data,
        {
            preserveScroll: true,
            onStart: () => (saving.value = true),
            onFinish: () => (saving.value = false),
            // Só em caso de sucesso: uma escrita recusada tem de deixar o painel
            // aberto com a mensagem, nunca uma célula a fingir que guardou.
            onSuccess: () => closeDecision(),
        },
    );
}

// ------------------------------------------- a decisão sobre um domínio
//
// A MESMA GRAMÁTICA, UMA ESCALA ABAIXO. O ecrã abre o painel e envia; quem
// escreve é o serviço, com a sua autorização, a sua validação contra a escala
// real da turma e o seu rasto (§3.3). O que muda em relação à decisão global é
// só o endereço — este é do ALUNO e do DOMÍNIO — e o facto de aqui não haver
// publicação nenhuma a fechar a porta: uma apreciação é sempre reeditável.

const editingDomain = ref<{ student: EvaluationSheetStudent; domain: EvaluationSheetDomain } | null>(null);
const savingDomain = ref(false);

const domainDecisionError = computed<string | null>(
    () => ((page.props.errors as Record<string, string> | undefined)?.scale_level_id ?? null),
);

function openDomainDecision(payload: { student: EvaluationSheetStudent; domain: EvaluationSheetDomain }): void {
    if (!canDecideHere.value) {
        return;
    }

    editingDomain.value = payload;
}

function closeDomainDecision(): void {
    editingDomain.value = null;
}

function postDomainDecision(scaleLevelId: number | null): void {
    const editing = editingDomain.value;
    const period = selectedPeriod.value;

    if (editing === null || period === null) {
        return;
    }

    const enrollment = editing.student.enrollment_ulid;
    const domain = editing.domain.domain_ulid;

    if (!enrollment || !domain) {
        return;
    }

    router.post(
        `/classes/${props.schoolClass.ulid}/pauta-avaliacao/${period.ulid}/dominios/${enrollment}/${domain}`,
        { scale_level_id: scaleLevelId },
        {
            preserveScroll: true,
            onStart: () => (savingDomain.value = true),
            onFinish: () => (savingDomain.value = false),
            // Só em caso de sucesso: uma escrita recusada deixa o painel aberto
            // com a mensagem, nunca uma célula a fingir que guardou.
            onSuccess: () => closeDomainDecision(),
        },
    );
}

function selectPeriod(ulid: string): void {
    router.get(`/classes/${props.schoolClass.ulid}/pauta-avaliacao/${ulid}`, {}, { preserveScroll: true });
}

// ------------------------------------------------------------- apresentação
//
// SÓ CLIENT-SIDE. Nenhum destes refs viaja para o servidor nem altera o que é
// pedido — ocultar um grupo é puramente visual (§ briefing, regra central).

const showQuantitative = ref(true);
const showDomainDetail = ref(true);
const showWarnings = ref(true);

/**
 * A AUTOAVALIAÇÃO ABRE VISÍVEL quando existe.
 *
 * A pauta abre com toda a informação relevante, e o que o aluno disse de si
 * próprio é informação relevante para quem vai decidir. Quem não a quiser
 * desliga-a — como desliga tudo o resto. Onde não existe não há coluna nem
 * interruptor: um controlo para esconder o que não há não é uma opção (§11).
 */
const selfAssessmentAvailable = computed(
    () =>
        props.sheet?.students.some(
            (student) =>
                (student.self_assessment ?? null) !== null ||
                student.domains.some((domain) => (domain.self_assessment ?? null) !== null),
        ) ?? false,
);

const showSelfAssessment = ref(true);

// ------------------------------------------------------------ preparar fecho
//
// Uma CAMADA DE LEITURA sobre a pauta, nunca uma segunda pauta: o painel
// apenas mostra o que o servidor já leu (`props.readiness`), e abrir ou
// fechar não pede nada ao servidor nem altera coisa alguma. Fechado por
// omissão — a pauta continua a ser o ecrã principal.

const showReadiness = ref(false);

// ---------------------------------------------------------------- impressão
//
// NO PRÓPRIO ECRÃ, sem página paralela (§5): o que se imprime é este ecrã,
// através de `@media print`, e não uma segunda vista que teria de ser mantida
// a par desta para dizer a mesma coisa.
//
// WYSIWYG, E DELIBERADAMENTE: sai o que ESTÁ NO ECRÃ. Se o professor ocultou o
// detalhe por domínio para levar uma folha mais simples para o conselho de
// turma, é essa folha que sai da impressora. É o OPOSTO da regra do CSV — lá o
// ficheiro leva sempre tudo, porque é um ficheiro de dados e omitir colunas em
// silêncio seria uma armadilha; aqui é uma folha para ler, e uma folha que
// ignorasse o que o professor escolheu ver seria a ferramenta a discordar dele.

const printedOn = new Date().toLocaleDateString('pt-PT');

function print(): void {
    window.print();
}

// ------------------------------------------------------------ guardar pauta
//
// O título e a data são SUGESTÕES editáveis. O título vem da configuração
// temporal do próprio período («Semestre — 1.º Semestre»), nunca de uma
// palavra escrita à mão no código; a data é validada no servidor contra o
// `starts_on`/`ends_on` do período, e os limites são mostrados aqui para que o
// professor não seja recusado só depois de carregar no botão.

const showSaveForm = ref(false);

const saveForm = useForm({
    moment_label: props.saveDefaults?.moment_label ?? '',
    effective_at: props.saveDefaults?.effective_at ?? '',
});

const canSave = computed(() => props.saveDefaults !== null && props.saveDefaults !== undefined && props.sheet !== null);

function submitSave(): void {
    if (!props.saveDefaults) {
        return;
    }

    saveForm.post(`/classes/${props.schoolClass.ulid}/pauta-avaliacao/${props.saveDefaults.period_ulid}/guardar`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`Pauta de Avaliação — ${schoolClass.label}`" />

    <div class="space-y-4 p-4">
        <div class="print-hide flex flex-wrap items-center justify-between gap-3">
            <div>
                <Heading
                    :title="`Pauta de Avaliação — ${schoolClass.label}`"
                    :description="
                        selectedPeriod
                            ? `${schoolClass.subject} · ${selectedPeriod.kind_label} selecionado: ${selectedPeriod.label}`
                            : schoolClass.subject
                    "
                />
                <Link :href="`/classes/${schoolClass.ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>

            <div v-if="periods.length" class="flex flex-wrap gap-1">
                <button
                    v-for="period in periods"
                    :key="period.ulid"
                    type="button"
                    class="rounded-md border px-3 py-1.5 text-sm"
                    :class="period.selected ? 'border-primary bg-primary text-primary-foreground' : 'border-border hover:bg-muted/40'"
                    :title="period.kind_label"
                    @click="selectPeriod(period.ulid)"
                >
                    {{ period.label }}
                </button>
            </div>
        </div>

        <div class="print-hide flex flex-wrap items-center gap-3">
            <button
                v-if="canSave"
                type="button"
                class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground hover:opacity-90"
                @click="showSaveForm = !showSaveForm"
            >
                Guardar esta pauta
            </button>
            <!-- «Preparar fecho» ABRE UMA LEITURA, não uma ação: o que está
                 completo, o que merece um olhar, o que não se aplica. Nada
                 aqui fecha, bloqueia ou decide — avisar, nunca impedir. -->
            <button
                v-if="readiness"
                type="button"
                class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                :aria-expanded="showReadiness"
                aria-controls="preparacao-do-fecho"
                @click="showReadiness = !showReadiness"
            >
                <ListChecks class="size-4" />
                Preparar fecho
                <span
                    v-if="readiness.summary.attention_count > 0"
                    class="rounded-full bg-amber-100 px-1.5 text-xs font-medium text-amber-800"
                >
                    {{ readiness.summary.attention_count }}
                </span>
            </button>
            <!-- NÃO EXPORTA JÁ. Abre a etapa de preparação: carregar a grelha,
                 ver o que vai ser escrito, e só depois confirmar. Uma grelha
                 subtilmente errada seria enviada à escola sem ninguém dar por
                 isso — a revisão é o ponto. -->
            <Link
                v-if="canExportToInovar && selectedPeriod"
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/inovar/${selectedPeriod.ulid}`"
                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            >
                Preparar exportação para o Inovar
            </Link>
            <!-- UM FICHEIRO DE DADOS, não a folha impressa nem a grelha do
                 Inovar. Leva SEMPRE tudo — todos os domínios, o quantitativo, as
                 apreciações, o global e o nível atribuído — independentemente
                 dos toggles «Mostrar:» aqui em baixo, que são só apresentação e
                 nem sequer chegam ao servidor. Um `<a>`, não um `<Link>`: uma
                 resposta de ficheiro não volta por uma visita Inertia. -->
            <a
                v-if="sheet && selectedPeriod"
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/csv/${selectedPeriod.ulid}`"
                class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            >
                <Download class="size-4" /> Exportar CSV
            </a>
            <!-- O MESMO ESTADO, EM EXCEL. Ficheiro do Lapispro — com título,
                 contexto e as cores dos domínios — e não a grelha do Inovar.
                 Exporta a pauta de HOJE; o histórico exporta o momento que ficou
                 guardado, e as duas origens nunca se cruzam. -->
            <a
                v-if="sheet && selectedPeriod"
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/xlsx/${selectedPeriod.ulid}`"
                class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            >
                <Download class="size-4" /> Exportar Excel
            </a>
            <button
                v-if="sheet"
                type="button"
                class="inline-flex items-center gap-2 rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                @click="print"
            >
                <Printer class="size-4" /> Imprimir
            </button>
            <Link
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/historico`"
                class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
            >
                Histórico
            </Link>
        </div>

        <form
            v-if="canSave && showSaveForm"
            class="print-hide space-y-3 rounded-lg border border-border bg-muted/10 px-4 py-3"
            @submit.prevent="submitSave"
        >
            <p class="text-sm text-muted-foreground">
                Guardar cria uma fotografia imutável desta pauta. O que estiver no ecrã fica registado tal como
                está agora — alterações posteriores às notas ou às classificações não mexem no que foi guardado.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <label for="moment-label" class="text-sm font-medium">Título do momento</label>
                    <input
                        id="moment-label"
                        v-model="saveForm.moment_label"
                        type="text"
                        maxlength="200"
                        required
                        class="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                    />
                    <InputError :message="saveForm.errors.moment_label" />
                </div>

                <div class="space-y-1">
                    <label for="effective-at" class="text-sm font-medium">Data de referência</label>
                    <input
                        id="effective-at"
                        v-model="saveForm.effective_at"
                        type="date"
                        required
                        :min="saveDefaults?.starts_on"
                        :max="saveDefaults?.ends_on"
                        class="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                    />
                    <p v-if="saveDefaults" class="text-xs text-muted-foreground">
                        Entre {{ saveDefaults.starts_on }} e {{ saveDefaults.ends_on }}.
                    </p>
                    <InputError :message="saveForm.errors.effective_at" />
                </div>
            </div>

            <div class="flex gap-2">
                <button
                    type="submit"
                    :disabled="saveForm.processing"
                    class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                >
                    {{ saveForm.processing ? 'A guardar…' : 'Guardar' }}
                </button>
                <button
                    type="button"
                    class="rounded-md border border-border px-3 py-1.5 text-sm hover:bg-muted/40"
                    @click="showSaveForm = false"
                >
                    Cancelar
                </button>
            </div>
        </form>

        <!-- Fora do papel, como todos os controlos: a preparação é leitura de
             trabalho, não parte da pauta impressa. -->
        <EvaluationSheetReadinessPanel
            v-if="readiness && showReadiness && selectedPeriod"
            :readiness="readiness"
            :class-ulid="schoolClass.ulid"
            :period-ulid="selectedPeriod.ulid"
            class="print-hide"
        />

        <!-- Controlos de visualização: só apresentação, nunca alteram os dados
             recebidos do servidor. Fora do papel — o que eles decidem já está
             decidido no que sai impresso. -->
        <EvaluationSheetViewControls
            v-model:show-quantitative="showQuantitative"
            v-model:show-domain-detail="showDomainDetail"
            v-model:show-warnings="showWarnings"
            v-model:show-self-assessment="showSelfAssessment"
            :self-assessment-available="selfAssessmentAvailable"
            class="print-hide"
        />

        <!-- O que vai para o papel, e só isto. -->
        <div class="pauta-print space-y-3">
            <!-- O cabeçalho que a folha impressa precisa e o ecrã já mostra
                 noutro sítio: turma, disciplina, o período com a sua
                 terminologia própria («Semestre», «Período»…) — nunca uma
                 palavra fixa no código — e a data em que foi impressa. -->
            <div class="hidden print:block">
                <h1 class="text-lg font-semibold">Pauta de Avaliação — {{ schoolClass.label }}</h1>
                <p class="text-sm">{{ schoolClass.subject }} · {{ schoolClass.academic_year }}</p>
                <p v-if="selectedPeriod" class="text-sm">
                    {{ selectedPeriod.kind_label }}: {{ selectedPeriod.label }}
                </p>
                <p class="text-xs">Impresso em {{ printedOn }}</p>
            </div>

            <p v-if="!schoolClass.has_profile" class="rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Esta turma não tem perfil de avaliação associado, por isso não há pauta a mostrar.
            </p>

            <EmptyState
                v-else-if="sheet === null || sheet.students.length === 0"
                title="Sem alunos ou sem resultados neste período."
                :icon="Table2"
            />

            <EvaluationSheetTable
                v-else
                :domains="sheet.domains"
                :students="sheet.students"
                :show-quantitative="showQuantitative"
                :show-domain-detail="showDomainDetail"
                :show-warnings="showWarnings"
                :show-self-assessment="showSelfAssessment && selfAssessmentAvailable"
                :decidable="canDecideHere"
                @decide="openDecision"
                @decide-domain="openDomainDecision"
            />

            <p class="text-xs text-muted-foreground print:text-black">
                "—" significa sem elementos, nunca zero. O ícone de aviso assinala cobertura parcial ou a ausência de
                elementos avaliados — passe o rato ou o foco por cima para ver o detalhe. Uma apreciação em itálico é a
                <strong>proposta</strong> do Lapispro, ainda não decidida; uma apreciação a negrito é a
                <strong>decisão</strong> do professor. Isso vale para o nível atribuído e para cada domínio: clique
                numa apreciação para a decidir ou para a devolver à proposta. A decisão é sempre sua e pode ser
                alterada a qualquer momento — guardar, exportar ou ter histórico não a fecham. Com os
                <strong>valores quantitativos</strong> desligados, as apreciações aparecem pela menção da escala
                («Bom») em vez do código («4»); o código continua no texto de cada célula.
            </p>
        </div>

        <!-- Fora da folha impressa e fora da grelha: um diálogo é trabalho, não
             parte da pauta. Abre pela ação da coluna final e escreve pelo
             caminho canónico. -->
        <EvaluationSheetDecisionDialog
            v-if="sheet"
            :student="editing"
            :decision="decision"
            :domains="sheet.domains"
            :saving="saving"
            :error="decisionError"
            @close="closeDecision"
            @save="postDecision"
            @use-proposal="postDecision({ final_scale_level_id: null, final_value: null })"
        />

        <!-- A apreciação de um domínio, decidida a partir da própria célula. -->
        <EvaluationSheetDomainDecisionDialog
            v-if="sheet"
            :student="editingDomain?.student ?? null"
            :domain="editingDomain?.domain ?? null"
            :decision="decision"
            :saving="savingDomain"
            :error="domainDecisionError"
            @close="closeDomainDecision"
            @save="postDomainDecision"
        />
    </div>
</template>

<style>
/* Imprimir SÓ a pauta, a partir deste mesmo ecrã — sem página paralela (§5).
   O truque da visibilidade não depende do markup do layout da aplicação, que
   não é deste ecrã e pode mudar sem aviso. */
@media print {
    body * {
        visibility: hidden;
    }
    .pauta-print,
    .pauta-print * {
        visibility: visible;
    }
    .pauta-print {
        position: absolute;
        inset: 0;
    }
    /* Nada de controlos no papel: toggles, botões, seletor de período e links
       não se carregam numa folha impressa. */
    .print-hide {
        display: none !important;
    }
    /* «Atribuir» é uma ação, e uma folha não se clica: o que fica por decidir
       lê-se pela ausência de valor, não por um botão impresso. O valor
       DECIDIDO é que não pode desaparecer — é também um botão no ecrã, e por
       isso perde aqui a aparência de botão em vez de ser escondido. */
    .pauta-print .sheet-decision-cta {
        display: none !important;
    }
    .pauta-print button {
        border: 0 !important;
        background: transparent !important;
        padding: 0 !important;
    }
    /* No ecrã a grelha rola dentro de si própria e tem duas colunas fixas. No
       papel não há scroll: sem isto sairia apenas a primeira dobra da tabela, e
       as colunas «Aluno» e «Nível atribuído» ficariam por cima do resto em vez
       de ao lado. `print-color-adjust` mantém as cores dos domínios, que são
       identidade da pauta e não decoração. */
    .pauta-print .evaluation-sheet-grid {
        max-height: none !important;
        overflow: visible !important;
        border: 0 !important;
        print-color-adjust: exact;
        -webkit-print-color-adjust: exact;
    }
    .pauta-print .evaluation-sheet-grid th,
    .pauta-print .evaluation-sheet-grid td {
        position: static !important;
        box-shadow: none !important;
    }
    /* O PAPEL NÃO ROLA, E O QUE NÃO CABE PERDE-SE. No ecrã a tabela é mais
       larga do que o contentor de propósito e quem lê arrasta-a de lado; numa
       folha, essa mesma largura corta a última coluna — que é justamente o
       «Nível atribuído», a decisão do professor. Landscape porque uma pauta com
       vários domínios é larga por natureza, e `width: 100%` com quebra de linha
       permitida para as colunas se ajustarem à folha em vez de saírem dela. */
    @page {
        size: A4 landscape;
        margin: 10mm;
    }
    .pauta-print .evaluation-sheet-grid table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: auto;
        font-size: 9pt;
    }
    .pauta-print .evaluation-sheet-grid th,
    .pauta-print .evaluation-sheet-grid td {
        white-space: normal !important;
        padding: 2pt 3pt !important;
    }
}
</style>
