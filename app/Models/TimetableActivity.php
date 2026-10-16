<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma atividade não letiva do horário de UM professor (direção de turma, apoio,
 * reunião…). NÃO É UMA AULA: nunca gera `Lesson`, nunca é numerada nem
 * sumariada — nenhum leitor de `recurring_lesson_slots` a vê, e nada aqui
 * escreve em `lessons`. Ver a migração `create_timetable_activities_table`.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $user_id
 * @property TimetableActivityKind $kind
 * @property string $title
 * @property int|null $class_id
 * @property int $day_of_week
 * @property string $starts_at
 * @property string $ends_at
 * @property Carbon|null $starts_on
 * @property Carbon|null $ends_on
 */
#[Fillable(['user_id', 'kind', 'title', 'class_id', 'day_of_week', 'starts_at', 'ends_at', 'starts_on', 'ends_on'])]
class TimetableActivity extends Model
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
            'kind' => TimetableActivityKind::class,
            'day_of_week' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
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
     * Está em vigor no dia dado (`Y-m-d`)? Mesma regra dos tempos de aula:
     * `starts_on` e `ends_on` inclusivos, NULL = aberto.
     */
    public function isInForceOn(string $date): bool
    {
        if ($this->starts_on !== null && $this->starts_on->toDateString() > $date) {
            return false;
        }

        return $this->ends_on === null || $this->ends_on->toDateString() >= $date;
    }
}
