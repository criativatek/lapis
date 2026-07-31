<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's enrollment in a class — the axis of all results. A result never
 * links to a student directly; it belongs to the (student, class) pair (§11.4).
 * enrolled_on is the entry date the calculation engine depends on for late entry.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int $student_id
 * @property int|null $class_number
 * @property Carbon $enrolled_on
 * @property Carbon|null $left_on
 * @property EnrollmentStatus $status
 * @property bool $is_late_entry
 * @property string|null $late_entry_note
 * @property string|null $import_note
 * @property bool|null $include_evidence_in_report
 */
#[Fillable(['class_id', 'student_id', 'class_number', 'enrolled_on', 'left_on', 'status', 'is_late_entry', 'late_entry_note', 'import_note', 'include_evidence_in_report'])]
class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use BelongsToOrganization, HasFactory, HasUlids;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'left_on' => 'date',
            'status' => EnrollmentStatus::class,
            'is_late_entry' => 'boolean',
            'include_evidence_in_report' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Whether this student's Evidence records show up in their class's
     * report. NULL means "no per-student override" — falls back to the
     * class's own default (SchoolClass::$include_evidence_in_report), never
     * a hidden false.
     */
    public function includesEvidenceInReport(): bool
    {
        return $this->include_evidence_in_report ?? $this->schoolClass->include_evidence_in_report;
    }
}
