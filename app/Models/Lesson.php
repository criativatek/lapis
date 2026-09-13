<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
 * @property LessonStatus $status
 * @property Carbon|null $attendance_recorded_at
 * @property int|null $attendance_recorded_by
 * @property int|null $created_by nullable desde 2026-11-10 (importação de backup — ver 2026_11_10_000500_let_imported_lessons_keep_an_unresolved_author); nunca `null` numa aula criada pela própria aplicação
 * @property-read int|null $absent_count carregado por `withCount()` em WeeklyLessonsQuery — não existe fora dessa consulta
 */
#[Fillable(['class_id', 'class_group_id', 'recurring_lesson_slot_id', 'starts_at', 'ends_at', 'lesson_number', 'status', 'attendance_recorded_at', 'attendance_recorded_by', 'created_by'])]
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
}
