<?php

namespace App\Models;

use App\Support\Privacy\BlindIndex;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A student's identifying data — separate table, own Policy, encrypted at rest
 * (ADR-0004). display_name is encrypted with Laravel's `encrypted` cast (AES via
 * APP_KEY, not bespoke crypto). display_name_index is a blind index for exact
 * search without decrypting.
 *
 * organization_id is duplicated here by design, so the tenant Policy works
 * without a join to students.
 *
 * @property int $id
 * @property int $student_id
 * @property int $organization_id
 * @property string $display_name
 * @property string|null $display_name_index
 * @property string|null $school_number
 * @property Carbon|null $birth_date
 * @property string|null $photo_path
 */
#[Fillable(['student_id', 'organization_id', 'display_name', 'school_number', 'birth_date', 'photo_path'])]
class StudentIdentity extends Model
{
    public static function booted(): void
    {
        // Keep the blind index in step with the name automatically, so no caller
        // can forget it and leave the two out of sync.
        static::saving(function (self $identity): void {
            if ($identity->isDirty('display_name')) {
                $identity->display_name_index = BlindIndex::of($identity->display_name);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'display_name' => 'encrypted',
            'school_number' => 'encrypted',
            'birth_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * The student's N.º DE PROCESSO — the school's own internal identifier for
     * them, read from the «N.º PROC.» column of the Relação de Turma.
     *
     * Stored as `school_number` because that is what it is: a number the SCHOOL
     * gives a student, not a number belonging to any one system that consumes
     * it. A later export to another platform reads it from here, by this name,
     * and there is deliberately no second field meaning the same thing.
     *
     * It lives on the identity, which is per (student, organization): the same
     * person enrolled in two schools has two of these, and neither is «the»
     * one. Encrypted at rest like every other identifying field (ADR-0004).
     */
    public function processNumber(): ?string
    {
        return $this->school_number;
    }
}
