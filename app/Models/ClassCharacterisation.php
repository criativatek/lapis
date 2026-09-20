<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\IsCharacterisation;
use App\Support\Characterisation\CharacterisationSection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A caracterização pedagógica da turma como um todo — o acessório, não o
 * essencial (§2.2 do desenho). 1:1 com `classes`, um único `summary` de texto
 * livre para o que é da turma e não de nenhum aluno em particular.
 *
 * NÃO SUBSTITUI a caracterização por aluno (EnrollmentCharacterisation), que é
 * onde vive o trabalho pedagógico real. A interface trata-a como um cartão
 * secundário, recolhido acima da lista de alunos.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property string|null $summary
 * @property int|null $updated_by
 * @property Carbon|null $last_updated_at
 */
#[Fillable(['class_id', 'summary', 'updated_by', 'last_updated_at'])]
class ClassCharacterisation extends Model
{
    use BelongsToOrganization, HasUlids, IsCharacterisation;

    /**
     * A class has one section, not six. Potencialidades, interesses e barreiras
     * são de uma pessoa e não de um grupo — e dizê-lo aqui é o que impede um
     * pedido de escrever `strengths` numa turma só por enviar a chave.
     *
     * @return list<string>
     */
    public function characterisationSections(): array
    {
        return [CharacterisationSection::Summary->value];
    }

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
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * O histórico desta caracterização — mais recente primeiro, como a
     * interface o mostra fechado por omissão (§2.3): a data e o autor da
     * última revisão chegam sem abrir nada, e "Ver histórico" pede o resto.
     *
     * @return MorphMany<CharacterisationRevision, $this>
     */
    public function revisions(): MorphMany
    {
        return $this->morphMany(CharacterisationRevision::class, 'characterisable')->latest('created_at');
    }
}
