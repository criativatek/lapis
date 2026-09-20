<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\IsCharacterisation;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A caracterização pedagógica de um aluno — o valor principal da
 * funcionalidade (§2.1 do desenho). Ancorada em `enrollment_id`, nunca em
 * `student_id`: o facto pertence ao par (aluno, turma), não ao aluno isolado,
 * e isso dá de graça o âmbito da turma e do ano letivo, e evita que um aluno
 * que mude de turma leve consigo a caracterização escrita por outro
 * professor noutro contexto.
 *
 * As quatro secções do meio (`strengths`, `interests`, `needs`, `barriers`)
 * usam deliberadamente as palavras da proposta de educação inclusiva, para
 * ficarem reutilizáveis por um futuro instrumento legal — mas isto NÃO é um
 * PDI e não se apresenta como documento legal. Nenhuma secção é obrigatória
 * e nenhuma alimenta cálculo nenhum.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property string|null $summary
 * @property string|null $strengths
 * @property string|null $interests
 * @property string|null $needs
 * @property string|null $barriers
 * @property string|null $participation
 * @property int|null $updated_by
 * @property Carbon|null $last_updated_at
 */
#[Fillable([
    'enrollment_id', 'summary', 'strengths', 'interests', 'needs', 'barriers', 'participation',
    'updated_by', 'last_updated_at',
])]
class EnrollmentCharacterisation extends Model
{
    use BelongsToOrganization, HasUlids, IsCharacterisation;

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    protected function casts(): array
    {
        return [
            'last_updated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * As medidas de apoio que uma importação atribuiu a este aluno — destino
     * (B) da importação assistida (§4.4): pares (nível, código) tipados,
     * nunca uma determinação da aplicação, sempre com o `raw_token` original
     * ao lado.
     *
     * @return HasMany<EnrollmentCharacterisationSourceMeasure, $this>
     */
    public function sourceMeasures(): HasMany
    {
        return $this->hasMany(EnrollmentCharacterisationSourceMeasure::class, 'enrollment_characterisation_id');
    }

    /**
     * O histórico desta caracterização — mais recente primeiro (§2.3): fechado
     * por omissão na interface, "Ver histórico" é que pede o resto.
     *
     * @return MorphMany<CharacterisationRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(CharacterisationRevision::class, 'characterisable')->latest('created_at');
    }
}
