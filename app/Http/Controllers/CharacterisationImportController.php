<?php

namespace App\Http\Controllers;

use App\Actions\Characterisation\ApplyCharacterisationImport;
use App\Models\SchoolClass;
use App\Services\Audit\AuditLog;
use App\Services\Characterisation\Import\BuildCharacterisationPreview;
use App\Services\Characterisation\Import\ReadCharacterisationTable;
use App\Services\Import\Tabular\UnreadableSpreadsheet;
use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
    public function __construct(
        private readonly ReadCharacterisationTable $reader,
        private readonly BuildCharacterisationPreview $builder,
        private readonly ApplyCharacterisationImport $importer,
        private readonly AuditLog $audit,
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
            'file' => ['nullable', 'file', 'max:5120', 'mimes:csv,txt,xlsx'],
        ]);

        if (blank($data['pasted_text'] ?? null) && ! $request->hasFile('file')) {
            throw ValidationException::withMessages([
                'pasted_text' => __('Cole a tabela ou escolha um ficheiro.'),
            ]);
        }

        try {
            $grid = $request->hasFile('file')
                ? $this->reader->fromUploadedFile($request->file('file'))
                : $this->reader->fromPastedText((string) $data['pasted_text']);
        } catch (UnreadableSpreadsheet $exception) {
            // The reader's messages say what to DO about it, so they are shown
            // as they are rather than replaced by a generic failure.
            throw ValidationException::withMessages([
                $request->hasFile('file') ? 'file' : 'pasted_text' => $exception->getMessage(),
            ]);
        }

        $preview = $this->builder->build($class, $grid);

        return response()->json([
            'preview' => $preview->toArray(),
            'source_kind' => $this->sourceKind($request),
            'original_filename' => $request->file('file')?->getClientOriginalName(),
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
     * Write what the teacher confirmed, and only that.
     */
    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'source_kind' => ['required', 'string', 'in:paste,csv,xlsx'],
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

    private function sourceKind(Request $request): string
    {
        $file = $request->file('file');

        if ($file === null) {
            return 'paste';
        }

        return strtolower($file->getClientOriginalExtension()) === 'xlsx' ? 'xlsx' : 'csv';
    }
}
