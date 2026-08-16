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
     * @param  array{name: string, class_number?: int|null, enrolled_on?: string|null, school_number?: string|null, birth_date?: string|null, photo_path?: string|null, import_note?: string|null, status?: string}  $data
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
                'status' => $data['status'] ?? 'active',
                'is_late_entry' => $isLate,
                'import_note' => $data['import_note'] ?? null,
            ]);
        });
    }

    /**
     * Corrects the basic data of a student already enrolled: the name (which
     * lives in the encrypted identity) and the number and entry date (which
     * live in the enrollment).
     *
     * Nothing is recreated. The Student row — and with it pseudonym_code, the
     * stable technical identity every result, instrument, record and
     * intervention hangs off — is never touched. A student who was imported
     * without an identity gets one created here, which is what turns
     * "(sem identidade)" into a real name (§11.2).
     *
     * @param  array{name: string, class_number?: int|null, enrolled_on?: string|null}  $data
     */
    public function updateExisting(Enrollment $enrollment, array $data): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $data): Enrollment {
            $student = $enrollment->student;

            // Only display_name is editable here: school_number, birth_date and
            // photo_path belong to other flows and must survive this edit.
            if ($student->identity === null) {
                $student->identity()->create([
                    'organization_id' => $this->currentOrganization->id(),
                    'display_name' => $data['name'],
                ]);
                $student->unsetRelation('identity');
            } else {
                // The blind index follows display_name on save (StudentIdentity::booted).
                $student->identity->update(['display_name' => $data['name']]);
            }

            $enrolledOn = $data['enrolled_on'] ?? $enrollment->enrolled_on->toDateString();

            $enrollment->update([
                'class_number' => $data['class_number'] ?? null,
                'enrolled_on' => $enrolledOn,
                // Recomputed, never carried over: correcting the entry date has
                // to correct the late-entry marker with it.
                'is_late_entry' => Carbon::parse($enrolledOn)
                    ->greaterThan($enrollment->schoolClass->academicYear->starts_on),
            ]);

            return $enrollment;
        });
    }

    /**
     * Fills in what the roster knows and this student's record does not.
     *
     * FOR A RE-IMPORT of the same class: the student is already on the roll, so
     * nothing is created — the Student row, its pseudonym_code and every result
     * hanging off it stay exactly where they are. Only the fields the file
     * actually carries are written.
     *
     * IT NEVER ERASES. A cell the export left empty says nothing about the
     * student, and least of all that what a teacher typed in by hand should go:
     * an absent value leaves the stored one alone (§8). A present value wins,
     * because the school's own export is the better source for a school's own
     * data — and the match is by name within this class, which is unambiguous
     * or the row would have been marked a duplicate.
     *
     * @param  array{name?: string, class_number?: int|null, birth_date?: string|null, school_number?: string|null, import_note?: string|null}  $data
     */
    public function fillFromRoster(Enrollment $enrollment, array $data): Enrollment
    {
        return DB::transaction(function () use ($enrollment, $data): Enrollment {
            $student = $enrollment->student;

            $identityFields = array_filter([
                'display_name' => $data['name'] ?? null,
                'school_number' => $data['school_number'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');

            if ($student->identity === null) {
                $student->identity()->create([
                    'organization_id' => $this->currentOrganization->id(),
                    'display_name' => $data['name'] ?? '',
                    ...$identityFields,
                ]);
                $student->unsetRelation('identity');
            } elseif ($identityFields !== []) {
                $student->identity->update($identityFields);
            }

            $enrollmentFields = array_filter([
                'class_number' => $data['class_number'] ?? null,
                'import_note' => $data['import_note'] ?? null,
            ], fn ($value): bool => $value !== null && $value !== '');

            if ($enrollmentFields !== []) {
                $enrollment->update($enrollmentFields);
            }

            return $enrollment;
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
