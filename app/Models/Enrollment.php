<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
 * @property EnrollmentStatusReason|null $status_reason
 * @property bool $is_late_entry
 * @property string|null $late_entry_note
 * @property string|null $import_note
 * @property bool|null $include_evidence_in_report
 */
#[Fillable(['class_id', 'student_id', 'class_number', 'enrolled_on', 'left_on', 'status', 'status_reason', 'is_late_entry', 'late_entry_note', 'import_note', 'include_evidence_in_report'])]
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

    /**
     * The enrolments that are part of the class today.
     *
     * The one place the rule is written. `EnrollmentStatus::isCurrent()` is its
     * in-memory twin, and both state it positively: a status added later is
     * not current until somebody says so (§2, §9, §10).
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', EnrollmentStatus::Active);
    }

    protected function casts(): array
    {
        return [
            'enrolled_on' => 'date',
            'left_on' => 'date',
            'status' => EnrollmentStatus::class,
            'status_reason' => EnrollmentStatusReason::class,
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
     *
     * SEM CHAMADOR, E DE PROPÓSITO. O único ecrã que alguma vez escreveu estas
     * duas colunas foi a pauta de classificações antiga, absorvida pela Pauta
     * de Avaliação; o toggle «Incluir dados que constam nos Registos do
     * professor» saiu com ela, porque nunca chegou a ser lido por nada — nem
     * pelo pipeline dos relatórios-documento, que é o sítio onde faria sentido.
     * Era uma preferência que o professor podia mudar sem que mudasse nada.
     *
     * O MÉTODO E AS COLUNAS FICAM. `enrollments.include_evidence_in_report` e
     * `classes.include_evidence_in_report` guardam escolhas reais de
     * professores reais, e não se apagam dados de ninguém por arrumação. No dia
     * em que os Registos entrarem de facto nos relatórios, a regra — override
     * do aluno, senão o valor da turma, nunca um `false` escondido — já está
     * escrita aqui e as escolhas antigas continuam lá para ser respeitadas.
     */
    public function includesEvidenceInReport(): bool
    {
        return $this->include_evidence_in_report ?? $this->schoolClass->include_evidence_in_report;
    }
}
