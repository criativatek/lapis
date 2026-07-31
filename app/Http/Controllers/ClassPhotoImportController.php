<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Import\PhotoFileParser;
use App\Services\Import\RosterFileParseException;
use App\Services\Import\RosterImportPreviewBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class ClassPhotoImportController extends Controller
{
    public function __construct(
        protected PhotoFileParser $photoParser,
        protected RosterImportPreviewBuilder $previewBuilder,
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

        $enrollments = $class->enrollments()->with('student.identity')->get();
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

            $permanentPath = 'student-photos/'.Str::uuid().'.'.$photoExtension;
            Storage::disk('local')->put($permanentPath, $photoMatches[$photoIndex]->imageBytes);
            $enrollment->student->identity->update(['photo_path' => $permanentPath]);
            $matchedCount++;
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$matchedCount} foto(s) associada(s)."]);

        return to_route('classes.show', $class->ulid);
    }
}
