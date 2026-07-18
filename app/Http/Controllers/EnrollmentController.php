<?php

namespace App\Http\Controllers;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\StudentEnrollmentService;
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
