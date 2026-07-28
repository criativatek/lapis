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
use App\Support\Import\RosterImportTempStorage;
use App\Support\Privacy\BlindIndex;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class RosterImportController extends Controller
{
    public function __construct(
        protected RosterFileParser $rosterParser,
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected RosterImportTempStorage $tempStorage,
        protected StudentEnrollmentService $enrollmentService,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function store(Request $request, SchoolClass $class): \Inertia\Response|RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'roster' => ['required', 'file', 'mimes:xls,xlsx'],
            'photos' => ['nullable', 'file', 'mimes:doc,docx'],
        ]);

        try {
            $rosterRows = $this->rosterParser->parse($data['roster']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['roster' => $exception->getMessage()]);
        }

        if ($rosterRows === []) {
            return back()->withErrors(['roster' => 'Não foi possível encontrar nenhum aluno neste ficheiro.']);
        }

        $photoMatches = [];

        if (isset($data['photos'])) {
            try {
                $photoMatches = $this->photoParser->parse($data['photos']->getRealPath());
            } catch (RosterFileParseException $exception) {
                return back()->withErrors(['photos' => $exception->getMessage()]);
            }
        }

        $token = $this->tempStorage->newToken();

        foreach ($photoMatches as $index => $photo) {
            $this->tempStorage->storePhoto($token, $index, $photo->imageBytes, $photo->extension);
        }

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

        $rows = $this->previewBuilder->build($rosterRows, $photoMatches, $isAlreadyEnrolled);

        return Inertia::render('roster-imports/Preview', [
            'schoolClassUlid' => $class->ulid,
            'token' => $token,
            'rows' => $rows,
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
        Gate::authorize('update', $class);

        $data = $request->validate([
            'rows' => ['required', 'array'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'rows.*.birth_date' => ['nullable', 'date'],
            'rows.*.situation_code' => ['required', 'string'],
            'rows.*.note' => ['nullable', 'string', 'max:255'],
            'rows.*.process_number' => ['nullable', 'string', 'max:64'],
            'rows.*.photo_temp_path' => ['nullable', 'string'],
            'rows.*.include' => ['required', 'boolean'],
        ]);

        $created = 0;

        foreach ($data['rows'] as $row) {
            if (! $row['include']) {
                continue;
            }

            $photoPath = null;

            if (! empty($row['photo_temp_path'])) {
                $photoPath = $this->movePhotoToPermanentStorage($row['photo_temp_path']);
            }

            $this->enrollmentService->enrollNew($class, [
                'name' => $row['name'],
                'class_number' => $row['class_number'] ?? null,
                'birth_date' => $row['birth_date'] ?? null,
                'import_note' => $row['note'] ?? null,
                'school_number' => $row['process_number'] ?? null,
                'photo_path' => $photoPath,
                'status' => $this->mapSituation($row['situation_code']),
            ]);

            $created++;
        }

        $this->tempStorage->delete($token);

        return to_route('classes.show', $class->ulid)
            ->with('status', "{$created} aluno(s) inscrito(s).");
    }

    protected function movePhotoToPermanentStorage(string $tempRelativePath): ?string
    {
        $bytes = $this->tempStorage->readPhoto($tempRelativePath);

        if ($bytes === null) {
            return null;
        }

        $extension = pathinfo($tempRelativePath, PATHINFO_EXTENSION) ?: 'jpg';
        $permanentPath = 'student-photos/'.Str::uuid().'.'.$extension;
        Storage::disk('local')->put($permanentPath, $bytes);

        return $permanentPath;
    }

    protected function mapSituation(string $code): string
    {
        return match ($code) {
            'TR' => EnrollmentStatus::TransferredOut->value,
            default => EnrollmentStatus::Active->value,
        };
    }
}
