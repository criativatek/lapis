<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\SubjectParticipationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «Esta inscrição não frequentou esta disciplina, deste dia até àquele.»
 *
 * SEM LINHA = A FREQUENTAR (ver o docblock da migração). O intervalo é
 * INCLUSIVO DOS DOIS LADOS e comparado ao dia, por «Y-m-d» — o mesmo idioma
 * que `ClassGroupMembership` já usa para as suas próprias janelas, e a mesma
 * razão: `effective_from`/`effective_until` são colunas `date` e o que dizem
 * é um DIA, nunca um instante.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property SubjectParticipationState $state
 * @property SubjectParticipationReason $reason
 * @property string|null $reason_detail
 * @property string|null $note
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 */
#[Fillable(['enrollment_id', 'state', 'reason', 'reason_detail', 'note', 'effective_from', 'effective_until'])]
class SubjectParticipation extends Model
{
    /** @use HasFactory<SubjectParticipationFactory> */
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
            'state' => SubjectParticipationState::class,
            'reason' => SubjectParticipationReason::class,
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * As janelas de não-frequência em vigor numa data — a única forma de a
     * pergunta ser feita.
     *
     * `whereDate` nos dois lados, e nunca um `where` simples: pela mesma razão
     * que `ClassGroupMembership::scopeInVigorOn()` já documenta — comparadas
     * como texto contra um limite «Y-m-d», a janela que começa exatamente
     * nesse dia desapareceria sem erro nenhum.
     *
     * @param  Builder<SubjectParticipation>  $query
     */
    public function scopeInVigorOn(Builder $query, string $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $date));
    }

    /**
     * As janelas ainda abertas — as que uma reativação pode fechar.
     *
     * @param  Builder<SubjectParticipation>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('effective_until');
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
