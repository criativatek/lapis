<?php

namespace App\Http\Controllers;

use App\Domain\Import\Correction\CorrectionGridSource;
use App\Domain\Import\Correction\ImportMapping;
use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\SchoolClass;
use App\Models\User;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\Import\Correction\BuildImportPreview;
use App\Services\Import\Correction\CorrectionGridParserRegistry;
use App\Services\Import\Correction\ImportCorrectionGrid;
use App\Support\Import\CorrectionImportException;
use App\Support\Import\CorrectionImportTempStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The import wizard's four steps, as thin as they can be.
 *
 * Nothing here parses, matches or writes: the registry reads the file, the
 * preview builder describes it, ImportCorrectionGrid persists it. What is left
 * is validation, authorisation and choosing what to render — which is all a
 * controller should ever be doing in this codebase.
 *
 * The whole group sits behind `module:correction_grid_import`, so a Base
 * organization cannot reach any of it, including by typing a URL. Hiding the
 * button is presentation; this is the access control (§7).
 */
class CorrectionImportController extends Controller
{
    public function __construct(
        protected CorrectionGridParserRegistry $registry,
        protected CorrectionImportTempStorage $storage,
        protected BuildImportPreview $preview,
        protected ImportCorrectionGrid $importer,
    ) {}

    /**
     * Step 1: which class, which source, which file.
     */
    public function create(): Response
    {
        Gate::authorize('create', CorrectionImport::class);

        return Inertia::render('imports/correction/Create', [
            'classes' => $this->classes(),
            // Built from the parsers actually registered, so the list can never
            // offer a source that does not work (§11 of the foundation brief).
            'sources' => $this->registry->descriptors(),
            'accepted_extensions' => $this->registry->acceptedExtensions(),
        ]);
    }

    /**
     * Reads the file into a canonical grid and stores the session. Deliberately
     * writes nothing academic: at this point the teacher has only asked "what is
     * in this file?".
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', CorrectionImport::class);

        $extensions = $this->registry->acceptedExtensions();

        $data = $request->validate([
            'class_id' => ['required', 'integer', new BelongsToCurrentOrganization(SchoolClass::class)],
            'source' => ['required', 'string', 'in:'.implode(',', array_map(
                fn (CorrectionGridSource $source): string => $source->value,
                $this->registry->supportedSources(),
            ))],
            // Extension AND mime type, from the parsers themselves.
            //
            // `mimes:csv` alone would reject real Plickers exports: a CSV of
            // plain ASCII is detected as text/plain, not text/csv, and the rule
            // compares against the canonical type. So the extension is checked
            // as the claim and the mime type as the evidence, with the parser's
            // own list of what a CSV is allowed to look like in the wild (§24).
            // 8 MB is far past any real class's export.
            'file' => [
                'required',
                'file',
                'max:8192',
                'extensions:'.implode(',', $extensions),
                'mimetypes:'.implode(',', $this->registry->acceptedMimeTypes()),
            ],
        ]);

        // whereKey()->firstOrFail() rather than findOrFail(): find() may return a
        // collection when handed an array, and this has to be one class.
        $class = SchoolClass::query()->whereKey($data['class_id'])->firstOrFail();
        Gate::authorize('update', $class);

        $source = CorrectionGridSource::from($data['source']);
        $parser = $this->registry->for($source);

        if ($parser === null) {
            return back()->withErrors(['source' => __('Esta origem ainda não é suportada.')]);
        }

        $upload = $request->file('file');
        $originalName = (string) $upload->getClientOriginalName();

        if (! $parser->supports($upload->getRealPath(), $originalName)) {
            return back()->withErrors(['file' => __('Este ficheiro não parece ser uma exportação de :origem.', ['origem' => $source->label()])]);
        }

        $hash = $this->storage->hash($upload->getRealPath());
        $grid = $parser->parse($upload->getRealPath(), $originalName);
        $storedPath = $this->storage->store($upload->getRealPath(), $originalName);

        $import = CorrectionImport::create([
            'class_id' => $class->getKey(),
            'source' => $source->value,
            'status' => CorrectionImportStatus::Parsed->value,
            'original_filename' => $originalName,
            'stored_path' => $storedPath,
            'file_sha256' => $hash,
            'file_size' => $upload->getSize(),
            'uploaded_by' => $this->user()->getKey(),
            'canonical_snapshot' => $grid->toArray(),
            'source_metadata' => $grid->sourceMetadata,
            'mapping_snapshot' => (new ImportMapping)->toArray(),
        ]);

        return to_route('correction-imports.edit', $import);
    }

    /**
     * Steps 2 to 4, all reading from the same preview so the teacher never sees
     * one screen say ready and another say otherwise.
     */
    public function edit(CorrectionImport $import): Response
    {
        Gate::authorize('view', $import);

        // camelCase keys, like every other page: Vue matches prop names exactly,
        // and `import` in particular cannot be one — it is a reserved word in a
        // template expression and the build fails on it.
        return Inertia::render('imports/correction/Wizard', [
            'correctionImport' => [
                'ulid' => $import->ulid,
                'status' => $import->status->value,
                'statusLabel' => $import->status->label(),
                'originalFilename' => $import->original_filename,
                'class' => ['id' => $import->class_id, 'label' => $import->schoolClass->label],
            ],
            'preview' => $this->preview->for($import),
            'conflicts' => $this->preview->conflicts($import),
            'duplicateOfEarlierImport' => $this->seenBefore($import),
            'catalogue' => $this->catalogue(),
        ]);
    }

    /**
     * Every decision the teacher makes in the wizard lands here. Stored as a
     * mapping snapshot and re-described by the preview, which is what decides
     * whether the import may be confirmed.
     */
    public function update(Request $request, CorrectionImport $import): RedirectResponse
    {
        Gate::authorize('update', $import);

        $data = $request->validate([
            'mode' => ['required', 'string', 'in:'.ImportMapping::MODE_CREATE.','.ImportMapping::MODE_ASSOCIATE],
            'instrument_id' => ['nullable', 'integer'],
            'students' => ['array'],
            'items' => ['array'],
            'points' => ['array'],
            'domains' => ['array'],
            'conflicts' => ['array'],
            'instrument' => ['array'],
        ]);

        $mapping = new ImportMapping(
            mode: $data['mode'],
            instrumentId: $data['instrument_id'] ?? null,
            students: $this->sanitiseStudents($data['students'] ?? [], $import),
            items: $this->sanitiseItems($data['items'] ?? [], $import),
            points: array_map(fn ($value): string => (string) $value, $data['points'] ?? []),
            domains: $this->sanitiseDomains($data['domains'] ?? []),
            conflicts: array_map(fn ($value): string => (string) $value, $data['conflicts'] ?? []),
            instrumentAttributes: $data['instrument'] ?? [],
        );

        $import->forceFill([
            'mapping_snapshot' => $mapping->toArray(),
            'instrument_id' => $mapping->createsInstrument() ? null : $mapping->instrumentId,
        ])->save();

        // Readiness is the preview's answer, never the interface's — the button
        // and the door have to agree.
        $preview = $this->preview->for($import->fresh());

        $import->forceFill([
            'status' => $preview['can_confirm'] ? CorrectionImportStatus::Ready : CorrectionImportStatus::NeedsMapping,
        ])->save();

        return back();
    }

    public function confirm(CorrectionImport $import): RedirectResponse
    {
        Gate::authorize('confirm', $import);

        try {
            $instrument = $this->importer->confirm($import, $this->user());
        } catch (CorrectionImportException $exception) {
            return back()->withErrors(['confirm' => $exception->getMessage()]);
        }

        // Straight to the normal correction grid: the point of the whole feature
        // is that the marks land where the teacher already works, and the
        // message says plainly that reviewing comes before concluding (§28).
        return to_route('instruments.show', $instrument)
            ->with('success', __('Importação concluída. Reveja a correção antes de a concluir.'));
    }

    public function destroy(CorrectionImport $import): RedirectResponse
    {
        Gate::authorize('delete', $import);

        $this->storage->delete($import->stored_path);

        $import->forceFill([
            'status' => CorrectionImportStatus::Cancelled,
            'stored_path' => null,
        ])->save();

        return to_route('assessments.index')->with('success', __('Importação cancelada.'));
    }

    /**
     * An enrolment id the teacher sends has to belong to THIS import's class.
     * Anything else is dropped rather than trusted — a request is not a source
     * of truth about who is in a class.
     *
     * @param  array<string, mixed>  $students
     * @return array<string, int|null>
     */
    protected function sanitiseStudents(array $students, CorrectionImport $import): array
    {
        $allowed = $import->schoolClass->enrollments()->pluck('id')->flip();

        $clean = [];

        foreach ($students as $sourceKey => $enrollmentId) {
            if ($enrollmentId === null || $enrollmentId === '') {
                // An explicit decision to leave the row out. Kept, because it is
                // exactly what distinguishes "ignored" from "not yet decided".
                $clean[(string) $sourceKey] = null;

                continue;
            }

            if ($allowed->has((int) $enrollmentId)) {
                $clean[(string) $sourceKey] = (int) $enrollmentId;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $items
     * @return array<string, int>
     */
    protected function sanitiseItems(array $items, CorrectionImport $import): array
    {
        $allowed = $import->schoolClass->instruments()
            ->with('items:id,instrument_id')
            ->get()
            ->flatMap(fn ($instrument) => $instrument->items->pluck('id'))
            ->flip();

        $clean = [];

        foreach ($items as $sourceKey => $itemId) {
            if ($itemId !== null && $itemId !== '' && $allowed->has((int) $itemId)) {
                $clean[(string) $sourceKey] = (int) $itemId;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $domains
     * @return array<string, list<array{domain_id: int, allocation_percent: string}>>
     */
    protected function sanitiseDomains(array $domains): array
    {
        $allowed = Domain::query()->pluck('id')->flip();

        $clean = [];

        foreach ($domains as $sourceKey => $allocations) {
            if (! is_array($allocations)) {
                continue;
            }

            $rows = [];

            foreach ($allocations as $allocation) {
                $domainId = (int) ($allocation['domain_id'] ?? 0);

                if ($allowed->has($domainId)) {
                    $rows[] = [
                        'domain_id' => $domainId,
                        'allocation_percent' => (string) ($allocation['allocation_percent'] ?? '100'),
                    ];
                }
            }

            if ($rows !== []) {
                $clean[(string) $sourceKey] = $rows;
            }
        }

        return $clean;
    }

    /**
     * Whether this exact file has been through here before. A warning, never a
     * refusal: re-importing a corrected export is a legitimate thing to do (§30).
     */
    protected function seenBefore(CorrectionImport $import): bool
    {
        if ($import->file_sha256 === null) {
            return false;
        }

        return CorrectionImport::query()
            ->where('file_sha256', $import->file_sha256)
            ->whereKeyNot($import->getKey())
            ->where('status', CorrectionImportStatus::Imported)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function catalogue(): array
    {
        return [
            // The same catalogues the manual instrument form uses. Duplicating
            // them here would be a second place for them to drift.
            'instrument_types' => InstrumentType::query()->orderBy('name')->get(['id', 'code', 'name']),
            'domains' => Domain::query()->orderBy('name')->get(['id', 'name']),
        ];
    }

    /**
     * The teacher's own classes, with the periods a new instrument could belong
     * to — the same "only my classes" rule the rest of the app keeps (§23).
     *
     * @return list<array<string, mixed>>
     */
    protected function classes(): array
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear.periods'])
            ->orderByDesc('created_at')
            ->get();

        $rows = [];

        foreach ($classes as $class) {
            $periods = [];

            foreach ($class->academicYear->periods as $period) {
                $periods[] = ['id' => (int) $period->id, 'label' => $period->label];
            }

            $rows[] = [
                'id' => (int) $class->getKey(),
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'periods' => $periods,
            ];
        }

        return $rows;
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
