<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property int|null $class_group_id
 * @property int|null $recurring_lesson_slot_id
 * @property Carbon $starts_at
 * @property Carbon|null $ends_at
 * @property int|null $lesson_number
 * @property string|null $lesson_unit_key
 * @property LessonStatus $status
 * @property LessonOutcome|null $outcome NULL = ocorrência ainda não fechada (0.146.0)
 * @property TeacherAbsenceReason|null $outcome_reason só numa ausência do professor, só categoria
 * @property string|null $outcome_note descrição curta, opcional, de uma atividade da turma
 * @property Carbon|null $outcome_recorded_at
 * @property int|null $outcome_recorded_by
 * @property Carbon|null $attendance_recorded_at
 * @property int|null $attendance_recorded_by
 * @property int|null $created_by nullable desde 2026-11-10 (importação de backup — ver 2026_11_10_000500_let_imported_lessons_keep_an_unresolved_author); nunca `null` numa aula criada pela própria aplicação
 * @property-read int|null $absent_count carregado por `withCount()` em WeeklyLessonsQuery — não existe fora dessa consulta
 */
#[Fillable(['class_id', 'class_group_id', 'recurring_lesson_slot_id', 'starts_at', 'ends_at', 'lesson_number', 'lesson_unit_key', 'status', 'outcome', 'outcome_reason', 'outcome_note', 'outcome_recorded_at', 'outcome_recorded_by', 'attendance_recorded_at', 'attendance_recorded_by', 'created_by'])]
class Lesson extends Model
{
    use BelongsToOrganization, HasUlids;

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
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'lesson_number' => 'integer',
            'status' => LessonStatus::class,
            'outcome' => LessonOutcome::class,
            'outcome_reason' => TeacherAbsenceReason::class,
            'outcome_recorded_at' => 'datetime',
            'attendance_recorded_at' => 'datetime',
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
     * @return BelongsTo<RecurringLessonSlot, $this>
     */
    public function recurringLessonSlot(): BelongsTo
    {
        return $this->belongsTo(RecurringLessonSlot::class);
    }

    /**
     * O grupo com que esta aula nasceu — NULL quando é a turma inteira.
     *
     * INSTANTÂNEO, NÃO LEITURA. Copiado do tempo do horário no momento da
     * materialização (MaterializeLessonsForRange) e nunca reescrito: rever o
     * slot em janeiro cria uma versão nova do slot, e as aulas de novembro
     * continuam a apontar para o grupo que na altura era verdade.
     *
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * «8.º F» ou «8.º F · T1» — o nome por que o professor reconhece esta
     * aula, num sítio só.
     *
     * Escrito aqui e não em cada ecrã porque são quatro os sítios que o
     * mostram (o cartão da semana, o cabeçalho do sumário, o título da página
     * e o `<Head>`), e um separador diferente em qualquer um deles leria como
     * outra coisa. O front-end recebe a frase já composta.
     *
     * Exige `schoolClass` (e `classGroup`, quando existe) carregados — todos
     * os chamadores o fazem com eager-loading, precisamente para que uma
     * lista de aulas não faça uma consulta por linha.
     */
    public function contextLabel(): string
    {
        return $this->classGroup === null
            ? $this->schoolClass->label
            : $this->schoolClass->label.' · '.$this->classGroup->label;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasOne<LessonPlan, $this>
     */
    public function plan(): HasOne
    {
        return $this->hasOne(LessonPlan::class);
    }

    /**
     * @return HasOne<LessonSummary, $this>
     */
    public function summary(): HasOne
    {
        return $this->hasOne(LessonSummary::class);
    }

    /**
     * @return HasMany<LessonAttendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(LessonAttendance::class);
    }

    /**
     * Se a assiduidade desta aula já está CONSOLIDADA — um instantâneo
     * fechado, e não mais um rascunho. `attendance_recorded_at` é a única
     * fonte desta resposta: nunca se infere pela presença de linhas, porque
     * antes da consolidação já podem existir linhas `absent` de rascunho.
     */
    public function attendanceRecorded(): bool
    {
        return $this->attendance_recorded_at !== null;
    }

    /**
     * A ocorrência já fechou — com qualquer resultado. Uma aula `taught` de
     * antes da 0.146.0 fica fechada pelo backfill; o `status` é lido também
     * para que um modelo em memória nunca pareça aberto por engano.
     */
    public function isClosed(): bool
    {
        return $this->outcome !== null || $this->status === LessonStatus::Taught;
    }

    /**
     * Fechada e com número próprio na sequência (lecionada ou atividade da
     * turma). É o histórico que a renumeração não pode mudar.
     */
    public function isClosedAndNumbered(): bool
    {
        return $this->isClosed() && $this->outcome !== LessonOutcome::TeacherAbsent;
    }

    /**
     * «Lecionada», na base de dados: `outcome = taught`, ou — numa linha que
     * ainda não tem resultado mas tem o estado antigo (importação, fixture) —
     * `status = taught`. É a única ocorrência onde a assiduidade se aplica.
     *
     * @param  Builder<Lesson>|\Illuminate\Database\Query\Builder  $query
     */
    public static function whereCountsAsTaughtWithAttendance($query): void
    {
        $query->where('outcome', LessonOutcome::Taught->value)
            ->orWhere(fn ($legacy) => $legacy->whereNull('outcome')->where('status', LessonStatus::Taught->value));
    }

    /**
     * A assiduidade dos alunos não se aplica a esta ocorrência.
     */
    public function attendanceNotApplicable(): bool
    {
        return $this->outcome !== null && ! $this->outcome->takesAttendance();
    }
}
