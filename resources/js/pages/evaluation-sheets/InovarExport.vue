<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleHelp, CircleX, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type {
    InovarExportPreparation,
    InovarExportRow,
    InovarMatchCandidate,
    InovarMatchConfidence,
    SheetMomentKind,
} from '@/types';

/**
 * Preparar a exportação para o INOVAR — a etapa que existe para que ninguém
 * exporte às cegas.
 *
 * ESTE ECRÃ NÃO É A PAUTA. Mostra só o que vai para o ficheiro: a linha da
 * grelha, o aluno que lhe corresponde, as apreciações por domínio e — se o
 * professor quiser — o nível. Sem percentagens, sem a grelha analítica e
 * DELIBERADAMENTE SEM AS CORES DO LAPISPRO: o Excel não as leva, e pintá-las
 * aqui sugeriria que levava.
 *
 * A COLUNA DO NÍVEL É ESCOLHIDA, NUNCA ADIVINHADA. Nenhuma das grelhas reais
 * dá nome à coluna do nível; «a coluna a seguir aos domínios» seria um
 * mapeamento que ninguém aprovou, e uma grelha preenchida na coluna errada é
 * pior do que uma grelha vazia, porque a escola envia-a à mesma. O servidor diz
 * que colunas existem e com que valores; quem decide é o professor.
 *
 * AVISOS PEDAGÓGICOS NUNCA BLOQUEIAM (§7). Faltar uma classificação decidida é
 * informação, não impedimento: há sempre as duas saídas — voltar e completar,
 * ou exportar na mesma. Só o que é tecnicamente impossível bloqueia (§8).
 */

const props = defineProps<{
    schoolClass: { ulid: string; label: string; subject: string };
    period: {
        ulid: string;
        label: string;
        kind_label: string;
        ends_on: string;
        /** Qual dos dois momentos estruturais a Pauta estava a preparar. */
        moment?: SheetMomentKind;
        moment_label?: string;
    };
    token: string | null;
    preparation: InovarExportPreparation | null;
}>();

const page = usePage();

/** O que o servidor devolveu — incluindo uma confirmação recusada. */
const pageErrors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

function base(): string {
    return `/classes/${props.schoolClass.ulid}/pauta-avaliacao/inovar/${props.period.ulid}`;
}

/** O momento de onde o professor veio viaja em cada volta ao servidor. */
function momentQuery(): string {
    return props.period.moment === 'interim' ? '?momento=interim' : '';
}

// ------------------------------------------------------------------ upload

const upload = useForm<{ template: File | null }>({ template: null });
const fileInput = ref<HTMLInputElement | null>(null);
const chosenName = ref<string | null>(null);

function chooseFile(): void {
    fileInput.value?.click();
}

function onFileChosen(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    upload.template = file;
    chosenName.value = file?.name ?? null;
}

function submitTemplate(): void {
    if (upload.template === null) {
        return;
    }

    upload.post(base(), { forceFormData: true, preserveScroll: true });
}

// ------------------------------------------------------------------- nível

const levelCandidates = computed(() => props.preparation?.level.candidates ?? []);
const levelAvailable = computed(() => levelCandidates.value.length > 0);

const confirmation = useForm({
    include_level: false,
    level_column: '' as string,
    moment_label: '',
    // Devolvido intacto ao servidor: a grelha congela o momento de onde o
    // professor veio, e não um momento inferido do dia em que carregou no botão.
    moment: (props.period.moment ?? 'final') as SheetMomentKind,
});

/**
 * O default vem do servidor, da configuração temporal real do período — nunca
 * da palavra «semestre» nem de nada escrito à mão. O professor inverte-o sempre
 * que quiser, e é o que ele deixar aqui que é enviado.
 */
watch(
    () => props.preparation,
    (preparation) => {
        if (!preparation) {
            return;
        }

        confirmation.include_level = preparation.level.default_include && preparation.level.candidates.length > 0;
        confirmation.level_column = preparation.level.suggested_column ?? '';
        confirmation.moment_label = preparation.moment_label;
        confirmation.moment = preparation.moment ?? props.period.moment ?? 'final';
    },
    { immediate: true },
);

// ------------------------------------------------------ correspondências
//
// DE QUEM É CADA LINHA é a pergunta mais perigosa deste ecrã, e por isso é a
// única em que o Lapispro pede ajuda em vez de decidir. Quatro estados, ditos
// por palavras e não por cor (§35): correspondido, provável (confirmar),
// ambíguo (escolher), sem correspondência.
//
// A ESCOLHA VOLTA AO SERVIDOR. Podia ser resolvida aqui e o ecrã ficaria mais
// rápido e mentiria: as menções de uma linha só existem depois de se saber de
// quem ela é, e confirmar sem ver o que passa a ser escrito não é a revisão que
// este ecrã existe para dar.

const resolutions = ref<Record<number, number>>({});

/** O que o professor escolheu para esta linha, ou o que o Lapispro sugere. */
function choiceFor(student: InovarExportRow): number | null {
    return resolutions.value[student.row] ?? student.enrollment_id ?? null;
}

function choose(student: InovarExportRow, enrollmentId: number | null): void {
    if (enrollmentId === null) {
        delete resolutions.value[student.row];
    } else {
        resolutions.value[student.row] = enrollmentId;
    }
}

const resolving = ref(false);

function submitResolutions(): void {
    if (props.token === null) {
        return;
    }

    router.post(
        `${base()}/${props.token}/correspondencias${momentQuery()}`,
        { resolutions: resolutions.value },
        {
            preserveScroll: true,
            onStart: () => (resolving.value = true),
            onFinish: () => (resolving.value = false),
        },
    );
}

/** Os candidatos desta linha, resolvidos a partir dos ids que ela traz. */
function candidatesFor(student: InovarExportRow): InovarMatchCandidate[] {
    const all = props.preparation?.candidates ?? [];
    const offered = all.filter((candidate) => student.candidates.includes(candidate.enrollment_id));

    // Uma linha ambígua traz os seus candidatos; uma linha que o professor está
    // a rever de raiz pode escolher entre a turma inteira.
    return offered.length > 0 ? offered : all;
}

const linesNeedingTeacher = computed(() =>
    (props.preparation?.students ?? []).filter((student) => student.needs_teacher),
);

const CONFIDENCE_TONE: Record<InovarMatchConfidence, string> = {
    strong: 'text-emerald-700 dark:text-emerald-400',
    probable: 'text-amber-700 dark:text-amber-400',
    ambiguous: 'text-amber-700 dark:text-amber-400',
    none: 'text-muted-foreground',
};

/** Quantos alunos correspondidos ainda não têm classificação decidida. */
const withoutDecision = computed(() =>
    (props.preparation?.students ?? []).filter((student) => student.matched && student.level === null),
);

const blocked = computed(() => (props.preparation?.summary.blocking_errors.length ?? 0) > 0);

const needsColumn = computed(() => confirmation.include_level && confirmation.level_column === '');

function submitConfirmation(): void {
    if (props.token === null) {
        return;
    }

    // As escolhas viajam com a confirmação e são reavaliadas no servidor: o
    // ficheiro é sempre relido, e uma escolha fora dos candidatos daquela linha
    // é descartada em vez de obedecida.
    confirmation
        .transform((data) => ({ ...data, resolutions: resolutions.value }))
        .post(`${base()}/${props.token}`);
}

function sampleLine(samples: string[]): string {
    return samples.length ? samples.join(', ') : 'sem valores nas linhas dos alunos';
}
</script>

<template>
    <Head :title="`Exportar para o INOVAR — ${schoolClass.label}`" />

    <div class="mx-auto w-full max-w-4xl space-y-5 p-4">
        <div>
            <Heading
                title="Preparar exportação para o INOVAR"
                :description="`${schoolClass.label} · ${schoolClass.subject} · ${period.moment_label ?? period.label}`"
            />
            <!-- Volta ao MOMENTO de onde veio, e não ao separador por omissão:
                 quem estava a preparar um intercalar não quis o fecho. -->
            <Link
                :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/${period.ulid}${period.moment === 'interim' ? '?momento=interim' : ''}`"
                class="text-sm text-muted-foreground hover:underline"
            >
                ← Voltar à pauta
            </Link>
        </div>

        <!-- 1 · o ficheiro -->
        <section class="space-y-3 rounded-lg border border-border p-4">
            <h2 class="text-sm font-semibold">1 · Carregar a grelha do INOVAR</h2>
            <p class="text-sm text-muted-foreground">
                Descarregue a grelha no INOVAR e carregue-a aqui. O Lapispro escreve as menções
                <strong>no mesmo ficheiro</strong> e devolve-o; o ficheiro que carregar não é alterado.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <input
                    id="inovar-template"
                    ref="fileInput"
                    type="file"
                    accept=".xls,.xlsx"
                    class="sr-only"
                    @change="onFileChosen"
                />
                <label
                    for="inovar-template"
                    tabindex="0"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-md border border-border px-4 py-2 text-sm font-medium hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    @keydown.enter.prevent="chooseFile"
                    @keydown.space.prevent="chooseFile"
                >
                    <Upload class="size-4" />
                    {{ chosenName === null ? 'Escolher grelha INOVAR' : 'Alterar grelha' }}
                </label>

                <span v-if="chosenName" class="text-sm text-muted-foreground">{{ chosenName }}</span>

                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                    :disabled="upload.template === null || upload.processing"
                    @click="submitTemplate"
                >
                    {{ upload.processing ? 'A ler a grelha…' : 'Ler a grelha' }}
                </button>
            </div>

            <InputError :message="upload.errors.template" />
            <InputError :message="pageErrors.template" />
        </section>

        <template v-if="preparation">
            <!-- 2 · o que vai ser escrito -->
            <section class="space-y-4 rounded-lg border border-border p-4">
                <h2 class="text-sm font-semibold">2 · Rever o que vai para o ficheiro</h2>

                <ul class="space-y-1 text-sm">
                    <li class="flex items-center gap-2">
                        <CircleCheck class="size-4 text-emerald-600" />
                        {{ preparation.summary.matched_students }} alunos correspondidos automaticamente
                    </li>
                    <li v-if="preparation.summary.students_needing_teacher > 0" class="flex items-center gap-2">
                        <CircleHelp class="size-4 text-amber-600" />
                        {{ preparation.summary.students_needing_teacher }} linhas à espera de si
                    </li>
                    <li class="flex items-center gap-2">
                        <CircleCheck class="size-4 text-emerald-600" />
                        {{ preparation.summary.mapped_domains }} domínios associados
                    </li>
                    <li class="flex items-center gap-2">
                        <CircleCheck class="size-4 text-emerald-600" />
                        {{ preparation.summary.ready_cells }} apreciações prontas a escrever
                    </li>
                </ul>

                <!-- Bloqueio TÉCNICO: sem isto o ficheiro sairia errado. -->
                <ul v-if="blocked" class="space-y-1 text-sm text-red-700 dark:text-red-400">
                    <li v-for="error in preparation.summary.blocking_errors" :key="error" class="flex items-start gap-2">
                        <CircleX class="mt-0.5 size-4 shrink-0" />{{ error }}
                    </li>
                </ul>

                <!-- Avisos PEDAGÓGICOS: informam, nunca impedem (§7). -->
                <ul v-if="preparation.summary.warnings.length" class="space-y-1 text-sm text-amber-700 dark:text-amber-400">
                    <li v-for="warning in preparation.summary.warnings" :key="warning" class="flex items-start gap-2">
                        <CircleAlert class="mt-0.5 size-4 shrink-0" />{{ warning }}
                    </li>
                </ul>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[42rem] text-left text-sm">
                        <thead class="border-b border-border text-xs text-muted-foreground">
                            <tr>
                                <th scope="col" class="py-2 pr-3 font-medium">Linha</th>
                                <th scope="col" class="py-2 pr-3 font-medium">N.º processo</th>
                                <th scope="col" class="py-2 pr-3 font-medium">Aluno na grelha</th>
                                <th scope="col" class="py-2 pr-3 font-medium">Correspondência</th>
                                <th scope="col" class="py-2 pr-3 font-medium">Apreciações a escrever</th>
                                <th v-if="confirmation.include_level" scope="col" class="py-2 font-medium">Nível</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in preparation.students" :key="student.row" class="border-b border-border/60 align-top">
                                <td class="py-2 pr-3 tabular-nums text-muted-foreground">{{ student.row }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ student.process_number ?? '—' }}</td>
                                <td class="py-2 pr-3">{{ student.name }}</td>

                                <!-- O ESTADO POR PALAVRAS, e a razão a seguir.
                                     Nunca só uma cor: quem não a distingue tem
                                     de poder ler a mesma coisa (§35). -->
                                <td class="py-2 pr-3">
                                    <p :class="CONFIDENCE_TONE[student.confidence]" class="font-medium">
                                        {{ student.confidence_label }}
                                    </p>
                                    <p v-if="student.issues.length" class="text-xs text-muted-foreground">
                                        {{ student.issues.join(' ') }}
                                    </p>

                                    <!-- Uma correspondência PROVÁVEL: o Lapispro
                                         diz quem acha que é, e espera. -->
                                    <div v-if="student.needs_teacher" class="mt-1.5 space-y-1.5">
                                        <label class="block text-xs">
                                            <span class="block text-muted-foreground">Aluno do Lapispro</span>
                                            <select
                                                class="mt-0.5 w-full rounded-md border border-border bg-background px-2 py-1 text-sm"
                                                :value="choiceFor(student) ?? ''"
                                                @change="choose(student, ($event.target as HTMLSelectElement).value === '' ? null : Number(($event.target as HTMLSelectElement).value))"
                                            >
                                                <option value="">— deixar esta linha em branco —</option>
                                                <option
                                                    v-for="candidate in candidatesFor(student)"
                                                    :key="candidate.enrollment_id"
                                                    :value="candidate.enrollment_id"
                                                >
                                                    {{ candidate.name }}<template v-if="candidate.process_number"> · {{ candidate.process_number }}</template>
                                                </option>
                                            </select>
                                        </label>
                                    </div>

                                    <p v-else-if="student.chosen_by_teacher" class="text-xs text-muted-foreground">
                                        Confirmado por si: {{ student.lapis_name }}
                                    </p>
                                </td>

                                <td class="py-2 pr-3">
                                    <span v-if="!student.matched" class="text-muted-foreground">—</span>
                                    <ul v-else class="space-y-0.5">
                                        <li v-for="cell in student.domains" :key="cell.column">
                                            <span class="tabular-nums text-muted-foreground">{{ cell.column }}</span>
                                            {{ cell.domain }}:
                                            <template v-if="cell.writable">
                                                <strong>{{ cell.code }}</strong>
                                                <span class="text-muted-foreground"> ({{ cell.band }})</span>
                                                <span v-if="cell.decided" class="text-muted-foreground"> · decisão sua</span>
                                                <span v-if="cell.partial" class="text-amber-700 dark:text-amber-400"> ⚠ parcial</span>
                                            </template>
                                            <span v-else class="text-muted-foreground">fica por preencher</span>
                                        </li>
                                    </ul>
                                </td>
                                <td v-if="confirmation.include_level" class="py-2">
                                    <strong v-if="student.level !== null">{{ student.level }}</strong>
                                    <span v-else class="text-amber-700 dark:text-amber-400">sem decisão — fica vazio</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- A RESPOSTA VOLTA AO SERVIDOR antes de se exportar, para o
                     professor VER o que passa a ser escrito naquela linha. Um
                     «confirmado» que não mostrasse as menções seria uma
                     confirmação às cegas — exatamente o que este ecrã existe
                     para evitar. -->
                <div
                    v-if="linesNeedingTeacher.length"
                    class="flex flex-wrap items-center gap-3 rounded-md border border-amber-300 bg-amber-50 px-3 py-2 text-sm dark:border-amber-900 dark:bg-amber-950/30"
                >
                    <CircleHelp class="size-4 shrink-0 text-amber-600" />
                    <span class="text-amber-900 dark:text-amber-200">
                        {{ linesNeedingTeacher.length === 1
                            ? 'Uma linha espera pela sua confirmação.'
                            : `${linesNeedingTeacher.length} linhas esperam pela sua confirmação.` }}
                        Escolha o aluno e volte a rever antes de exportar.
                    </span>
                    <button
                        type="button"
                        :disabled="resolving"
                        class="rounded-md border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-primary-foreground disabled:opacity-60"
                        @click="submitResolutions"
                    >
                        {{ resolving ? 'A rever…' : 'Aplicar e rever' }}
                    </button>
                </div>

                <p class="text-xs text-muted-foreground">
                    Uma célula sem menção fica exatamente como estava na grelha — nunca é preenchida com Fraco nem com
                    zero. Uma linha sem correspondência também não é preenchida: a grelha da escola pode trazer alunos
                    que não são desta turma. As cores da Pauta não aparecem aqui de propósito: o Excel não as leva.
                </p>
            </section>

            <!-- 3 · o nível -->
            <section class="space-y-3 rounded-lg border border-border p-4">
                <h2 class="text-sm font-semibold">3 · Nível / classificação atribuída</h2>

                <p v-if="!levelAvailable" class="rounded-md border border-border bg-muted/30 px-3 py-2 text-sm text-muted-foreground">
                    {{ preparation.level.unavailable_reason }}
                </p>

                <template v-else>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="confirmation.include_level" type="checkbox" class="mt-0.5" />
                        <span>
                            Incluir nível/classificação atribuída
                            <span class="block text-xs text-muted-foreground">
                                Vem ligado quando este é o momento que fecha o
                                {{ period.kind_label.toLowerCase() }} e ele já chegou ao fim
                                ({{ period.ends_on }}); desligado num momento intercalar. Pode inverter sempre.
                            </span>
                        </span>
                    </label>

                    <fieldset v-if="confirmation.include_level" class="space-y-2">
                        <legend class="text-sm font-medium">Coluna do template onde o nível deve ser escrito</legend>
                        <p class="text-xs text-muted-foreground">
                            O template não dá nome a esta coluna, por isso o Lapispro não a adivinha. Escolha-a pelos
                            valores que já tem.
                        </p>
                        <label
                            v-for="candidate in levelCandidates"
                            :key="candidate.column"
                            class="flex items-start gap-2 rounded-md border border-border px-3 py-2 text-sm"
                        >
                            <input v-model="confirmation.level_column" type="radio" :value="candidate.column" class="mt-1" />
                            <span>
                                <strong>Coluna {{ candidate.column }}</strong>
                                <template v-if="candidate.header"> — «{{ candidate.header }}»</template>
                                <span class="block text-xs text-muted-foreground">
                                    Primeiros valores: {{ sampleLine(candidate.samples) }}
                                </span>
                            </span>
                        </label>
                        <p v-if="needsColumn" class="text-sm text-amber-700 dark:text-amber-400">
                            Escolha uma coluna para poder incluir o nível.
                        </p>
                        <InputError :message="confirmation.errors.level_column" />
                    </fieldset>

                    <p v-if="confirmation.include_level && withoutDecision.length" class="text-sm text-amber-700 dark:text-amber-400">
                        {{ withoutDecision.length }}
                        {{ withoutDecision.length === 1 ? 'aluno ainda não tem' : 'alunos ainda não têm' }}
                        classificação decidida: {{ withoutDecision.map((student) => student.name).join(', ') }}. A célula
                        fica vazia — o Lapispro nunca escreve a proposta nem um zero.
                    </p>
                </template>
            </section>

            <!-- 4 · confirmar -->
            <section class="space-y-3 rounded-lg border border-border p-4">
                <h2 class="text-sm font-semibold">4 · Confirmar</h2>

                <div class="space-y-1">
                    <label for="moment-label" class="text-sm font-medium">Título do momento no histórico</label>
                    <input
                        id="moment-label"
                        v-model="confirmation.moment_label"
                        type="text"
                        maxlength="200"
                        class="w-full rounded-md border border-border bg-background px-3 py-1.5 text-sm"
                    />
                    <InputError :message="confirmation.errors.moment_label" />
                </div>

                <p class="text-sm text-muted-foreground">
                    Ao confirmar, o Lapispro guarda o ficheiro e uma fotografia imutável desta pauta no histórico.
                    Exportar outra vez o mesmo momento cria sempre um registo novo — nenhum substitui o anterior.
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <Link
                        :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/${period.ulid}${period.moment === 'interim' ? '?momento=interim' : ''}`"
                        class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40"
                    >
                        Voltar e completar
                    </Link>
                    <button
                        type="button"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                        :disabled="blocked || needsColumn || linesNeedingTeacher.length > 0 || confirmation.processing"
                        @click="submitConfirmation"
                    >
                        {{ confirmation.processing ? 'A gerar…' : 'Exportar na mesma' }}
                    </button>
                </div>

                <p v-if="blocked" class="text-sm text-red-700 dark:text-red-400">
                    Resolva primeiro os pontos a vermelho: sem eles o ficheiro sairia errado.
                </p>

                <!-- Não é um erro do ficheiro: é uma pergunta por responder
                     sobre DE QUEM é uma nota, e nenhuma nota se escreve com
                     essa pergunta em aberto (§28). -->
                <p v-else-if="linesNeedingTeacher.length" class="text-sm text-amber-700 dark:text-amber-400">
                    Confirme primeiro
                    {{ linesNeedingTeacher.length === 1 ? 'a linha que espera por si' : 'as linhas que esperam por si' }}
                    no passo 2: sem saber de quem é cada linha, uma menção pode ir parar ao aluno errado.
                </p>
            </section>
        </template>
    </div>
</template>
