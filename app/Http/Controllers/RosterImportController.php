<?php

// app/Http/Controllers/RosterImportController.php

namespace App\Http\Controllers;

use App\Models\EnrollmentStatus;
use App\Models\SchoolClass;
use App\Models\StudentIdentity;
use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterFileParser;
use App\Services\Import\RosterImportPreviewBuilder;
use App\Services\StudentEnrollmentService;
use App\Services\StudentPhotoService;
use App\Support\Import\RosterImportTempStorage;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Throwable;

class RosterImportController extends Controller
{
    public function __construct(
        protected RosterFileParser $rosterParser,
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected RosterImportTempStorage $tempStorage,
        protected StudentEnrollmentService $enrollmentService,
        protected StudentPhotoService $photoService,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function store(Request $request, SchoolClass $class): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'roster' => ['required', 'file', 'mimes:xls,xlsx'],
        ]);

        try {
            $rosterRows = $this->rosterParser->parse($data['roster']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['roster' => $exception->getMessage()]);
        }

        if ($rosterRows === []) {
            return back()->withErrors(['roster' => 'Não foi possível encontrar nenhum aluno neste ficheiro.']);
        }

        // Photos are no longer part of this step — they are a separate,
        // later phase (attachPhotos() below), reusing the token created
        // here. Every row therefore starts with photo_index/photo_extension
        // null.
        $token = $this->tempStorage->newToken();

        $isAlreadyEnrolled = function (string $name) use ($class): bool {
            $index = BlindIndex::of($name);

            // StudentIdentity has no BelongsToOrganization scope (by design —
            // see its own doc comment), so this query is otherwise unscoped
            // across organizations. It stays tenant-safe because the
            // whereHas narrows it to enrollments in this exact, already
            // tenant-verified SchoolClass row (class_id is a globally unique
            // primary key, never reused across organizations) — never rely
            // on that alone; the explicit organization_id filter below is
            // deliberate defense-in-depth, not redundant belt-and-braces.
            return StudentIdentity::where('display_name_index', $index)
                ->where('organization_id', $this->currentOrganization->id())
                ->whereHas('student.enrollments', fn ($query) => $query->where('class_id', $class->id))
                ->exists();
        };

        // No photos at this step (see the comment above $token) — the
        // preview page always starts with an empty photo pool; attachPhotos()
        // below is the only place that ever populates it.
        $rows = $this->previewBuilder->build($rosterRows, [], $isAlreadyEnrolled);

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
            'photos' => [],
        ]);
    }

    /**
     * Second, separate phase of the import: attaches a Word photo file to an
     * ALREADY-created preview, in place — reusing the roster upload's own
     * $token so both phases' temp files land in the same
     * roster-imports/{token}/ folder (the scheduled prune command and
     * confirm()'s own cleanup keep working unchanged).
     *
     * The row data validated/used here is whatever the CLIENT currently
     * holds — i.e. the teacher's own edits already made on the preview page
     * — never a fresh re-parse of the roster file. Matching therefore runs
     * against the CURRENT names, not the original ones.
     */
    public function attachPhotos(Request $request, SchoolClass $class, string $token): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        // Same per-row rules confirm() already uses — see the extensive
        // comments there on why photo_index/photo_extension are never a
        // client-supplied path.
        $validated = $request->validate([
            'photos' => ['required', 'file', 'mimes:doc,docx'],
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.birth_date' => ['nullable', 'date'],
            'rows.*.situation_code' => ['required', 'string'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.process_number' => ['nullable', 'string', 'max:64'],
            'rows.*.photo_index' => ['nullable', 'integer', 'min:0', 'required_with:rows.*.photo_extension'],
            'rows.*.photo_extension' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9]+$/', 'max:10', 'required_with:rows.*.photo_index'],
            'rows.*.include' => ['required', 'boolean'],
        ]);

        try {
            $photoMatches = $this->photoParser->parse($validated['photos']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['photos' => $exception->getMessage()]);
        }

        foreach ($photoMatches as $index => $photo) {
            $this->tempStorage->storePhoto($token, $index, $photo->imageBytes, $photo->extension);
        }

        // $request->validate() only returns the fields it was told to
        // validate, dropping every other key — but the preview page's row
        // shape also carries display-only fields (situation_recognized,
        // duplicate_in_file, already_enrolled) that were never part of
        // those rules and must still round-trip unchanged. So the raw,
        // as-submitted row is merged with its validated/cast counterpart:
        // validated fields win (sanitized types), everything else survives.
        $rawRows = $request->input('rows', []);
        $rows = [];

        foreach ($rawRows as $index => $rawRow) {
            $rows[] = array_merge($rawRow, $validated['rows'][$index]);
        }

        $rows = $this->previewBuilder->matchPhotosToRows($rows, $photoMatches);

        // Same shape store() already produces — every parsed photo, not
        // just the ones that auto-matched a row by name (see store()'s own
        // comment on $photos below).
        $photos = [];

        foreach ($photoMatches as $index => $photo) {
            $photos[] = ['index' => $index, 'extension' => $photo->extension];
        }

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
            'photos' => $photos,
        ]);
    }

    public function previewPhoto(SchoolClass $class, string $token, int $index): Response
    {
        Gate::authorize('update', $class);

        $extension = $this->guessExtension($token, $index);

        abort_if($extension === null, 404);

        $bytes = $this->tempStorage->readPhoto($this->tempStorage->path($token)."/{$index}.{$extension}");

        abort_if($bytes === null, 404);

        return response($bytes, 200, ['Content-Type' => "image/{$extension}"]);
    }

    protected function guessExtension(string $token, int $index): ?string
    {
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            if (Storage::disk('local')->exists($this->tempStorage->path($token)."/{$index}.{$extension}")) {
                return $extension;
            }
        }

        return null;
    }

    public function confirm(Request $request, SchoolClass $class, string $token): RedirectResponse
    {
        // Authorization and validation run BEFORE the try/finally below, on
        // purpose: they never touch the temp folder, so a rejected request
        // (wrong permissions, or a row that fails validation — e.g. a name
        // over 255 chars) leaves the teacher's uploaded photos untouched and
        // resubmittable. Only the row-processing loop actually needs the temp
        // photos and must guarantee their cleanup regardless of outcome, so
        // only IT sits inside try/finally.
        Gate::authorize('update', $class);

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.birth_date' => ['nullable', 'date'],
            'rows.*.situation_code' => ['required', 'string'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.process_number' => ['nullable', 'string', 'max:64'],
            // Deliberately NOT a path: the client only ever names a photo by
            // its position in the original photo pool (photo_index) and its
            // extension (photo_extension). The server is the only party that
            // ever builds an actual filesystem path, and it does so using
            // $token from the route — never anything the client sends — so
            // there is no client-controlled string that could ever resolve
            // outside this confirm request's own temp folder. The regex on
            // photo_extension is an allowlist (alphanumeric only): it makes a
            // '/' or '..' in that value structurally impossible, not merely
            // unlikely. required_with in both directions means a row must
            // supply both fields together or neither — never just one.
            'rows.*.photo_index' => ['nullable', 'integer', 'min:0', 'required_with:rows.*.photo_extension'],
            'rows.*.photo_extension' => ['nullable', 'string', 'regex:/^[a-zA-Z0-9]+$/', 'max:10', 'required_with:rows.*.photo_index'],
            'rows.*.include' => ['required', 'boolean'],
        ]);

        // The temp token folder is ALWAYS cleaned up on the way out of this
        // block — whether a row throws partway through the loop, or it
        // completes normally. Rows already enrolled before such a throw are
        // deliberately NOT rolled back (no outer DB::transaction() here):
        // partial success is the accepted behavior, matching the "N
        // inscritos, M ignorados" partial-completion design elsewhere in this
        // flow. Only the temp-folder cleanup is unconditional; the exception
        // itself still propagates so the teacher sees the failure.
        try {
            $created = 0;

            foreach ($data['rows'] as $row) {
                if (! $row['include']) {
                    continue;
                }

                $photoPath = null;

                // The temp path is always rebuilt HERE, from $token (the
                // route's own value, never client input) plus the row's
                // validated photo_index/photo_extension — never taken as a
                // string from the request. If no file actually exists at
                // that reconstructed path (e.g. a stale or out-of-range
                // photo_index), movePhotoToPermanentStorage() below simply
                // returns null, exactly as if no photo had been supplied.
                $photoIndex = $row['photo_index'] ?? null;
                $photoExtension = $row['photo_extension'] ?? null;

                if ($photoIndex !== null && $photoExtension !== null) {
                    $photoTempPath = $this->tempStorage->path($token)."/{$photoIndex}.{$photoExtension}";
                    $photoPath = $this->movePhotoToPermanentStorage($photoTempPath);
                }

                try {
                    $this->enrollmentService->enrollNew($class, [
                        'name' => $row['name'],
                        'class_number' => $row['class_number'] ?? null,
                        'birth_date' => $row['birth_date'] ?? null,
                        'import_note' => $row['note'] ?? null,
                        'school_number' => $row['process_number'] ?? null,
                        'photo_path' => $photoPath,
                        'status' => $this->mapSituation($row['situation_code']),
                    ]);
                } catch (Throwable $exception) {
                    // enrollNew() rolls its own transaction back, so nothing in
                    // the database points at the photo we just wrote for this
                    // row. Compensate for it here — the temp folder cleanup
                    // below only covers the staging area, not permanent storage.
                    if ($photoPath !== null) {
                        Storage::disk(StudentPhotoService::DISK)->delete($photoPath);
                    }

                    throw $exception;
                }

                $created++;
            }

            Inertia::flash('toast', ['type' => 'success', 'message' => "{$created} aluno(s) inscrito(s)."]);

            return to_route('classes.show', $class->ulid);
        } finally {
            $this->tempStorage->delete($token);
        }
    }

    protected function movePhotoToPermanentStorage(string $tempRelativePath): ?string
    {
        $bytes = $this->tempStorage->readPhoto($tempRelativePath);

        if ($bytes === null) {
            return null;
        }

        // One writer decides the disk and the naming, here and in the manual
        // single-student path alike.
        return $this->photoService->putBytes(
            $bytes,
            pathinfo($tempRelativePath, PATHINFO_EXTENSION) ?: 'jpg',
        );
    }

    protected function mapSituation(string $code): string
    {
        return match ($code) {
            'TR' => EnrollmentStatus::TransferredOut->value,
            default => EnrollmentStatus::Active->value,
        };
    }
}
