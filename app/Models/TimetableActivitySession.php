<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O registo de UMA sessão de uma «outra atividade» do horário de um professor
 * (0.170.0): o sumário do apoio, da reunião ou do clube numa data concreta.
 *
 * NÃO É UMA AULA. Nunca é um `Lesson`, nunca entra em `LessonNumbering`, nunca
 * toca nas aulas de nenhuma turma. A turma (opcional) é uma referência
 * informativa copiada do bloco.
 *
 * Só existe quando o professor guarda. A identificação (`activity_kind`,
 * `activity_title`, `class_id`, as horas) é uma CÓPIA tirada na criação: a
 * sessão continua legível e identificável se o bloco for alterado, fechado ou
 * eliminado (a FK é `nullOnDelete`).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $user_id
 * @property int|null $timetable_activity_id
 * @property string $activity_kind
 * @property string $activity_title
 * @property int|null $class_id
 * @property int|null $academic_year_id
 * @property Carbon $occurs_on
 * @property string $starts_at
 * @property string $ends_at
 * @property string|null $summary
 * @property TimetableActivitySessionStatus $status
 * @property int|null $session_number
 */
#[Fillable([
    'user_id', 'timetable_activity_id', 'activity_kind', 'activity_title', 'class_id', 'academic_year_id',
    'occurs_on', 'starts_at', 'ends_at', 'summary', 'status', 'session_number',
])]
class TimetableActivitySession extends Model
{
    use BelongsToOrganization, HasUlids;

    /** O limite do sumário, em caracteres: o mesmo da validação. */
    public const int SUMMARY_MAX = 16000;

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
            'occurs_on' => 'date',
            'status' => TimetableActivitySessionStatus::class,
            'session_number' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<TimetableActivity, $this>
     */
    public function activity(): BelongsTo
    {
        return $this->belongsTo(TimetableActivity::class, 'timetable_activity_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function hasSummary(): bool
    {
        return trim((string) $this->summary) !== '';
    }

    /**
     * «Tem registos»: texto não vazio OU estado diferente de «por realizar».
     * É o que nunca se perde nem se desloca quando o bloco muda.
     */
    public function hasRecords(): bool
    {
        return $this->hasSummary() || $this->status !== TimetableActivitySessionStatus::Planned;
    }

    /**
     * @param  Builder<TimetableActivitySession>  $query
     * @return Builder<TimetableActivitySession>
     */
    public function scopeWithRecords(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where('status', '!=', TimetableActivitySessionStatus::Planned->value)
                ->orWhere(function (Builder $text): void {
                    $text->whereNotNull('summary')->where('summary', '!=', '');
                });
        });
    }

    /**
     * @param  Builder<TimetableActivitySession>  $query
     * @return Builder<TimetableActivitySession>
     */
    public function scopeWithoutRecords(Builder $query): Builder
    {
        return $query->where('status', TimetableActivitySessionStatus::Planned->value)
            ->where(function (Builder $inner): void {
                $inner->whereNull('summary')->orWhere('summary', '');
            });
    }
}
