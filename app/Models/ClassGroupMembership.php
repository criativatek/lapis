<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\ClassGroupMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * «Esta inscrição pertenceu a este grupo, deste dia até àquele.»
 *
 * O intervalo é INCLUSIVO DOS DOIS LADOS e comparado ao dia, por «Y-m-d» —
 * `effective_from` e `effective_until` são colunas `date` e o que elas dizem é
 * um DIA, nunca um instante. É o mesmo idioma que
 * MaterializeLessonsForRange::isNonTeachingDay() já usa para as exceções
 * letivas, e a mesma razão: comparar meias-noites de fusos que não têm de
 * coincidir tornaria a resposta dependente da hora legal em vigor nesse dia.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_group_id
 * @property int $enrollment_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_until
 */
#[Fillable(['class_group_id', 'enrollment_id', 'effective_from', 'effective_until'])]
class ClassGroupMembership extends Model
{
    /** @use HasFactory<ClassGroupMembershipFactory> */
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
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    /**
     * As pertenças em vigor numa data — a única forma de a pergunta ser feita.
     *
     * `whereDate` nos dois lados, e nunca um `where` simples: são colunas
     * `date` guardadas como «Y-m-d 00:00:00» e, comparadas como texto contra um
     * limite «Y-m-d», a pertença que começa exatamente nesse dia desaparecia
     * sem erro nenhum — a armadilha que MaterializeLessonsForRange já
     * documenta para `starts_on`/`ends_on`.
     *
     * @param  Builder<ClassGroupMembership>  $query
     */
    public function scopeInVigorOn(Builder $query, string $date): void
    {
        $query->whereDate('effective_from', '<=', $date)
            ->where(fn (Builder $inner) => $inner
                ->whereNull('effective_until')
                ->orWhereDate('effective_until', '>=', $date));
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }
}
