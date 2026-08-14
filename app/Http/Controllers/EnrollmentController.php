<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\StudentEnrollmentService;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EnrollmentController extends Controller
{
    public function __construct(protected StudentEnrollmentService $service) {}

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        // Enrolling a student is editing the class's roster.
        Gate::authorize('update', $class);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'enrolled_on' => ['nullable', 'date'],
            'school_number' => ['nullable', 'string', 'max:64'],
        ]);

        $this->service->enrollNew($class, $data);

        return back();
    }

    /**
     * Corrects a student's basic data in place. Same authorization as enrolling
     * one — editing the roster — and the same field rules, so a name accepted on
     * creation stays acceptable on correction.
     */
    public function update(Request $request, SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        // The enrollment resolves within the tenant already (global scope), so
        // this is what stops a valid ulid from another of my own classes.
        abort_unless($enrollment->class_id === $class->id, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'class_number' => ['nullable', 'integer', 'min:1', 'max:65535'],
            // Mirrors enrollments_class_student_date_unique — the composite
            // (class_id, student_id, enrolled_on), not a bare unique on the
            // date: classmates share an entry date all the time. Only the same
            // student's OTHER enrollment in the SAME class clashes, which is the
            // re-entry case the date is in the key for.
            //
            // whereDate, not a plain where: enrolled_on is a date column but
            // Eloquent stores it as "Y-m-d 00:00:00", so comparing it against
            // the request's "Y-m-d" string never matches and the clash reaches
            // the database as a 500 instead of a validation message.
            'enrolled_on' => [
                'bail',
                'required',
                'date',
                function (string $attribute, mixed $value, Closure $fail) use ($class, $enrollment): void {
                    $clashes = Enrollment::where('class_id', $class->id)
                        ->where('student_id', $enrollment->student_id)
                        ->whereKeyNot($enrollment->getKey())
                        ->whereDate('enrolled_on', $value)
                        ->exists();

                    if ($clashes) {
                        $fail('Este aluno já tem uma inscrição nesta turma com esta data de entrada.');
                    }
                },
            ],
        ]);

        $this->service->updateExisting($enrollment, $data);

        return back();
    }

    public function destroy(SchoolClass $class, Enrollment $enrollment): RedirectResponse
    {
        Gate::authorize('update', $class);

        abort_unless($enrollment->class_id === $class->id, 404);

        // Removing a mistaken enrollment. Once results exist this needs to become
        // a status change (left/transferred), not a delete — the FK is RESTRICT.
        $enrollment->delete();

        return back();
    }
}
