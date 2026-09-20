<?php

namespace App\Http\Controllers;

use App\Actions\Characterisation\ApplyCharacterisationImport;
use App\Models\SchoolClass;
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\Import\BuildCharacterisationPreview;
use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Characterisation\Import\Extraction\ExtractedCell;
use App\Services\Characterisation\Import\Extraction\ExtractedRow;
use App\Services\Characterisation\Import\Extraction\ExtractedRowKind;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
use App\Services\Characterisation\Import\Extraction\ExtractedTableSource;
use App\Services\Characterisation\Import\ReadCharacterisationTable;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Importing a characterisation: propose, then — separately — write.
 *
 * TWO ENDPOINTS, AND THE FIRST ONE CANNOT WRITE. `preview` reads the file in
 * the request that carried it, proposes, and forgets it: no staging folder, no
 * row in an intermediate state, no token naming a copy of thirty children's
 * text sitting on disk until someone remembers to prune it. `store` accepts
 * only explicit per-row decisions and re-verifies every one of them against the
 * class.
 *
 * That split is why «nada é gravado antes de confirmar» is not a promise this
 * controller has to keep — it is a shape it cannot break.
 */
class CharacterisationImportController extends Controller
{
    /**
     * Named to match the message DocxTableExtractor throws when the same
     * limit is crossed — stated here too so the validator's own message
     * tells the teacher the number before the extractor is ever reached.
     */
    private const MAX_DOCX_UPLOAD_KILOBYTES = 8 * 1024;

    private const MAX_SPREADSHEET_UPLOAD_KILOBYTES = 5120;

    /**
     * Mirrors NormaliseExtractedTable::MAX_ROWS/MAX_COLUMNS/MAX_CELLS. The
     * `extracted_table` payload is CLIENT JSON built by an OCR run this
     * server never saw — hostile input by default — so it is bounded here,
     * at the validator, before a single ExtractedCell object is built from
     * it, exactly as strictly as the extractors that read a file already are.
     */
    private const MAX_EXTRACTED_TABLE_ROWS = 500;

    private const MAX_EXTRACTED_TABLE_COLUMNS = 40;

    private const MAX_EXTRACTED_CELL_TEXT_LENGTH = 2000;

    public function __construct(
        private readonly ReadCharacterisationTable $reader,
        private readonly BuildCharacterisationPreview $builder,
        private readonly ApplyCharacterisationImport $importer,
        private readonly AuditLog $audit,
        private readonly DocxTableExtractor $docxExtractor,
    ) {}

    /**
     * Read, match, resolve, propose. Writes nothing.
     */
    public function preview(Request $request, SchoolClass $class): JsonResponse
    {
        // `update`, not `view`: this produces a proposal to change the class,
        // and someone who may only read it has no business generating one.
        Gate::authorize('update', $class);

        $data = $request->validate([
            'pasted_text' => ['nullable', 'string', 'max:200000'],
            // Clipboard HTML from Word/Excel/Google Sheets (§8) — a second,
            // richer alternative to pasted_text, never both read at once.
            'pasted_html' => ['nullable', 'string', 'max:2000000'],
            'file' => [
                'nullable',
                'file',
                // The bigger of the two ceilings at the validator level: a
                // .docx over its own 8 MB limit is refused by the callback
                // rule below with the number named, before this generic size
                // rule would otherwise produce a less specific message.
                'max:'.self::MAX_DOCX_UPLOAD_KILOBYTES,
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof UploadedFile) {
                        return;
                    }

                    $extension = strtolower((string) $value->getClientOriginalExtension());

                    if ($extension === 'docx') {
                        // .docx is itself a zip, so the extension proves
                        // nothing — Laravel's mimes: rule is unreliable for
                        // it. The extractor's own supports() does the real
                        // check: opens it as OOXML, not just as a zip.
                        if (! $this->docxExtractor->supports($value)) {
                            $fail(__('Este ficheiro não é um documento Word (.docx) válido.'));
                        }

                        return;
                    }

                    if (! in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
                        $fail(__('Só é possível importar ficheiros CSV, Excel (.xlsx) ou Word (.docx).'));

                        return;
                    }

                    if ($value->getSize() > self::MAX_SPREADSHEET_UPLOAD_KILOBYTES * 1024) {
                        $fail(__('O ficheiro é demasiado grande.'));
                    }
                },
            ],
            // Escolha de tabela dentro de um .docx com mais do que uma —
            // ausente na primeira chamada, presente depois de o professor
            // escolher no seletor.
            'table_index' => ['nullable', 'integer', 'min:0'],
            // Produzido inteiramente no browser por OCR (nunca a imagem em
            // si — ver a nota de privacidade em
            // characterisation-image-extraction.ts). JSON, não um array
            // aninhado no corpo do pedido, porque chega como um campo de
            // FormData ao lado de um possível ficheiro — descodificado
            // manualmente a seguir, e cada limite aqui espelha
            // NormaliseExtractedTable::MAX_ROWS/MAX_COLUMNS/MAX_CELLS.
            'extracted_table' => ['nullable', 'string', 'max:2000000'],
            // §39: when `extracted_table` is a CORRECTED table sent back from
            // the structural review step for a .docx/pasted-HTML source
            // (never for OCR, which has no filename of its own to lose), the
            // original filename would otherwise be lost — the request no
            // longer carries the original `file`, only the corrected table.
            'original_filename' => ['nullable', 'string', 'max:255'],
            // §19: acceptances of a suggested correction — raw token as it
            // appeared in the source => the token the teacher accepted in its
            // place. Never a shortcut around LegalCodeResolver: see
            // BuildCharacterisationPreview::applyCorrections()'s own comment.
            //
            // F4: a JSON STRING, not a `corrections[key]=value` form field —
            // the same reason `extracted_table` is one. A raw token can
            // itself contain `[`, `]` or `.`, which PHP's own form-key
            // parser (`parse_str`) treats as array-nesting syntax: a token
            // like "MU[1]" silently became a DIFFERENT, shorter key once it
            // crossed the wire, and the correction landed on the wrong
            // token — or on none at all. A JSON object has no such
            // collision: whatever the token is, it round-trips as a plain
            // string key. Decoded and validated in parseCorrectionsPayload().
            'corrections' => ['nullable', 'string', 'max:8000'],
        ]);

        $pastedHtml = $data['pasted_html'] ?? null;
        $pastedText = $data['pasted_text'] ?? null;
        $hasFile = $request->hasFile('file');
        $extractedTableJson = $data['extracted_table'] ?? null;

        if (blank($pastedText) && blank($pastedHtml) && ! $hasFile && blank($extractedTableJson)) {
            throw ValidationException::withMessages([
                'pasted_text' => __('Cole a tabela ou escolha um ficheiro.'),
            ]);
        }

        $errorField = $hasFile
            ? 'file'
            : ($extractedTableJson !== null && $extractedTableJson !== '' ? 'extracted_table' : ($pastedHtml !== null && $pastedHtml !== '' ? 'pasted_html' : 'pasted_text'));

        try {
            if (! blank($extractedTableJson)) {
                $extracted = $this->parseExtractedTablePayload((string) $extractedTableJson);
                $grid = $this->reader->fromExtractedTable($extracted);
                // §39: a "corrected" source resubmits as the ORIGINAL kind
                // (docx/pasted_html) — the teacher confirmed a Word/pasted
                // table, not a synthetic new source, and `store()`'s own
                // source_kind enum (and the confirmation copy the dialog
                // shows) only knows those two words, never "corrected_*".
                $sourceKind = match ($extracted->sourceType) {
                    ExtractedTableSource::CorrectedDocx => 'docx',
                    ExtractedTableSource::CorrectedPastedHtml => 'pasted_html',
                    default => $extracted->sourceType->value,
                };
            } elseif ($hasFile) {
                $file = $request->file('file');
                $isDocx = strtolower((string) $file->getClientOriginalExtension()) === 'docx';

                if ($isDocx) {
                    $resolved = $this->resolveDocxTable($file, $data['table_index'] ?? null);

                    // Not yet one table: hand the teacher the chooser list
                    // instead of guessing (scope item 3). No preview key on
                    // this shape — that is how the dialog tells the two
                    // responses apart.
                    if (is_array($resolved)) {
                        return response()->json($resolved);
                    }

                    $grid = $this->reader->fromExtractedTable($resolved);
                    $sourceKind = 'docx';
                } else {
                    $grid = $this->reader->fromUploadedFile($file);
                    $sourceKind = strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
                }
            } elseif (! blank($pastedHtml)) {
                $grid = $this->reader->fromPastedHtml((string) $pastedHtml);
                $sourceKind = 'pasted_html';
            } else {
                $grid = $this->reader->fromPastedText((string) $pastedText);
                $sourceKind = 'paste';
            }
        } catch (UnreadableSpreadsheet $exception) {
            // The reader's messages say what to DO about it, so they are shown
            // as they are rather than replaced by a generic failure.
            throw ValidationException::withMessages([
                $errorField => $exception->getMessage(),
            ]);
        }

        $corrections = blank($data['corrections'] ?? null)
            ? []
            : $this->parseCorrectionsPayload((string) $data['corrections']);

        $preview = $this->builder->build($class, $grid, $corrections);

        return response()->json([
            'preview' => $preview->toArray(),
            'source_kind' => $sourceKind,
            'original_filename' => $request->file('file')?->getClientOriginalName() ?? ($data['original_filename'] ?? null),
            // Populated only on the pasted-HTML/.docx paths, which go through
            // NormaliseExtractedTable — the csv/xlsx/plain-paste readers
            // never drop a row, so they have nothing to warn about.
            'warnings' => $this->reader->lastWarnings(),
            // §38: the whole recognised table — header rows and dropped
            // Group/Legend rows included — for "Rever tabela reconhecida".
            // `show_structural_step` is the ONE trigger rule, decided here so
            // the dialog never has to re-derive it: .docx and image/OCR
            // sources always show it; a plain CSV/text paste never does (no
            // merge information exists to review); pasted HTML AND .xlsx
            // show it only when the source actually had a merged cell — most
            // Excel/Google Sheets pastes and plain spreadsheets have none,
            // and forcing every one of them through an extra step to review
            // nothing would be the "spreadsheet, not a minimum" this scope
            // explicitly warns against (§39's own framing).
            //
            // F2: .xlsx used to be excluded outright, on the theory that a
            // spreadsheet needs no review because it has no OCR/parsing
            // uncertainty. That missed that .xlsx can carry merged cells too
            // — a left "spine" column merged down the sheet, or a subdivided
            // header — and a merge is exactly the shape that can fold a real
            // student row into the header (see
            // NormaliseExtractedTable::headerLevels()'s own comment on the
            // spine case). An .xlsx that HAD merged cells is exactly as
            // ambiguous as pasted HTML that did, so it is keyed on the same
            // `hadMergedCells` signal rather than a blanket source-kind
            // exclusion.
            'structural' => [
                'headers' => $this->reader->lastStructuralHeaders(),
                'rows' => $this->reader->lastStructuralRows(),
            ],
            // §39: a table resubmitted from the structural step itself never
            // shows that step a second time — the teacher already reviewed
            // and corrected the structure once; re-showing it on the very
            // response that carries her corrections would be a loop, not a
            // review.
            'show_structural_step' => ! $this->reader->lastWasPreClassified()
                && (in_array($sourceKind, ['docx', 'pasted_image', 'image_upload'], true)
                    || (in_array($sourceKind, ['pasted_html', 'xlsx'], true) && $this->reader->lastHadMergedCells())),
            'sections' => array_map(
                fn (CharacterisationSection $section) => [
                    'key' => $section->value,
                    'label' => $section->label(),
                ],
                CharacterisationSection::cases(),
            ),
        ]);
    }

    /**
     * Reads every table out of the .docx. With exactly one, or once the
     * teacher has picked an index, returns that ExtractedTable so the caller
     * proceeds straight to the preview. With more than one and no explicit
     * choice yet, returns the chooser payload instead — a list to show the
     * teacher, never a silent guess (scope item 3).
     *
     * @return ExtractedTable|array<string, mixed>
     */
    private function resolveDocxTable(UploadedFile $file, ?int $tableIndex): ExtractedTable|array
    {
        $tables = $this->reader->tablesFromUploadedFile($file);

        if ($tables === []) {
            throw new UnreadableSpreadsheet(__('Não foi possível reconhecer nenhuma tabela neste documento Word.'));
        }

        if (count($tables) === 1) {
            return $tables[0];
        }

        if ($tableIndex === null) {
            return [
                'tables' => array_map(
                    fn (ExtractedTable $table, int $index): array => [
                        'index' => $index,
                        'row_count' => $table->rowCount(),
                        'column_count' => $table->columnCount(),
                        'label' => __('Tabela :number — :rows linhas × :columns colunas', [
                            'number' => $index + 1,
                            'rows' => $table->rowCount(),
                            'columns' => $table->columnCount(),
                        ]),
                    ],
                    $tables,
                    array_keys($tables),
                ),
            ];
        }

        if (! array_key_exists($tableIndex, $tables)) {
            throw ValidationException::withMessages([
                'table_index' => __('Escolha uma das tabelas encontradas no documento.'),
            ]);
        }

        return $tables[$tableIndex];
    }

    /**
     * F4: decodes and validates the JSON `corrections` field — raw token (as
     * it appeared in the source) => the token the teacher accepted in its
     * place. Trims every key here, once, so a token that arrived with
     * incidental whitespace (an OCR artefact, most often) still matches the
     * cell text it came from: BuildCharacterisationPreview::applyCorrections()
     * builds a `\b…\b`-bounded regex out of each key, and a leading/trailing
     * space inside that pattern can never sit next to a word boundary,
     * making the whole correction a silent no-op. An empty key or an empty
     * accepted value is refused outright rather than silently ignored (an
     * empty accepted value used to delete the token from the cell instead of
     * replacing it — see the same method's own comment).
     *
     * @return array<string, string>
     */
    private function parseCorrectionsPayload(string $json): array
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'corrections' => __('Não foi possível ler as correções aceites.'),
            ]);
        }

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'corrections' => __('Não foi possível ler as correções aceites.'),
            ]);
        }

        if (count($decoded) > 50) {
            throw ValidationException::withMessages([
                'corrections' => __('Demasiadas correções aceites de uma vez.'),
            ]);
        }

        $corrections = [];

        foreach ($decoded as $original => $accepted) {
            if (! is_string($original) || ! is_string($accepted)) {
                throw ValidationException::withMessages([
                    'corrections' => __('Não foi possível ler as correções aceites.'),
                ]);
            }

            $original = trim($original);
            $accepted = trim($accepted);

            if ($original === '' || $accepted === '') {
                throw ValidationException::withMessages([
                    'corrections' => __('Uma correção aceite não pode ficar em branco.'),
                ]);
            }

            if (mb_strlen($original) > 64 || mb_strlen($accepted) > 64) {
                throw ValidationException::withMessages([
                    'corrections' => __('Uma correção aceite é demasiado longa.'),
                ]);
            }

            $corrections[$original] = $accepted;
        }

        return $corrections;
    }

    /**
     * Turns the client-built OCR — or §39 structural-correction — payload
     * into an ExtractedTable, refusing anything that does not match the shape
     * ExtractedTable/ExtractedRow/ExtractedCell expect. A malformed or
     * oversized `extracted_table` is refused HERE, before
     * NormaliseExtractedTable ever sees it, because this JSON came from
     * client-side code the server never witnessed and is hostile input by
     * construction (see the MAX_EXTRACTED_TABLE_* constants) — true of an
     * OCR run exactly as it is true of a teacher's manual row/cell edits.
     */
    private function parseExtractedTablePayload(string $json): ExtractedTable
    {
        try {
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages([
                'extracted_table' => __('Não foi possível ler o resultado do reconhecimento da imagem.'),
            ]);
        }

        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            throw ValidationException::withMessages([
                'extracted_table' => __('Não foi possível ler o resultado do reconhecimento da imagem.'),
            ]);
        }

        $sourceType = ExtractedTableSource::tryFrom((string) ($decoded['source_type'] ?? ''));

        // The two OCR variants, PLUS the two §39 "corrected" variants — never
        // ::Docx or ::PastedHtml themselves, which stay reserved for a
        // genuine server-side read of the original file (see
        // ExtractedTableSource::CorrectedDocx's own docblock and
        // CharacterisationImportExtractedTableTest::an_invalid_source_type_is_refused).
        $allowed = [
            ExtractedTableSource::PastedImage,
            ExtractedTableSource::ImageUpload,
            ExtractedTableSource::CorrectedDocx,
            ExtractedTableSource::CorrectedPastedHtml,
        ];

        if (! in_array($sourceType, $allowed, true)) {
            throw ValidationException::withMessages([
                'extracted_table' => __('Origem da tabela reconhecida inválida.'),
            ]);
        }

        $rawRows = array_values($decoded['rows']);

        if (count($rawRows) > self::MAX_EXTRACTED_TABLE_ROWS) {
            throw ValidationException::withMessages([
                'extracted_table' => __('A imagem tem :count linhas — mais do que esta importação aceita de uma vez.', [
                    'count' => count($rawRows),
                ]),
            ]);
        }

        $rows = [];

        foreach ($rawRows as $rowIndex => $rawRow) {
            if (! is_array($rawRow) || ! is_array($rawRow['cells'] ?? null)) {
                throw ValidationException::withMessages([
                    'extracted_table' => __('Não foi possível ler o resultado do reconhecimento da imagem.'),
                ]);
            }

            $rawCells = array_values($rawRow['cells']);

            if (count($rawCells) > self::MAX_EXTRACTED_TABLE_COLUMNS) {
                throw ValidationException::withMessages([
                    'extracted_table' => __('A imagem tem colunas a mais para ser lida com segurança.'),
                ]);
            }

            $cells = [];

            foreach ($rawCells as $columnIndex => $rawCell) {
                if (! is_array($rawCell) || ! is_string($rawCell['text'] ?? null)) {
                    throw ValidationException::withMessages([
                        'extracted_table' => __('Não foi possível ler o resultado do reconhecimento da imagem.'),
                    ]);
                }

                $text = $rawCell['text'];

                if (mb_strlen($text) > self::MAX_EXTRACTED_CELL_TEXT_LENGTH) {
                    throw ValidationException::withMessages([
                        'extracted_table' => __('Uma célula reconhecida tem texto a mais para ser lida com segurança.'),
                    ]);
                }

                // F5: a cell's extraction confidence is a claim about how
                // uncertain a RECOGNITION step was — never meaningful for
                // ::CorrectedDocx/::CorrectedPastedHtml, whose text was read
                // EXACTLY out of the original file by this server's own
                // extractors (§18: "a cell read exactly has no reading
                // confidence to report"). Trusting whatever the client sent
                // for those two source types would let a hostile or merely
                // buggy client fabricate a LOW confidence for a token the
                // school's own document genuinely wrote, which is the one
                // thing suggestionsFor() is gated on: it would offer — and
                // let a teacher silently accept — an "ACN5 → ACNS"-style
                // rewrite of text nobody misread. Only the two genuine OCR
                // source types (`pasted_image`/`image_upload`) ever get to
                // keep a client-supplied confidence at all.
                $confidence = in_array($sourceType, [ExtractedTableSource::PastedImage, ExtractedTableSource::ImageUpload], true)
                    ? ($rawCell['confidence'] ?? null)
                    : null;

                $cells[] = new ExtractedCell(
                    text: $text,
                    // ExtractedCell's row/column are 1-INDEXED (see
                    // HtmlTableExtractor::extractRow, the convention every
                    // other extractor already follows) — the payload's own
                    // row/column are 0-indexed array positions, so +1 here,
                    // not a copy of them.
                    row: (int) $rowIndex + 1,
                    column: (int) $columnIndex + 1,
                    colspan: 1,
                    rowspan: 1,
                    // Extraction confidence — see ExtractedCell's own
                    // docblock: this is never CodeConfidence, and is clamped
                    // rather than trusted verbatim because it came from the
                    // browser, not from tesseract's own report to this server.
                    confidence: is_numeric($confidence) ? max(0.0, min(1.0, (float) $confidence)) : null,
                );
            }

            // §39: a corrected table carries the kind the teacher assigned in
            // the structural review step ('header'/'data'/'group'/'legend') —
            // an unrecognised or absent value falls back to Unknown, exactly
            // as every OCR row always has, so NormaliseExtractedTable keeps
            // classifying it itself rather than trusting a garbage string.
            $kind = ExtractedRowKind::tryFrom((string) ($rawRow['kind'] ?? '')) ?? ExtractedRowKind::Unknown;

            // ExtractedRow's own index is 1-indexed too (see
            // HtmlTableExtractor's $rowIndex, which starts at 1).
            $rows[] = new ExtractedRow(index: (int) $rowIndex + 1, cells: $cells, kind: $kind);
        }

        return new ExtractedTable(
            rows: $rows,
            sourceType: $sourceType,
            sourceFilename: is_string($decoded['source_filename'] ?? null) ? $decoded['source_filename'] : null,
        );
    }

    /**
     * Write what the teacher confirmed, and only that.
     */
    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            // paste/csv/xlsx are the vocabulary that shipped first; the rest
            // are ExtractedTableSource::value strings, so store() speaks the
            // same words preview() already returns as source_kind. Image
            // sources are reserved here — accepted, not yet produced — for
            // the slice that fills extractTableFromImage() in.
            'source_kind' => ['required', 'string', 'in:paste,csv,xlsx,pasted_html,pasted_tsv,docx,pasted_image,image_upload'],
            'original_filename' => ['nullable', 'string', 'max:255'],
            'decisions' => ['required', 'array', 'min:1', 'max:500'],
            'decisions.*.enrollment_ulid' => ['required', 'string', 'size:26'],
            'decisions.*.sections' => ['nullable', 'array'],
            'decisions.*.sections.*' => ['nullable', 'string', 'max:5000'],
            'decisions.*.measure_codes' => ['nullable', 'array', 'max:20'],
            'decisions.*.measure_codes.*' => ['string', 'max:64'],
            'decisions.*.raw_tokens' => ['nullable', 'array'],
            'decisions.*.raw_tokens.*' => ['nullable', 'string', 'max:255'],
            // Shaped to the depth it is actually read at. Left as a bare
            // `array`, this was the one field in the write path with no element
            // rule at all — and it lands in a JSON column, so an unbounded
            // nested structure would be stored verbatim.
            'decisions.*.annotations' => ['nullable', 'array', 'max:20'],
            'decisions.*.annotations.*' => ['array', 'max:12'],
            'decisions.*.annotations.*.*' => ['string', 'max:16'],
        ]);

        $result = $this->importer->apply(
            class: $class,
            decisions: $data['decisions'],
            sourceKind: $data['source_kind'],
            originalFilename: $data['original_filename'] ?? null,
            confirmedBy: $request->user(),
        );

        $this->audit->record(
            'characterisation.imported',
            $class,
            $request->user(),
            "Caracterização importada — {$class->label}.",
            [
                'class_id' => $class->id,
                'batch_ulid' => $result['batch']->ulid,
                'source_kind' => $data['source_kind'],
                // Counts, never contents. What was written about which child is
                // in the characterisation and its history; repeating it here
                // would be a second store of the same sentences.
                'written' => $result['written'],
                'skipped' => $result['skipped'],
            ],
        );

        return redirect()
            ->route('classes.characterisation.show', $class)
            ->with('status', __('Caracterização importada para :count alunos.', ['count' => $result['written']]));
    }
}
