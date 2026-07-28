<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Enrolls a new student into a class: creates the pseudonymous student record,
 * its separate encrypted identity, and the enrollment — in one transaction (§11.2).
 */
class StudentEnrollmentService
{
    public function __construct(protected CurrentOrganization $currentOrganization) {}

    /**
     * @param  array{name: string, class_number?: int|null, enrolled_on?: string|null, school_number?: string|null, birth_date?: string|null, photo_path?: string|null, import_note?: string|null}  $data
     */
    public function enrollNew(SchoolClass $class, array $data): Enrollment
    {
        return DB::transaction(function () use ($class, $data): Enrollment {
            $student = Student::create(['pseudonym_code' => $this->uniquePseudonym()]);

            $student->identity()->create([
                'organization_id' => $this->currentOrganization->id(),
                'display_name' => $data['name'],
                'school_number' => $data['school_number'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
                'photo_path' => $data['photo_path'] ?? null,
            ]);

            $enrolledOn = $data['enrolled_on'] ?? $class->academicYear->starts_on->toDateString();

            // Late entry is a UI marker; the engine uses enrolled_on. We flag it
            // when the entry date is after the class's academic year began.
            $isLate = Carbon::parse($enrolledOn)->greaterThan($class->academicYear->starts_on);

            return $class->enrollments()->create([
                'student_id' => $student->id,
                'class_number' => $data['class_number'] ?? null,
                'enrolled_on' => $enrolledOn,
                'status' => 'active',
                'is_late_entry' => $isLate,
                'import_note' => $data['import_note'] ?? null,
            ]);
        });
    }

    protected function uniquePseudonym(): string
    {
        do {
            $code = 'ALU-'.Str::upper(Str::random(4));
        } while (Student::withoutGlobalScope('organization')
            ->where('organization_id', $this->currentOrganization->id())
            ->where('pseudonym_code', $code)
            ->exists());

        return $code;
    }
}
