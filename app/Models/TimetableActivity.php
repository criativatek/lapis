<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property bool $numbering_enabled
 * @property int $numbering_start
 */
#[Fillable(['user_id', 'kind', 'title', 'class_id', 'day_of_week', 'starts_at', 'ends_at', 'starts_on', 'ends_on', 'numbering_enabled', 'numbering_start'])]
class TimetableActivity extends Model
{
    use BelongsToOrganization, HasUlids;

    /**
     * A numeração das sessões só existe se o professor a pedir (0.170.0); sem
     * isto, uma atividade acabada de criar não teria os valores por omissão da
     * coluna até ser relida.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'numbering_enabled' => false,
        'numbering_start' => 1,
    ];

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
            'numbering_enabled' => 'boolean',
            'numbering_start' => 'integer',
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
     * Os registos por sessão desta atividade (0.170.0). `nullOnDelete`: apagar
     * o bloco nunca apaga um sumário.
     *
     * @return HasMany<TimetableActivitySession, $this>
     */
    public function sessions(): HasMany
    {
        return $this->hasMany(TimetableActivitySession::class);
    }

    /**
     * Esta atividade tem uma ocorrência no dia dado (`Y-m-d`)? Dia da semana
     * certo e vigência a cobri-lo. Não olha para o calendário letivo.
     */
    public function occursOn(string $date): bool
    {
        return (int) CarbonImmutable::parse($date)->dayOfWeekIso === (int) $this->day_of_week
            && $this->isInForceOn($date);
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
