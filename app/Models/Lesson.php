<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property LessonStatus $status
 * @property int $created_by
 */
#[Fillable(['class_id', 'class_group_id', 'recurring_lesson_slot_id', 'starts_at', 'ends_at', 'status', 'created_by'])]
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
            'status' => LessonStatus::class,
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
}
