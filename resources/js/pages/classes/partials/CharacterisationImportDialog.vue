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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import NativeSelect from '@/components/ui/NativeSelect.vue';
import { Textarea } from '@/components/ui/textarea';
import type { ExtractedCellPayload, ExtractedRowPayload, ExtractedTablePayload } from './characterisation-extracted-table';
import { extractTableFromImage, ImageDecodeError  } from './characterisation-image-extraction';
import type {OcrProgress} from './characterisation-image-extraction';
import { resolvePastePayload } from './characterisation-paste-priority';

type StudentOption = { ulid: string; name: string; class_number: number | null };

// §19 — mirrors AcronymSuggestion::toArray(). Never itself a resolution: it
// only names a dictionary token the raw text may have meant, for the teacher
// to explicitly accept or decline.
type SuggestedCorrection = {
    token: string;
    expansion: string | null;
};

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
    // §18 — EXTRACTION confidence ("did I read this cell right?"), never
    // CodeConfidence ("do I know what it means?" — that's `confidence`
    // above). Null for every source but OCR; see PreviewRow::extractionArray().
    extraction_confidence: number | null;
    // §19 — present only on an unresolved, low-extraction-confidence token
    // for which the dictionary had a plausible near-miss.
    suggested_correction: SuggestedCorrection | null;
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

// §38: mirrors NormaliseExtractedTable's own row kinds (ExtractedRowKind) —
// 'unknown' is never sent by the server (every structural row is already
// classified), but stays here so a row the teacher marks back to "não
// decidido" has somewhere honest to sit; see structuralKindOptions().
type StructuralRowKind = 'header' | 'data' | 'group' | 'legend' | 'unknown';

type StructuralRow = {
    number: number;
    kind: StructuralRowKind;
    cells: string[];
};

type StructuralData = {
    headers: string[];
    rows: StructuralRow[];
};

type PreviewResponse = {
    preview: {
        columns: unknown[];
        rows: PreviewRow[];
        identifiable: boolean;
        tally: Record<string, number>;
        data_row_count: number;
        footer_row_count: number;
        has_recognised_content_column: boolean;
    };
    source_kind: string;
    original_filename: string | null;
    warnings: string[];
    sections: { key: string; label: string }[];
    // §38: present on every response (possibly with 0 rows on a simple
    // source), but only READ when `show_structural_step` says to.
    structural: StructuralData;
    show_structural_step: boolean;
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

const step = ref<'input' | 'chooser' | 'structural' | 'preview'>('input');
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

// §38/§39 — "Rever tabela reconhecida". The whole recognised table (header,
// data, and what was classified Group/Legend and dropped), editable, before
// the per-student preview is derived from it. `structuralRowStates` is keyed
// by the row's own (stable) `number` from the server — never by array
// index — so reordering never happens and a row's identity survives edits.
type StructuralRowState = { row: StructuralRow; kind: StructuralRowKind | 'ignore'; cells: string[] };
const structuralHeaders = ref<string[]>([]);
const structuralRowStates = reactive<Record<number, StructuralRowState>>({});
// Column-level "Ignorar coluna" (§39) — index into structuralHeaders.
const ignoredColumns = reactive<Set<number>>(new Set());
// What the CURRENT structural review is reviewing — captured from the
// response that first set `show_structural_step`, since the corrected
// resubmission carries no `file`/`pasted_html` of its own to re-derive it
// from (see CharacterisationImportController::preview()'s own comment on
// why `original_filename` is echoed back for exactly this reason).
const pendingSourceKind = ref<string | null>(null);
const pendingOriginalFilename = ref<string | null>(null);
// Extraction confidence (§18) — ONLY ever populated for an OCR source, and
// ONLY for a cell the teacher has not since edited (an edited cell is exact:
// whatever she typed, not a guess tesseract made — see markCellEdited()).
// Never the domain confidence CodeResolution carries; this answers "did I
// read this right", nothing about what the text MEANS.
const structuralCellConfidence = reactive<Record<string, number | null>>({});
const editedStructuralCells = reactive<Set<string>>(new Set());

// F1: the last `extracted_table` actually POSTed to the preview endpoint —
// in practice the §39 CORRECTED table once one has been submitted, since
// applyStructuralCorrections() is the only writer. Read by buildPreviewBody()
// in preference to `ocrExtractedTable`/`file`/`pastedHtml`/`pastedText`:
// without this, acceptSuggestion() had no way to resubmit anything but the
// original, uncorrected source, which silently threw away every structural
// correction the teacher had just made (wrong row kinds fixed, columns
// ignored, cells edited) the moment she accepted an ACNS suggestion. Cleared
// alongside the rest of the preview state (clearPreviewState()) so a freshly
// chosen file never inherits a correction that belonged to the one before it.
const submittedExtractedTable = ref<ExtractedTablePayload | null>(null);

// Mirrors BuildCharacterisationPreview::LOW_EXTRACTION_CONFIDENCE — the same
// threshold the §38 structural step already uses, so the two steps never
// disagree about what counts as "low".
const LOW_EXTRACTION_CONFIDENCE = 0.7;

function isLowExtractionConfidence(confidence: number | null): boolean {
    return confidence !== null && confidence < LOW_EXTRACTION_CONFIDENCE;
}

// §19: raw token (as the source had it) => the token the teacher accepted in
// its place. Resubmitting the SAME source with this attached is what re-runs
// resolution through the normal path (BuildCharacterisationPreview applies it
// to the cell text before ever calling the resolver) — accepting a
// suggestion is never a client-side shortcut that fabricates a resolution.
const corrections = reactive<Record<string, string>>({});

function cellKey(rowNumber: number, columnIndex: number): string {
    return `${rowNumber}:${columnIndex}`;
}

function clearStructuralState(): void {
    structuralHeaders.value = [];
    Object.keys(structuralRowStates).forEach((key) => delete structuralRowStates[Number(key)]);
    ignoredColumns.clear();
    Object.keys(structuralCellConfidence).forEach((key) => delete structuralCellConfidence[key]);
    editedStructuralCells.clear();
}

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
    clearStructuralState();
    pendingSourceKind.value = null;
    pendingOriginalFilename.value = null;
    // F1: `submittedExtractedTable` deliberately NOT cleared here, for the
    // same reason `corrections` isn't (see clearCorrections()'s own
    // comment): postPreview() calls clearPreviewState() on every successful
    // response, including the very one applyStructuralCorrections() just
    // submitted the corrected table through — clearing it here would erase
    // it before acceptSuggestion() ever got a chance to reuse it. It is
    // cleared explicitly, alongside corrections, wherever a genuinely NEW
    // source is being started (resetToInput(), backToInputStep()).
}

// §19: a correction belongs to the source it was accepted against — carrying
// it over to a freshly chosen file/paste would silently rewrite a token
// nobody looked at. Deliberately NOT called from clearPreviewState() itself:
// that helper also runs on every successful postPreview() response
// (including the very one a correction was just submitted to re-fetch), and
// clearing there would drop the correction before it could ever be reused on
// a later resubmission.
function clearCorrections(): void {
    Object.keys(corrections).forEach((key) => delete corrections[key]);
}

// F1: a submitted, corrected table belongs to the source it was corrected
// from — same reasoning as clearCorrections() above, so it is always called
// alongside it, never from clearPreviewState() itself.
function clearSubmittedExtractedTable(): void {
    submittedExtractedTable.value = null;
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
    clearCorrections();
    clearSubmittedExtractedTable();
}

// Usada pelo "Escolher outro ficheiro" e pelo "Voltar" do seletor de tabelas
// — volta ao passo de entrada SEM apagar o que já foi colado/escolhido (essa
// é a diferença com resetToInput(), que só corre à abertura do diálogo), mas
// sempre a limpar o estado da pré-visualização anterior (F5).
function backToInputStep(): void {
    step.value = 'input';
    tableChoices.value = null;
    clearPreviewState();
    clearCorrections();
    clearSubmittedExtractedTable();
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

/**
 * The one place that POSTs to the preview endpoint and decides which of the
 * THREE shapes it can come back as: a .docx table chooser, a §38 structural
 * review, or an ordinary per-student preview. Used both by loadPreview() (a
 * fresh source) and applyStructuralCorrections() (§39's corrected
 * resubmission) — the branching is identical either way, only what goes into
 * `body` differs.
 */
async function postPreview(body: FormData): Promise<void> {
    loading.value = true;
    loadError.value = null;

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

        const typedPayload = payload as PreviewResponse;

        // Limpa ANTES de repovoar (F5) — uma pré-visualização nova nunca deve
        // herdar linhas da anterior, mesmo que esta chamada não tenha passado
        // por resetToInput()/backToInputStep() (ex.: escolher uma tabela
        // diferente no seletor do .docx chama loadPreview() diretamente).
        clearPreviewState();
        previewData.value = typedPayload;

        // §38: a complex source is shown the WHOLE recognised table first —
        // header, data, and what was classified Group/Legend and dropped —
        // rather than jumping straight to the per-student preview. The
        // trigger (`show_structural_step`) is decided server-side, once, so
        // it can never drift from NormaliseExtractedTable's own
        // classification (see CharacterisationImportController::preview()).
        if (typedPayload.show_structural_step) {
            pendingSourceKind.value = typedPayload.source_kind;
            pendingOriginalFilename.value = typedPayload.original_filename;
            structuralHeaders.value = typedPayload.structural.headers;

            typedPayload.structural.rows.forEach((row) => {
                structuralRowStates[row.number] = { row, kind: row.kind, cells: [...row.cells] };
            });

            populateStructuralCellConfidence(typedPayload.structural.rows);

            step.value = 'structural';

            return;
        }

        populateRowStates(typedPayload.preview.rows);
        step.value = 'preview';
    } catch {
        loadError.value = 'Não foi possível ler o ficheiro.';
    } finally {
        loading.value = false;
    }
}

function populateRowStates(rows: PreviewRow[]): void {
    rows.forEach((row) => {
        rowStates[row.row_number] = {
            row,
            // Só as linhas pré-selecionadas pelo servidor começam marcadas —
            // tudo o resto exige uma decisão explícita do professor.
            included: row.match.preselected,
            enrollmentUlid: row.match.enrollment_ulid,
            sections: { ...row.sections },
        };
    });
}

/**
 * §18 — extraction confidence overlay for the structural grid. ONLY ever
 * populated when the table being reviewed came from OCR: `ocrExtractedTable`
 * is the same object extractTableFromImage() produced, still held locally,
 * and its rows/cells are in the exact same order (by 1-based row number) as
 * the server's `structural.rows` — the server never reorders rows, only
 * classifies and (for Group/Legend) drops them from $grid, never from
 * `structural.rows` itself. A .docx or pasted-HTML table read EXACTLY has no
 * extraction-confidence question to answer (see ExtractedCell's own
 * docblock), so this is a no-op for those sources.
 */
function populateStructuralCellConfidence(rows: StructuralRow[]): void {
    if (ocrExtractedTable.value === null) {
        return;
    }

    const ocrRowsByNumber = new Map(ocrExtractedTable.value.rows.map((row) => [row.index + 1, row]));

    rows.forEach((row) => {
        const ocrRow = ocrRowsByNumber.get(row.number);

        if (!ocrRow) {
            return;
        }

        ocrRow.cells.forEach((cell) => {
            structuralCellConfidence[cellKey(row.number, cell.column)] = cell.confidence;
        });
    });
}

// F4: `corrections` travels as ONE JSON string field, not as
// `corrections[<token>]` form keys — a raw token can itself contain `[`,
// `]` or `.`, which PHP's own form-key parser reads as array-nesting syntax
// and silently truncates or misroutes the key. A JSON object has no such
// collision: see parseCorrectionsPayload()'s own comment on the server side.
function appendCorrections(body: FormData): void {
    if (Object.keys(corrections).length > 0) {
        body.append('corrections', JSON.stringify(corrections));
    }
}

/**
 * Builds the same FormData shape `postPreview` always expects, from
 * whichever source is currently held — factored out of `loadPreview` so
 * `acceptSuggestion` (§19) can resubmit the SAME source, with `corrections`
 * attached, without duplicating the branching.
 */
function buildPreviewBody(tableIndex: number | null): FormData {
    const body = new FormData();

    // F1: once a corrected table has actually been submitted (§39), THAT is
    // the source of truth for every later resubmission — never the original
    // OCR table or raw file/HTML still sitting in `ocrExtractedTable`/`file`/
    // `pastedHtml`, which are uncorrected and, for a .docx/pasted HTML, get
    // RE-READ from scratch server-side (see ExtractedTableSource::CorrectedDocx's
    // own docblock on why `corrected_docx`/`corrected_pasted_html` exist at
    // all). Resubmitting the raw source here is exactly what threw away
    // every structural correction the moment a suggestion was accepted.
    if (submittedExtractedTable.value !== null) {
        body.append('extracted_table', JSON.stringify(submittedExtractedTable.value));
        body.append('source_kind', submittedExtractedTable.value.source_type);

        if (pendingOriginalFilename.value !== null) {
            body.append('original_filename', pendingOriginalFilename.value);
        }

        appendCorrections(body);

        return body;
    }

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

    appendCorrections(body);

    return body;
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

    await postPreview(buildPreviewBody(tableIndex));
}

/**
 * §19: the explicit, opt-in acceptance a suggestion requires — nothing is
 * pre-accepted, and declining (never calling this) leaves the original token
 * and its unresolved state exactly as they were. Re-submits the very same
 * source through the very same preview endpoint, only now with this
 * correction attached, so resolution runs through the NORMAL path
 * (BuildCharacterisationPreview -> LegalCodeResolver) rather than the client
 * fabricating a resolved measure of its own.
 */
async function acceptSuggestion(rawToken: string, acceptedToken: string): Promise<void> {
    // F4: trimmed — the server matches this key against the cell text with a
    // `\b…\b`-bounded regex (BuildCharacterisationPreview::applyCorrections()),
    // and a boundary can never sit next to a space INSIDE the pattern. An
    // untrimmed token (a common OCR artefact) made that regex unmatchable,
    // so clicking "Aceitar" reloaded the preview with nothing changed and no
    // error — a dead end. Trimming here, once, keeps the key that reaches
    // the server identical to what SuggestAcronymCorrection::suggest()
    // itself matched on (it trims too).
    corrections[rawToken.trim()] = acceptedToken;

    await postPreview(buildPreviewBody(null));
}

// §39: the row-kind choices the structural grid offers — mirrors
// ExtractedRowKind, plus 'ignore' (never sent as a row at all; see
// applyStructuralCorrections()). Portuguese labels only exist here, in the
// UI layer — the server keeps speaking its own enum values.
const structuralKindOptions: { value: StructuralRowState['kind']; label: string }[] = [
    { value: 'header', label: 'Cabeçalho' },
    { value: 'data', label: 'Aluno (dados)' },
    { value: 'group', label: 'Agrupamento (não é aluno)' },
    { value: 'legend', label: 'Legenda' },
    { value: 'ignore', label: 'Ignorar linha' },
];

const structuralRows = computed(() => Object.values(structuralRowStates).sort((a, b) => a.row.number - b.row.number));

function toggleIgnoredColumn(columnIndex: number, value: boolean): void {
    if (value) {
        ignoredColumns.add(columnIndex);
    } else {
        ignoredColumns.delete(columnIndex);
    }
}

// Defect (2026-09-20 structural review report): a single-line `<input>`
// cannot hold or display "\n" at all — a browser's own value-sanitisation
// algorithm strips it, silently, the moment Vue sets the DOM value, whether
// or not the teacher ever touches the field. A cell with a genuinely
// multiline observation (a common shape: "MU a) b) e)\nNecessita de apoio…")
// would therefore lose its line break just from §38's review step being
// OPENED, before any edit — the one step whose whole purpose is to let the
// teacher check the table before trusting it. Checked against `state.row`,
// the ORIGINAL StructuralRow from the server, never `state.cells` (which, by
// the time this is asked, may already have been rendered into an `<input>`
// once and corrupted) — so a cell that started multiline stays on a
// `<textarea>` for its whole life in this step, edited or not.
function cellHasNewline(row: StructuralRow, columnIndex: number): boolean {
    return (row.cells[columnIndex] ?? '').includes('\n');
}

function markCellEdited(rowNumber: number, columnIndex: number): void {
    // §19: an edited cell is exact — whatever the teacher typed — not a
    // guess tesseract made, so its extraction-confidence badge must stop
    // showing the moment she touches it. The ORIGINAL token stays visible
    // nowhere else in this step (§39 has no "raw_token" concept — that is
    // the per-student preview's job); this step edits the recognised table
    // directly, and an edit here is the explicit acceptance §19 requires.
    editedStructuralCells.add(cellKey(rowNumber, columnIndex));
}

/**
 * §39: turns the (possibly edited) structural grid back into the same
 * ExtractedTablePayload shape OCR already produces, and resubmits it through
 * the identical preview endpoint/pipeline — normalise → match → merge →
 * confirm never branches on where the table came from. Ignored rows are
 * dropped outright; ignored columns are removed from every surviving row,
 * re-numbered so column indices stay contiguous (see the payload's own
 * `column` field).
 */
function applyStructuralCorrections(): void {
    const keptColumnIndices = structuralHeaders.value
        .map((_, index) => index)
        .filter((index) => !ignoredColumns.has(index));

    const rows: ExtractedRowPayload[] = structuralRows.value
        .filter((state) => state.kind !== 'ignore')
        .map((state, rowIndex): ExtractedRowPayload => ({
            index: rowIndex,
            // 'unknown' never reaches here — the row-kind selector only ever
            // offers header/data/group/legend/ignore (see
            // structuralKindOptions) — but the type is shared with the OCR
            // payload's own ExtractedRowPayload, which allows it.
            kind: state.kind as ExtractedRowPayload['kind'],
            cells: keptColumnIndices.map((columnIndex, newColumnIndex): ExtractedCellPayload => ({
                text: state.cells[columnIndex] ?? '',
                row: rowIndex,
                column: newColumnIndex,
                colspan: 1,
                rowspan: 1,
                confidence: editedStructuralCells.has(cellKey(state.row.number, columnIndex))
                    ? null
                    : (structuralCellConfidence[cellKey(state.row.number, columnIndex)] ?? null),
            })),
        }));

    // Corrected resubmissions use their OWN source_type — never the real
    // ::Docx/::PastedHtml, which stay reserved for a genuine server-side
    // read of the original file (see ExtractedTableSource::CorrectedDocx's
    // own docblock). An OCR source keeps its own pasted_image/image_upload
    // value — corrections there were already an accepted source_type before
    // §39 existed.
    const sourceType: ExtractedTablePayload['source_type'] =
        pendingSourceKind.value === 'docx'
            ? 'corrected_docx'
            : pendingSourceKind.value === 'pasted_html'
              ? 'corrected_pasted_html'
              : pendingSourceKind.value === 'image_upload'
                ? 'image_upload'
                : 'pasted_image';

    const correctedTable: ExtractedTablePayload = {
        rows,
        source_type: sourceType,
        source_filename: pendingOriginalFilename.value,
        warnings: [],
        extraction_confidence: 1,
    };

    // F1: this IS the corrected table `buildPreviewBody()` must prefer from
    // now on — set BEFORE posting, never inside postPreview()'s response
    // handling, since clearPreviewState() runs there and must not erase it
    // (see clearPreviewState()'s own comment on why).
    submittedExtractedTable.value = correctedTable;

    const body = new FormData();
    body.append('extracted_table', JSON.stringify(correctedTable));

    if (pendingOriginalFilename.value !== null) {
        body.append('original_filename', pendingOriginalFilename.value);
    }

    void postPreview(body);
}

function chooseTable(index: number): void {
    void loadPreview(index);
}

// §38 report (2026-09-21 real-table screenshots): a real caracterização table
// has ~15 columns, and an ordinary `sm:max-w-3xl` dialog only ever showed
// 3-4 of them. This step ALONE gets a much wider, near-viewport modal — a
// distinct `DialogContent` class, computed from `step`, so every OTHER
// dialog in the app (including this component's own other steps) keeps its
// normal, compact size. `dvh` (not `vh`) for the height clamp: `vh` includes
// the mobile browser chrome that `dvh` already excludes, which is what kept
// the footer reachable without a fixed height that breaks on a short phone
// screen.
const dialogContentClass = computed(() => {
    if (step.value === 'structural') {
        return 'flex max-h-[92dvh] w-[95vw] max-w-[1500px] flex-col overflow-hidden p-4 sm:p-6';
    }

    return 'max-h-[85vh] overflow-y-auto sm:max-w-3xl';
});

// §38 report: not every column deserves the same width — a 15-column table
// at one uniform width was exactly what made the reviewer unusable (a code
// column as wide as a free-text one). Classified from the header text alone
// (the only thing available client-side), never from cell CONTENT, since a
// short answer in a wide free-text column must not narrow it back down.
// Returns inline styles (not just a Tailwind class) so the same value can
// bound both the <th>/<td> and, via the "MUST NOT STRETCH THE GRID"
// requirement, the editable field inside it.
type ColumnWidth = { minWidth: string; width: string; maxWidth?: string };

const NARROW_HEADER_PATTERN = /^(mu|ms|ma|rtp|pei|x|p|m|ing\.?|sim|não|nao)$/i;

function columnWidthFor(header: string): ColumnWidth {
    const normalised = (header ?? '').trim().toLowerCase();

    if (normalised === '') {
        return { minWidth: '96px', width: '110px' };
    }

    if (normalised.includes('observ') || normalised.includes('nota') || normalised.includes('descri')) {
        return { minWidth: '260px', width: '320px' };
    }

    if (normalised.includes('aluno') || normalised.includes('nome')) {
        return { minWidth: '160px', width: '200px' };
    }

    // Short codes (MU/MS/MA, RTP/PEI, X marks, P/M/Ing.) or anything a
    // couple of characters long — a real header this short is never a
    // free-text column.
    if (NARROW_HEADER_PATTERN.test(normalised) || normalised.length <= 5) {
        return { minWidth: '56px', width: '72px', maxWidth: '96px' };
    }

    return { minWidth: '110px', width: '140px' };
}

function columnWidthStyle(header: string): Record<string, string> {
    const { minWidth, width, maxWidth } = columnWidthFor(header);

    return {
        minWidth,
        width,
        ...(maxWidth ? { maxWidth } : {}),
    };
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

// «Não encontrado»/«Ambíguo»: escolher um aluno no seletor era um gesto que
// destravava a checkbox sem a marcar — quem escolhia o aluno ainda tinha de
// dar um segundo passo, sem indicação de que faltava. Escolher UM aluno
// concreto já é a decisão que a checkbox representa, por isso o próprio
// gesto de escolher inclui a linha; voltar a «Escolher…» desinclui-a, porque
// deixa de haver decisão nenhuma para confirmar.
function onManualMatchChange(state: RowState, value: string | number | null): void {
    state.enrollmentUlid = value === null ? null : String(value);
    state.included = state.enrollmentUlid !== null && state.enrollmentUlid !== '';
}

const confirmableCount = computed(
    () => rows.value.filter((state) => state.included && canConfirm(state)).length,
);

// F9 continued: «0 de 0» sozinho não diz se a tabela estava vazia ou se
// tinha linhas que nenhuma sobreviveu — e, quando tinha, não diz PORQUÊ. Isto
// lê os números que o servidor já contou (data_row_count, footer_row_count,
// has_recognised_content_column — ver BuildCharacterisationPreview::build())
// em vez de adivinhar a partir do que chegou ao ecrã, precisamente porque o
// que chegou ao ecrã é o que desapareceu.
const zeroRowsReason = computed(() => {
    const preview = previewData.value?.preview;

    if (preview === undefined) {
        return '';
    }

    if (preview.data_row_count === 0) {
        return 'O ficheiro não tinha linhas de dados abaixo do cabeçalho.';
    }

    if (!preview.has_recognised_content_column) {
        return `Havia ${preview.data_row_count} linha(s) de dados, mas nenhuma coluna de conteúdo foi reconhecida (caracterização, medidas ou apoios) — reveja os cabeçalhos do ficheiro.`;
    }

    if (preview.footer_row_count === preview.data_row_count) {
        return 'Todas as linhas foram identificadas como rodapé ou totais, sem um aluno associado.';
    }

    return 'Ou o ficheiro não tinha linhas de dados reconhecíveis, ou todas foram ignoradas — veja os avisos acima, se os houver. Pode tentar outro formato, escolher outra tabela do mesmo documento ou carregar outro ficheiro.';
});

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
        <DialogContent :class="dialogContentClass">
            <DialogHeader class="shrink-0">
                <DialogTitle>Importar caracterização</DialogTitle>
            </DialogHeader>

            <template v-if="step === 'input'">
                <!-- Defect (2026-09-20, 390px screenshots): DialogContent is
                     `display: grid` with no explicit template, so its
                     implicit column track sizes itself to the MAX-CONTENT
                     width of whatever is inside — for a paragraph, that is
                     its full text laid out on ONE line, as if it never
                     wrapped. That pushed this div (and everything in it)
                     wider than the dialog itself, with no visible
                     scrollbar, so the extra width simply sat past the
                     dialog's edge — sentences that read as "cut off" in a
                     screenshot even though `document.documentElement
                     .scrollWidth === window.innerWidth` stayed true (the
                     PAGE never grew; the dialog's own content did).
                     `min-w-0` is the standard escape from that grid-track
                     sizing rule: it lets this item shrink back down to the
                     grid's actual column width, so text wraps within it
                     instead of dictating a wider one. -->
                <div class="min-w-0 space-y-4 py-2">
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
                <!-- min-w-0: see the same comment on the other steps'
                     wrapper — DialogContent's implicit grid track otherwise
                     sizes to this div's max-content width. -->
                <div class="min-w-0 space-y-3 py-2">
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

            <!-- §38: "Rever tabela reconhecida" — only for complex sources
                 (.docx, image/OCR, pasted HTML with a merged cell; see
                 `show_structural_step`, decided server-side). A single step
                 inside this same dialog, not a second application: compact,
                 with scroll kept INSIDE the table (§51/§52) so the page
                 itself never grows wider than the viewport. -->
            <template v-else-if="step === 'structural'">
                <!-- min-w-0/min-h-0: DialogContent is now `flex flex-col` for
                     this step (dialogContentClass) so this wrapper can be the
                     ONE flex child that grows to fill the remaining height
                     and lets the table area scroll internally — without
                     min-h-0 a flex child never shrinks below its content's
                     natural height, which is exactly what pushed the footer
                     off-screen instead of the table scrolling. -->
                <div class="flex min-h-0 min-w-0 flex-1 flex-col gap-3 py-2">
                    <p class="shrink-0 text-sm text-muted-foreground">
                        Esta é a tabela tal como foi reconhecida. Corrija o que for preciso — o texto de uma célula,
                        se uma linha é de alunos, um agrupamento ou uma legenda, ou se uma coluna deve ser ignorada —
                        antes de continuar para a pré-visualização por aluno.
                    </p>

                    <ul v-if="previewData?.warnings.length" class="shrink-0 space-y-1 rounded-md border p-2 text-xs text-muted-foreground">
                        <li v-for="(warning, index) in previewData.warnings" :key="index" class="flex items-start gap-1.5">
                            <AlertTriangle class="mt-0.5 size-3.5 shrink-0" /> {{ warning }}
                        </li>
                    </ul>

                    <!-- Scroll HORIZONTAL e VERTICAL só aqui dentro — nunca a
                         página (§51/§52). `flex-1 min-h-0` (not a fixed
                         max-h) so the table claims whatever height the wider
                         modal has left, on any screen. First column and
                         header row are `sticky` (§38 report E/F) so a
                         15-column table never loses the "que tipo de linha é
                         esta" context while scrolling right, nor the column
                         headers while scrolling down. -->
                    <div class="min-h-0 flex-1 overflow-auto rounded-md border">
                        <table class="w-full min-w-max border-collapse text-xs">
                            <thead>
                                <tr>
                                    <th class="sticky top-0 left-0 z-20 w-40 border-b bg-muted p-2 text-left font-medium">Linha</th>
                                    <th
                                        v-for="(header, columnIndex) in structuralHeaders"
                                        :key="columnIndex"
                                        class="sticky top-0 z-10 border-b bg-muted p-2 text-left font-medium"
                                        :style="columnWidthStyle(header)"
                                    >
                                        <div class="space-y-1">
                                            <span class="block truncate" :class="{ 'line-through opacity-50': ignoredColumns.has(columnIndex) }">
                                                {{ header || `Coluna ${columnIndex + 1}` }}
                                            </span>
                                            <label class="flex items-center gap-1.5 text-[11px] font-normal text-muted-foreground">
                                                <Checkbox
                                                    :model-value="ignoredColumns.has(columnIndex)"
                                                    @update:model-value="(value) => toggleIgnoredColumn(columnIndex, value === true)"
                                                />
                                                Ignorar
                                            </label>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="state in structuralRows"
                                    :key="state.row.number"
                                    class="border-b align-top"
                                    :class="{ 'bg-muted/40 opacity-70': state.kind !== 'data' }"
                                >
                                    <td
                                        class="sticky left-0 z-10 bg-background p-2"
                                        :class="{ 'bg-muted/40': state.kind !== 'data' }"
                                    >
                                        <NativeSelect v-model="state.kind" :aria-label="`Tipo da linha ${state.row.number}`">
                                            <option v-for="option in structuralKindOptions" :key="option.value" :value="option.value">
                                                {{ option.label }}
                                            </option>
                                        </NativeSelect>
                                        <!-- F40: PORQUÊ esta linha foi/vai ser
                                             ignorada — em texto, não só a
                                             opacidade (§51 avisos não só por
                                             cor). -->
                                        <p v-if="state.kind === 'group'" class="mt-1 text-[11px] text-muted-foreground">
                                            Agrupamento — não entra como aluno.
                                        </p>
                                        <p v-else-if="state.kind === 'legend'" class="mt-1 text-[11px] text-muted-foreground">
                                            Legenda — ignorada.
                                        </p>
                                        <p v-else-if="state.kind === 'ignore'" class="mt-1 text-[11px] text-muted-foreground">
                                            Esta linha não será importada.
                                        </p>
                                        <p v-else-if="state.kind === 'header'" class="mt-1 text-[11px] text-muted-foreground">
                                            Título das colunas.
                                        </p>
                                    </td>
                                    <td
                                        v-for="(header, columnIndex) in structuralHeaders"
                                        :key="columnIndex"
                                        class="p-2 align-top"
                                        :class="{ 'opacity-40': ignoredColumns.has(columnIndex) }"
                                        :style="columnWidthStyle(header)"
                                    >
                                        <!-- Defect (2026-09-20): a value
                                             containing "\n" is silently
                                             stripped by a single-line
                                             `<input>` — a textarea is the
                                             only field here that round-trips
                                             a multiline observation losslessly,
                                             touched or not.
                                             §38 report D: `w-full min-w-0`
                                             keeps the field exactly as wide as
                                             its (role-sized) cell, never
                                             wider — it was an editable field
                                             ignoring the cell's width that
                                             stretched the whole grid back out
                                             to one-size-fits-all. It grows
                                             VERTICALLY (rows, wrapping text)
                                             rather than horizontally. -->
                                        <Textarea
                                            v-if="cellHasNewline(state.row, columnIndex)"
                                            v-model="state.cells[columnIndex]"
                                            :disabled="ignoredColumns.has(columnIndex)"
                                            :aria-label="`Linha ${state.row.number}, coluna ${columnIndex + 1}`"
                                            rows="2"
                                            class="min-h-8 w-full min-w-0 resize-y text-xs"
                                            @input="markCellEdited(state.row.number, columnIndex)"
                                        />
                                        <Input
                                            v-else
                                            v-model="state.cells[columnIndex]"
                                            :disabled="ignoredColumns.has(columnIndex)"
                                            :aria-label="`Linha ${state.row.number}, coluna ${columnIndex + 1}`"
                                            class="h-8 w-full min-w-0 text-xs"
                                            @input="markCellEdited(state.row.number, columnIndex)"
                                        />
                                        <!-- §18: confiança de EXTRAÇÃO (li bem
                                             esta célula?) — só existe para OCR,
                                             nunca para uma célula já editada
                                             (essa passou a ser exata), e nunca
                                             confundida com a confiança de
                                             domínio (essa aparece só no passo
                                             seguinte, por medida/recurso). -->
                                        <p
                                            v-if="
                                                !editedStructuralCells.has(cellKey(state.row.number, columnIndex)) &&
                                                structuralCellConfidence[cellKey(state.row.number, columnIndex)] !== undefined &&
                                                structuralCellConfidence[cellKey(state.row.number, columnIndex)] !== null &&
                                                structuralCellConfidence[cellKey(state.row.number, columnIndex)]! < 0.7
                                            "
                                            class="mt-1 flex items-center gap-1 text-[11px] text-amber-700 dark:text-amber-500"
                                        >
                                            <AlertTriangle class="size-3 shrink-0" />
                                            Confiança de leitura baixa — confirme o texto.
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- §38 report I: `shrink-0` (this step's DialogContent is a
                     flex column) plus a top border/background keeps this
                     footer visibly separated and reachable WITHOUT scrolling
                     back through a 15-column table — it is never part of the
                     scrollable table area above. -->
                <DialogFooter class="shrink-0 border-t pt-3">
                    <Button type="button" variant="outline" @click="backToInputStep">Escolher outro ficheiro</Button>
                    <Button type="button" :disabled="loading" @click="applyStructuralCorrections">
                        <Loader2 v-if="loading" class="size-4 animate-spin" />
                        Continuar
                    </Button>
                </DialogFooter>
            </template>

            <template v-else-if="step === 'preview' && previewData !== null">
                <!-- Defect (2026-09-20, 390px screenshots): DialogContent is
                     `display: grid` with no explicit template, so its
                     implicit column track sizes itself to the MAX-CONTENT
                     width of whatever is inside — for a paragraph, that is
                     its full text laid out on ONE line, as if it never
                     wrapped. That pushed this div (and everything in it)
                     wider than the dialog itself, with no visible
                     scrollbar, so the extra width simply sat past the
                     dialog's edge — sentences that read as "cut off" in a
                     screenshot even though `document.documentElement
                     .scrollWidth === window.innerWidth` stayed true (the
                     PAGE never grew; the dialog's own content did).
                     `min-w-0` is the standard escape from that grid-track
                     sizing rule: it lets this item shrink back down to the
                     grid's actual column width, so text wraps within it
                     instead of dictating a wider one. -->
                <div class="min-w-0 space-y-4 py-2">
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
                        <p class="text-xs text-muted-foreground">{{ zeroRowsReason }}</p>
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
                                    :model-value="state.enrollmentUlid"
                                    @update:model-value="(value) => onManualMatchChange(state, value)"
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
                                    <!-- §18: confiança de EXTRAÇÃO — indicador
                                         visualmente distinto do domínio (acima),
                                         nunca combinado com ele. -->
                                    <span
                                        v-if="isLowExtractionConfidence(measure.extraction_confidence)"
                                        class="mt-0.5 flex items-center gap-1 text-amber-700 not-italic dark:text-amber-500"
                                    >
                                        <AlertTriangle class="size-3 shrink-0" />
                                        Confiança de leitura baixa — confirme o texto lido.
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
                                    <span
                                        v-if="isLowExtractionConfidence(resource.extraction_confidence)"
                                        class="mt-0.5 flex items-center gap-1 text-amber-700 not-italic dark:text-amber-500"
                                    >
                                        <AlertTriangle class="size-3 shrink-0" />
                                        Confiança de leitura baixa — confirme o texto lido.
                                    </span>
                                </p>
                            </div>

                            <div v-if="state.row.unresolved.length > 0" class="mt-3 space-y-1">
                                <p class="text-xs font-medium text-muted-foreground">
                                    Não reconhecido — não será gravado
                                </p>
                                <div v-for="unresolved in state.row.unresolved" :key="unresolved.raw_token" class="text-xs text-muted-foreground">
                                    <p>
                                        {{ unresolved.raw_token }}
                                        <!-- Confiança de DOMÍNIO ("percebo o que
                                             isto significa?") — já existia. -->
                                        <span>({{ unresolved.confidence_label }})</span>
                                    </p>
                                    <!-- §18: confiança de EXTRAÇÃO ("li bem esta
                                         célula?") — indicador SEPARADO, nunca
                                         fundido com o de domínio acima: uma
                                         sigla lida na perfeição e não
                                         reconhecida não é o mesmo problema que
                                         uma sigla mal lida. -->
                                    <p
                                        v-if="isLowExtractionConfidence(unresolved.extraction_confidence)"
                                        class="mt-0.5 flex items-center gap-1 text-amber-700 dark:text-amber-500"
                                    >
                                        <AlertTriangle class="size-3 shrink-0" />
                                        Confiança de leitura baixa — pode ter sido mal lida.
                                    </p>
                                    <!-- §19: sugestão explícita, nunca aplicada
                                         por omissão — o token original fica
                                         visível e inalterado até ser aceite. -->
                                    <p v-if="unresolved.suggested_correction" class="mt-0.5 flex flex-wrap items-center gap-1.5">
                                        <span>
                                            Talvez quisesse dizer
                                            <strong>{{ unresolved.suggested_correction.token }}</strong>
                                            <template v-if="unresolved.suggested_correction.expansion">
                                                ({{ unresolved.suggested_correction.expansion }})</template
                                            >?
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="h-6 px-2 text-[11px]"
                                            :disabled="loading"
                                            @click="acceptSuggestion(unresolved.raw_token, unresolved.suggested_correction.token)"
                                        >
                                            Aceitar correção
                                        </Button>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- F9 continued: com 0 linhas, o cartão acima já mostra a
                     razão e a única ação útil («Escolher outro ficheiro»).
                     Repetir aqui um botão «Confirmar importação» desativado,
                     ao lado de "0 de 0", era um botão morto sem explicação —
                     por isto este rodapé só aparece quando há alguma linha. -->
                <DialogFooter
                    v-if="rows.length > 0"
                    class="min-w-0 flex-col items-stretch gap-2 sm:flex-row sm:items-center sm:justify-between"
                >
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
