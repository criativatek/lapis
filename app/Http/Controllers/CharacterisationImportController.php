<?php

namespace App\Http\Controllers;

use App\Actions\Characterisation\ApplyCharacterisationImport;
use App\Models\SchoolClass;
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\Import\BuildCharacterisationPreview;
use App\Services\Characterisation\Import\Extraction\DocxTableExtractor;
use App\Services\Characterisation\Import\Extraction\ExtractedTable;
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
        ]);

        $pastedHtml = $data['pasted_html'] ?? null;
        $pastedText = $data['pasted_text'] ?? null;
        $hasFile = $request->hasFile('file');

        if (blank($pastedText) && blank($pastedHtml) && ! $hasFile) {
            throw ValidationException::withMessages([
                'pasted_text' => __('Cole a tabela ou escolha um ficheiro.'),
            ]);
        }

        $errorField = $hasFile ? 'file' : ($pastedHtml !== null && $pastedHtml !== '' ? 'pasted_html' : 'pasted_text');

        try {
            if ($hasFile) {
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

        $preview = $this->builder->build($class, $grid);

        return response()->json([
            'preview' => $preview->toArray(),
            'source_kind' => $sourceKind,
            'original_filename' => $request->file('file')?->getClientOriginalName(),
            // Populated only on the pasted-HTML/.docx paths, which go through
            // NormaliseExtractedTable — the csv/xlsx/plain-paste readers
            // never drop a row, so they have nothing to warn about.
            'warnings' => $this->reader->lastWarnings(),
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
