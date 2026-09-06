<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, CircleX, Upload } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import type { InovarExportPreparation, SheetMomentKind } from '@/types';

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

    confirmation.post(`${base()}/${props.token}`);
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
                        {{ preparation.summary.matched_students }} alunos correspondidos pelo N.º de processo
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
                                <th scope="col" class="py-2 pr-3 font-medium">Apreciações a escrever</th>
                                <th v-if="confirmation.include_level" scope="col" class="py-2 font-medium">Nível</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="student in preparation.students" :key="student.row" class="border-b border-border/60 align-top">
                                <td class="py-2 pr-3 tabular-nums text-muted-foreground">{{ student.row }}</td>
                                <td class="py-2 pr-3 tabular-nums">{{ student.process_number ?? '—' }}</td>
                                <td class="py-2 pr-3">
                                    {{ student.name }}
                                    <p v-if="!student.matched" class="text-xs text-red-600">{{ student.issues.join(' ') }}</p>
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

                <p class="text-xs text-muted-foreground">
                    Uma célula sem menção fica exatamente como estava na grelha — nunca é preenchida com Fraco nem com
                    zero. As cores da Pauta não aparecem aqui de propósito: o Excel não as leva.
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
                        :href="`/classes/${schoolClass.ulid}/pauta-avaliacao/${period.ulid}`"
                        class="rounded-md border border-border px-4 py-2 text-sm hover:bg-muted/40"
                    >
                        Voltar e completar
                    </Link>
                    <button
                        type="button"
                        class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:opacity-90 disabled:opacity-50"
                        :disabled="blocked || needsColumn || confirmation.processing"
                        @click="submitConfirmation"
                    >
                        {{ confirmation.processing ? 'A gerar…' : 'Exportar na mesma' }}
                    </button>
                </div>

                <p v-if="blocked" class="text-sm text-red-700 dark:text-red-400">
                    Resolva primeiro os pontos a vermelho: sem eles o ficheiro sairia errado.
                </p>
            </section>
        </template>
    </div>
</template>
