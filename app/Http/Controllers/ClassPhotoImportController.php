<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterImportPreviewBuilder;
use App\Services\StudentPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class ClassPhotoImportController extends Controller
{
    public function __construct(
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
        protected StudentPhotoService $photoService,
    ) {}

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'photos' => ['required', 'file', 'mimes:doc,docx'],
        ]);

        try {
            $photoMatches = $this->photoParser->parse($data['photos']->getRealPath());
        } catch (RosterFileParseException $exception) {
            return back()->withErrors(['photos' => $exception->getMessage()]);
        }

        // The class as it stands: a photo sheet is about who is here now (§4).
        $enrollments = $class->activeEnrollments()->with('student.identity')->get();
        $rows = array_values($enrollments
            ->map(fn (Enrollment $enrollment) => ['name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)'])
            ->all());
        $matchedRows = $this->previewBuilder->matchPhotosToRows($rows, $photoMatches);
        $matchedCount = 0;

        foreach ($enrollments as $index => $enrollment) {
            $photoIndex = $matchedRows[$index]['photo_index'] ?? null;
            $photoExtension = $matchedRows[$index]['photo_extension'] ?? null;

            if ($photoIndex === null || $photoExtension === null || $enrollment->student->identity === null) {
                continue;
            }

            // Same writer as the manual, one-student path: one naming scheme,
            // one disk, and the previous file cleaned up either way.
            $this->photoService->storeBytes(
                $enrollment->student->identity,
                $photoMatches[$photoIndex]->imageBytes,
                $photoExtension,
            );
            $matchedCount++;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$matchedCount} foto(s) associada(s)."]);

        return to_route('classes.show', $class->ulid);
    }
}
