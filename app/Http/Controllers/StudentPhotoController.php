<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\StudentPhotoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentPhotoController extends Controller
{
    public function __construct(protected StudentPhotoService $photoService) {}

    public function show(Student $student): StreamedResponse
    {
        Gate::authorize('viewPhoto', $student);

        $path = optional($student->identity)->photo_path;

        abort_if($path === null || ! Storage::disk(StudentPhotoService::DISK)->exists($path), 404);

        return Storage::disk(StudentPhotoService::DISK)->response($path);
    }

    /**
     * Sets or replaces one student's photo, from the class roster.
     *
     * Deliberately its own request rather than part of the student edit: a file
     * upload and a data update fail in different ways, and keeping them apart
     * means a rejected image never discards a corrected name (§12).
     */
    public function update(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        // Same rule as editing the student: managing the roster of my class.
        Gate::authorize('update', $class);

        abort_unless($enrollment->class_id === $class->id, 404);

        $request->validate([
            // `image` and `mimes` both read the file's real type, so a .exe
            // renamed to .jpg is rejected on content, not on its extension.
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'photo.image' => 'O ficheiro tem de ser uma imagem.',
            'photo.mimes' => 'Formatos aceites: JPG, PNG ou WEBP.',
            'photo.max' => 'A imagem não pode exceder 5 MB.',
        ]);

        $identity = $enrollment->student->identity;

        // photo_path lives on the identity, and display_name is NOT NULL — so
        // there is no row to hold a photo until the student has a name. Rather
        // than invent a placeholder name, say what is missing.
        if ($identity === null) {
            throw ValidationException::withMessages([
                'photo' => 'Este aluno ainda não tem nome. Guarda primeiro o nome para poderes associar uma fotografia.',
            ]);
        }

        $this->photoService->storeUploaded($identity, $request->file('photo'));

        return back();
    }

    /**
     * Removes the photo and only the photo.
     */
    public function destroy(SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        abort_unless($enrollment->class_id === $class->id, 404);

        $identity = $enrollment->student->identity;

        if ($identity !== null) {
            $this->photoService->remove($identity);
        }

        return back();
    }
}
