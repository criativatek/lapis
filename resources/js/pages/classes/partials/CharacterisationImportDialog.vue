<script setup lang="ts">
/**
 * Importação assistida da caracterização — colar/ficheiro → pré-visualização
 * → confirmar (docs/superpowers/specs/2026-09-20-class-pedagogical-characterisation.md §4 e §7).
 *
 * `preview` devolve JSON, não uma resposta Inertia — por isso usa-se `fetch`
 * diretamente, como em InsertLessonDialog.vue, em vez do `router` do Inertia.
 * `store` já é uma escrita normal da aplicação e usa o `router.post` do
 * costume.
 *
 * NADA fica marcado por omissão exceto o que o servidor pré-selecionou
 * (`match.preselected`). Uma linha ambígua ou não encontrada não pode ser
 * confirmada sem que o professor escolha a inscrição — é o mesmo princípio
 * de «o professor decide» do resto da funcionalidade.
 */
import { router } from '@inertiajs/vue3';
import { AlertTriangle, FileUp, ImageOff, Loader2 } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import FileInput from '@/components/FileInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { Textarea } from '@/components/ui/textarea';
import type { ExtractedTablePayload } from './characterisation-extracted-table';
import { extractTableFromImage, ImageDecodeError  } from './characterisation-image-extraction';
import type {OcrProgress} from './characterisation-image-extraction';
import { resolvePastePayload } from './characterisation-paste-priority';

type StudentOption = { ulid: string; name: string; class_number: number | null };

type Resolution = {
    raw_token: string;
    confidence: string;
    confidence_label: string;
    level: string | null;
    level_label: string | null;
    code: string | null;
    code_label: string | null;
    unresolved_annotations: string[];
    scope: string;
    note: string | null;
    storable: boolean;
    family: string | null;
    family_label: string | null;
    has_structured_destination: boolean;
};

// Só as medidas (destino B) trazem `already_active` — é o servidor a repetir,
// na pré-visualização, a MESMA verificação que o caminho de escrita faz ao
// confirmar (PreviewRow::toArray(), §29/§30): uma medida já ativa para o
// aluno não é reimportada como duplicada.
type MeasureResolution = Resolution & { already_active: boolean };

// Espelha SectionMergeResult::toArray() (app/Services/Characterisation/Import) —
// `action_label` já vem traduzido do servidor, não se reinventa aqui.
type SectionMergeResult = {
    section: string;
    action: 'add' | 'already_present' | 'review_required' | 'no_destination' | 'ignore';
    action_label: string;
    current_value: string | null;
    incoming_value: string | null;
    merged_value: string | null;
};

type RowMatch = {
    state: string;
    state_label: string;
    enrollment_ulid: string | null;
    matched_name: string | null;
    matched_by: string | null;
    candidates: StudentOption[];
    preselected: boolean;
};

type PreviewRow = {
    row_number: number;
    raw_name: string;
    raw_process_number: string | null;
    match: RowMatch;
    sections: Record<string, string>;
    section_merges: Record<string, SectionMergeResult>;
    measures: MeasureResolution[];
    resources: Resolution[];
    unresolved: Resolution[];
};

type PreviewResponse = {
    preview: {
        columns: unknown[];
        rows: PreviewRow[];
        identifiable: boolean;
        tally: Record<string, number>;
    };
    source_kind: string;
    original_filename: string | null;
    warnings: string[];
    sections: { key: string; label: string }[];
};

// Devolvido em vez de `preview` quando um .docx tem mais do que uma tabela
// (§ item 3) — sem escolha nenhuma feita a adivinhar.
type DocxTableChoice = { index: number; row_count: number; column_count: number; label: string };
type TableChooserResponse = { tables: DocxTableChoice[] };

const props = defineProps<{
    open: boolean;
    classUlid: string;
    students: StudentOption[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
}>();

type RowState = {
    row: PreviewRow;
    included: boolean;
    enrollmentUlid: string | null;
    sections: Record<string, string>;
};

const step = ref<'input' | 'chooser' | 'preview'>('input');
const pastedText = ref('');
const pastedHtml = ref('');
const file = ref<File | null>(null);
const loading = ref(false);
const submitting = ref(false);
const loadError = ref<string | null>(null);
// Imagem detetada no clipboard/ficheiro. Reconhecida por OCR inteiramente no
// browser (characterisation-image-extraction.ts) — nunca enviada ao
// servidor. `error` fica preenchido só quando a leitura falha (imagem
// inválida, demasiado grande, ou o reconhecimento em si).
const imageDetected = ref<{ file: File | Blob; error: string | null } | null>(null);
const ocrProgress = ref<OcrProgress | null>(null);
const ocrExtractedTable = ref<ExtractedTablePayload | null>(null);
let ocrAbortController: AbortController | null = null;

function cancelOcr(): void {
    ocrAbortController?.abort();
}

const previewData = ref<PreviewResponse | null>(null);
const rowStates = reactive<Record<number, RowState>>({});

const tableChoices = ref<DocxTableChoice[] | null>(null);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            resetToInput();
        }
    },
);

// Todo o estado derivado de UMA pré-visualização, num único sítio (F5). Sem
// isto, trocar de ficheiro a meio ("Escolher outro ficheiro") deixava
// `rowStates` com as chaves da tabela anterior por cima das novas sempre que
// a tabela nova tinha menos linhas — a diferença ficava lá, marcável e
// submetível, sem nunca ter vindo da tabela que está agora em ecrã.
function clearPreviewState(): void {
    previewData.value = null;
    Object.keys(rowStates).forEach((key) => delete rowStates[Number(key)]);
}

function resetToInput(): void {
    step.value = 'input';
    pastedText.value = '';
    pastedHtml.value = '';
    file.value = null;
    loadError.value = null;
    imageDetected.value = null;
    ocrProgress.value = null;
    ocrExtractedTable.value = null;
    ocrAbortController?.abort();
    ocrAbortController = null;
    tableChoices.value = null;
    clearPreviewState();
}

// Usada pelo "Escolher outro ficheiro" e pelo "Voltar" do seletor de tabelas
// — volta ao passo de entrada SEM apagar o que já foi colado/escolhido (essa
// é a diferença com resetToInput(), que só corre à abertura do diálogo), mas
// sempre a limpar o estado da pré-visualização anterior (F5).
function backToInputStep(): void {
    step.value = 'input';
    tableChoices.value = null;
    clearPreviewState();
}

function onFileChange(event: Event): void {
    const chosen = (event.target as HTMLInputElement).files?.[0] ?? null;
    pastedHtml.value = '';
    imageDetected.value = null;

    // An image never goes to the server as `file` (the server-side upload
    // path only ever accepts docx/csv/xlsx — see CharacterisationImportController)
    // — it is recognised here, in-browser, and only the recognised table is
    // sent on. See the PRIVACY block in characterisation-image-extraction.ts.
    if (chosen !== null && chosen.type.startsWith('image/')) {
        file.value = null;
        handleImage(chosen, 'image_upload');

        return;
    }

    file.value = chosen;
}

// A prioridade da cola está isolada em characterisation-paste-priority.ts
// (§8): HTML com tabela primeiro, texto tabular depois, imagem a seguir, e só
// no fim texto simples — nunca o inverso, que é exatamente o que perdia a
// estrutura de uma tabela colada do Word/Excel.
function onPaste(event: ClipboardEvent): void {
    if (event.clipboardData === null) {
        return;
    }

    const payload = resolvePastePayload(event.clipboardData);

    if (payload.kind === 'none') {
        return;
    }

    event.preventDefault();
    file.value = null;
    imageDetected.value = null;

    if (payload.kind === 'html') {
        pastedHtml.value = payload.html;
        pastedText.value = '';

        return;
    }

    if (payload.kind === 'text') {
        pastedText.value = payload.text;
        pastedHtml.value = '';

        return;
    }

    if (payload.kind === 'image') {
        pastedText.value = '';
        pastedHtml.value = '';
        handleImage(payload.file, 'pasted_image');
    }
}

// F10: a saída explícita da tabela rica — nunca uma segunda forma silenciosa
// de o fazer (ver o comentário junto ao textarea sobre porque não se escolheu
// limpar `pastedHtml` ao primeiro carácter escrito).
function discardPastedHtml(): void {
    pastedHtml.value = '';
}

function handleImage(imageFile: File | Blob, sourceKind: 'pasted_image' | 'image_upload'): void {
    imageDetected.value = { file: imageFile, error: null };
    ocrExtractedTable.value = null;
    ocrProgress.value = { status: 'a preparar', progress: 0 };
    ocrAbortController = new AbortController();

    extractTableFromImage(imageFile, {
        sourceKind,
        sourceFilename: imageFile instanceof File ? imageFile.name : null,
        signal: ocrAbortController.signal,
        onProgress: (progress) => {
            ocrProgress.value = progress;
        },
    })
        .then((table) => {
            ocrExtractedTable.value = table;
            ocrProgress.value = null;
        })
        .catch((error: unknown) => {
            ocrProgress.value = null;

            if (error instanceof DOMException && error.name === 'AbortError') {
                imageDetected.value = null;

                return;
            }

            imageDetected.value = {
                file: imageFile,
                error:
                    error instanceof ImageDecodeError
                        ? error.message
                        : 'Não foi possível reconhecer texto nesta imagem. Cole a tabela em texto ou escolha um ficheiro.',
            };
        });
}

function csrfToken(): string {
    return decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');
}

async function loadPreview(tableIndex: number | null = null): Promise<void> {
    if (
        pastedText.value.trim() === '' &&
        pastedHtml.value.trim() === '' &&
        file.value === null &&
        ocrExtractedTable.value === null
    ) {
        loadError.value = 'Cole a tabela ou escolha um ficheiro.';

        return;
    }

    loading.value = true;
    loadError.value = null;

    const body = new FormData();

    if (ocrExtractedTable.value !== null) {
        // The image itself is never sent — only the table the OCR seam
        // already recognised, in-browser (§ privacy). `source_kind` doubles
        // here as what the server expects in `extracted_table.source_type`.
        body.append('extracted_table', JSON.stringify(ocrExtractedTable.value));
        body.append('source_kind', ocrExtractedTable.value.source_type);
    } else if (file.value !== null) {
        body.append('file', file.value);

        if (tableIndex !== null) {
            body.append('table_index', String(tableIndex));
        }
    } else if (pastedHtml.value !== '') {
        body.append('pasted_html', pastedHtml.value);
    } else {
        body.append('pasted_text', pastedText.value);
    }

    try {
        const response = await fetch(`/classes/${props.classUlid}/characterisation-imports/preview`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-XSRF-TOKEN': csrfToken(),
            },
            body,
        });

        const payload = await response.json();

        if (!response.ok) {
            loadError.value =
                (payload?.errors?.file?.[0] as string | undefined) ??
                (payload?.errors?.pasted_html?.[0] as string | undefined) ??
                (payload?.errors?.pasted_text?.[0] as string | undefined) ??
                (payload?.errors?.extracted_table?.[0] as string | undefined) ??
                (payload?.message as string | undefined) ??
                'Não foi possível ler o ficheiro.';

            return;
        }

        // Um .docx com mais do que uma tabela devolve um seletor, sem
        // `preview` nenhuma — nunca se adivinha qual delas era a certa
        // (§ item 3).
        if (!('preview' in payload)) {
            const chooser = payload as TableChooserResponse;
            tableChoices.value = chooser.tables;
            step.value = 'chooser';

            return;
        }

        // Limpa ANTES de repovoar (F5) — uma pré-visualização nova nunca deve
        // herdar linhas da anterior, mesmo que esta chamada não tenha passado
        // por resetToInput()/backToInputStep() (ex.: escolher uma tabela
        // diferente no seletor do .docx chama loadPreview() diretamente).
        clearPreviewState();
        previewData.value = payload as PreviewResponse;

        payload.preview.rows.forEach((row: PreviewRow) => {
            rowStates[row.row_number] = {
                row,
                // Só as linhas pré-selecionadas pelo servidor começam marcadas
                // — tudo o resto exige uma decisão explícita do professor.
                included: row.match.preselected,
                enrollmentUlid: row.match.enrollment_ulid,
                sections: { ...row.sections },
            };
        });

        step.value = 'preview';
    } catch {
        loadError.value = 'Não foi possível ler o ficheiro.';
    } finally {
        loading.value = false;
    }
}

function chooseTable(index: number): void {
    void loadPreview(index);
}

const rows = computed(() => Object.values(rowStates).sort((a, b) => a.row.row_number - b.row.row_number));

// «possible» conta como por resolver, e tem de contar. É o estado que o
// emparelhamento por subsequência produz: «Maria Santos» na folha encontra
// «Maria Silva Santos» na pauta, que pode perfeitamente ser outra criança.
// Sem o seletor, a linha afirmava «Associado a Maria Silva Santos» e deixava
// confirmar à mesma — dizia ter a certeza e não dava como corrigi-la. Só a
// correspondência segura dispensa alguém apontar o aluno.
function needsManualMatch(state: RowState): boolean {
    return state.row.match.state !== 'confident';
}

function candidatesFor(state: RowState): StudentOption[] {
    return state.row.match.candidates.length > 0 ? state.row.match.candidates : props.students;
}

function canConfirm(state: RowState): boolean {
    return state.enrollmentUlid !== null && state.enrollmentUlid !== '';
}

const confirmableCount = computed(
    () => rows.value.filter((state) => state.included && canConfirm(state)).length,
);

function submitImport(): void {
    if (previewData.value === null) {
        return;
    }

    const decisions = rows.value
        .filter((state) => state.included && canConfirm(state))
        .map((state) => {
            const measureCodes: string[] = [];
            const rawTokens: Record<string, string> = {};
            const annotations: Record<string, string[]> = {};

            state.row.measures.forEach((measure) => {
                if (measure.code === null) {
                    return;
                }

                measureCodes.push(measure.code);
                rawTokens[measure.code] = measure.raw_token;

                if (measure.unresolved_annotations.length > 0) {
                    annotations[measure.code] = measure.unresolved_annotations;
                }
            });

            return {
                enrollment_ulid: state.enrollmentUlid,
                sections: state.sections,
                measure_codes: measureCodes,
                raw_tokens: rawTokens,
                annotations,
            };
        });

    if (decisions.length === 0) {
        return;
    }

    submitting.value = true;

    router.post(
        `/classes/${props.classUlid}/characterisation-imports`,
        {
            source_kind: previewData.value.source_kind,
            original_filename: previewData.value.original_filename,
            decisions,
        },
        {
            onSuccess: () => {
                emit('update:open', false);
            },
            onFinish: () => {
                submitting.value = false;
            },
        },
    );
}

function closeDialog(): void {
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(value) => emit('update:open', value)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Importar caracterização</DialogTitle>
            </DialogHeader>

            <template v-if="step === 'input'">
                <div class="space-y-4 py-2">
                    <p class="text-sm text-muted-foreground">
                        Cole uma tabela do Word, Excel ou Google Sheets, cole uma imagem da tabela ou escolha um
                        ficheiro. Colar do Word ou do Excel funciona diretamente — não é preciso guardar como
                        CSV primeiro.
                    </p>
                    <div class="grid gap-2">
                        <Label for="characterisation-paste">Colar tabela</Label>
                        <!-- Alcançável pelo teclado (§51): é o próprio campo de
                             texto que recebe o Ctrl+V, sem depender de um
                             clique numa div sem foco. -->
                        <!-- F10: quando a cola trouxe HTML com tabela, o
                             textarea fica desativado em vez de ficar vazio e
                             editável por baixo de um aviso — uma tabela rica
                             tem estrutura (linhas/colunas) que este campo de
                             texto simples não pode representar, por isso
                             "escrever por cima" não existe: ou se usa a
                             tabela reconhecida, ou descarta-se e escreve-se de
                             novo em texto simples. Nunca os dois em
                             simultâneo — era exatamente essa ambiguidade que
                             fazia uma edição desaparecer sem aviso. -->
                        <Textarea
                            id="characterisation-paste"
                            v-model="pastedText"
                            rows="6"
                            placeholder="Cole aqui a tabela…"
                            :disabled="file !== null || pastedHtml !== ''"
                            @paste="onPaste"
                        />
                        <div v-if="pastedHtml !== ''" class="flex items-center justify-between gap-2 rounded-md border p-2 text-xs text-muted-foreground">
                            <p>Tabela reconhecida na cola (com formatação) — pronta a pré-visualizar.</p>
                            <Button type="button" variant="outline" size="sm" @click="discardPastedHtml">
                                Descartar formatação / usar texto
                            </Button>
                        </div>
                    </div>
                    <div v-if="ocrProgress" class="flex items-center gap-2 rounded-md border p-2 text-sm">
                        <Loader2 class="size-4 shrink-0 animate-spin" />
                        <p class="flex-1">A reconhecer texto na imagem… ({{ Math.round(ocrProgress.progress * 100) }}%)</p>
                        <Button type="button" variant="outline" size="sm" @click="cancelOcr">Cancelar</Button>
                    </div>
                    <div v-else-if="ocrExtractedTable" class="flex items-start gap-2 rounded-md border p-2 text-sm">
                        <ImageOff class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <p>
                            Tabela reconhecida na imagem ({{ ocrExtractedTable.rows.length }} linhas) — pronta a
                            pré-visualizar.
                        </p>
                    </div>
                    <div v-else-if="imageDetected" class="flex items-start gap-2 rounded-md border p-2 text-sm">
                        <ImageOff class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <p>{{ imageDetected.error ?? 'Imagem detetada.' }}</p>
                    </div>
                    <div class="grid gap-2">
                        <Label for="characterisation-file">Ou escolha um ficheiro</Label>
                        <FileInput
                            id="characterisation-file"
                            accept=".csv,.xlsx,.docx,.png,.jpg,.jpeg,.webp"
                            :disabled="pastedText.trim() !== '' || pastedHtml.trim() !== ''"
                            @change="onFileChange"
                        />
                        <p class="text-xs text-muted-foreground">Word (.docx), Excel (.xlsx), CSV ou Imagem (.png, .jpg, .webp).</p>
                    </div>
                    <p v-if="loadError" class="flex items-center gap-1.5 text-sm text-destructive">
                        <AlertTriangle class="size-4" /> {{ loadError }}
                    </p>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="closeDialog">Cancelar</Button>
                    <Button type="button" :disabled="loading || ocrProgress !== null" @click="() => loadPreview()">
                        <Loader2 v-if="loading" class="size-4 animate-spin" />
                        <FileUp v-else class="size-4" />
                        Pré-visualizar
                    </Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'chooser' && tableChoices !== null">
                <div class="space-y-3 py-2">
                    <p class="text-sm text-muted-foreground">
                        Este documento tem mais do que uma tabela. Escolha qual delas é a caracterização a importar.
                    </p>
                    <ul class="space-y-2">
                        <li v-for="choice in tableChoices" :key="choice.index">
                            <button
                                type="button"
                                class="w-full rounded-md border p-3 text-left text-sm hover:bg-accent"
                                @click="chooseTable(choice.index)"
                            >
                                {{ choice.label }}
                            </button>
                        </li>
                    </ul>
                </div>
                <DialogFooter>
                    <Button type="button" variant="outline" @click="backToInputStep">Voltar</Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'preview' && previewData !== null">
                <div class="space-y-4 py-2">
                    <p class="text-sm text-muted-foreground">
                        Reveja cada linha antes de confirmar. Só as linhas assinaladas serão gravadas —
                        o que não for reconhecido não é guardado.
                    </p>

                    <!-- Avisos de leitura (§40): transmitidos em texto, não só
                         pela cor — uma legenda ou linha de grupo ignorada não
                         é um erro, mas o professor tem de saber que ficou de
                         fora. -->
                    <ul v-if="previewData.warnings.length > 0" class="space-y-1 rounded-md border p-2 text-xs text-muted-foreground">
                        <li v-for="(warning, index) in previewData.warnings" :key="index" class="flex items-start gap-1.5">
                            <AlertTriangle class="mt-0.5 size-3.5 shrink-0" /> {{ warning }}
                        </li>
                    </ul>

                    <!-- F9: 0 linhas não é "sem nada a mostrar" — é um estado
                         próprio, que explica porquê e dá para onde ir a
                         seguir. Os avisos (`warnings`) continuam visíveis
                         acima mesmo aqui, porque é precisamente aqui que eles
                         mais interessam: são normalmente a razão de 0 linhas
                         terem sobrevivido. -->
                    <div v-if="rows.length === 0" class="space-y-3 rounded-md border p-4 text-center">
                        <p class="text-sm font-medium">Nenhuma linha ficou pronta a importar.</p>
                        <p class="text-xs text-muted-foreground">
                            Ou o ficheiro não tinha linhas de dados reconhecíveis, ou todas foram ignoradas — veja os
                            avisos acima, se os houver. Pode tentar outro formato, escolher outra tabela do mesmo
                            documento ou carregar outro ficheiro.
                        </p>
                        <Button type="button" variant="outline" @click="backToInputStep">Escolher outro ficheiro</Button>
                    </div>

                    <!-- Empilhado em cartões a toda a largura, para caber sem scroll
                         horizontal a 390px (o requisito da spec §7). -->
                    <div v-else class="space-y-3">
                        <div
                            v-for="state in rows"
                            :key="state.row.row_number"
                            class="rounded-md border p-3"
                            :class="{ 'opacity-60': !state.included }"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="flex items-start gap-2">
                                    <Checkbox
                                        :model-value="state.included"
                                        :disabled="!canConfirm(state)"
                                        @update:model-value="(value) => (state.included = value === true)"
                                    />
                                    <div>
                                        <p class="text-sm font-medium">{{ state.row.raw_name || '(sem nome)' }}</p>
                                        <p class="text-xs text-muted-foreground">
                                            Linha {{ state.row.row_number }} · {{ state.row.match.state_label }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div v-if="needsManualMatch(state)" class="mt-2 grid gap-1.5">
                                <Label :for="`match-${state.row.row_number}`">Associar a aluno</Label>
                                <NativeSelect
                                    :id="`match-${state.row.row_number}`"
                                    v-model="state.enrollmentUlid"
                                >
                                    <option :value="null">Escolher…</option>
                                    <option
                                        v-for="candidate in candidatesFor(state)"
                                        :key="candidate.ulid"
                                        :value="candidate.ulid"
                                    >
                                        {{ candidate.class_number ?? '—' }} · {{ candidate.name }}
                                    </option>
                                </NativeSelect>
                                <p v-if="!canConfirm(state)" class="text-xs text-destructive">
                                    Sem inscrição escolhida, esta linha não pode ser confirmada.
                                </p>
                            </div>
                            <p v-else class="mt-1 text-xs text-muted-foreground">
                                Associado a {{ state.row.match.matched_name }}
                            </p>

                            <div v-if="Object.keys(state.sections).length > 0" class="mt-3 space-y-2">
                                <p class="text-xs font-medium text-muted-foreground">Caracterização</p>
                                <div
                                    v-for="(sectionKey, index) in Object.keys(state.sections)"
                                    :key="index"
                                    class="grid gap-1"
                                >
                                    <Label class="text-xs">
                                        {{ previewData.sections.find((s) => s.key === sectionKey)?.label ?? sectionKey }}
                                    </Label>
                                    <!-- F6: o que a importação faria a esta secção já
                                         registada — nunca uma substituição (não há
                                         essa opção no enum, ver SectionMergeAction).
                                         ALREADY_PRESENT (reimportar a mesma tabela)
                                         fica explícito para não parecer que nada
                                         aconteceu por engano. -->
                                    <template v-if="state.row.section_merges[sectionKey]">
                                        <p
                                            v-if="state.row.section_merges[sectionKey].action === 'already_present'"
                                            class="text-xs text-muted-foreground"
                                        >
                                            Já registado — esta reimportação não acrescenta nada de novo.
                                        </p>
                                        <template v-else-if="state.row.section_merges[sectionKey].current_value">
                                            <p class="text-xs text-muted-foreground">
                                                <span class="font-medium">JÁ REGISTADO:</span>
                                                {{ state.row.section_merges[sectionKey].current_value }}
                                            </p>
                                            <p class="text-xs text-muted-foreground">
                                                <span class="font-medium">A ACRESCENTAR:</span>
                                                {{ state.row.section_merges[sectionKey].incoming_value }}
                                            </p>
                                            <p class="text-xs text-muted-foreground italic">
                                                Será acrescentado à caracterização existente.
                                            </p>
                                        </template>
                                    </template>
                                    <Textarea v-model="state.sections[sectionKey]" rows="2" />
                                </div>
                            </div>

                            <div v-if="state.row.measures.length > 0" class="mt-3 space-y-1">
                                <p class="text-xs font-medium text-muted-foreground">Medidas</p>
                                <p v-for="measure in state.row.measures" :key="measure.raw_token" class="text-xs">
                                    {{ [measure.level_label, measure.code_label].filter(Boolean).join(' · ') }}
                                    <span class="text-muted-foreground italic">
                                        — o ficheiro indicava: {{ measure.raw_token }}
                                    </span>
                                    <!-- F6: idem às secções — o servidor já sabe se
                                         esta medida está ativa para o aluno; o
                                         cliente só lê, nunca decide (§29/§30). -->
                                    <span v-if="measure.already_active" class="block text-muted-foreground">
                                        Já registada — não será duplicada.
                                    </span>
                                    <span v-else class="block text-muted-foreground">
                                        Será adicionada a Estratégias e Medidas.
                                    </span>
                                </p>
                            </div>

                            <!-- Destino (C): entendido, e sem sítio onde ficar.
                                 Um CRI não é uma necessidade do aluno, e
                                 escrevê-lo em «Necessidades» porque essa coluna
                                 existe seria afirmar uma coisa que ninguém
                                 disse. Fica à vista, com nome, e não se grava. -->
                            <div v-if="state.row.resources.length > 0" class="mt-3 space-y-1">
                                <p class="text-xs font-medium text-muted-foreground">
                                    Apoios e recursos — sem destino estruturado, não será gravado
                                </p>
                                <p v-for="resource in state.row.resources" :key="resource.raw_token" class="text-xs">
                                    {{ resource.raw_token }}
                                    <span class="text-muted-foreground italic">
                                        — {{ resource.note ?? resource.family_label }}
                                    </span>
                                </p>
                            </div>

                            <div v-if="state.row.unresolved.length > 0" class="mt-3 space-y-1">
                                <p class="text-xs font-medium text-muted-foreground">
                                    Não reconhecido — não será gravado
                                </p>
                                <p v-for="unresolved in state.row.unresolved" :key="unresolved.raw_token" class="text-xs text-muted-foreground">
                                    {{ unresolved.raw_token }} ({{ unresolved.confidence_label }})
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <DialogFooter class="flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-xs text-muted-foreground">
                        {{ confirmableCount }} de {{ rows.length }} linhas serão importadas.
                    </p>
                    <div class="flex gap-2">
                        <Button type="button" variant="outline" @click="backToInputStep">Escolher outro ficheiro</Button>
                        <Button type="button" :disabled="submitting || confirmableCount === 0" @click="submitImport">
                            <Loader2 v-if="submitting" class="size-4 animate-spin" />
                            Confirmar importação
                        </Button>
                    </div>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
