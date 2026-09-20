<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Uma entrada do histórico de uma caracterização (§2.3 do desenho), no mesmo
 * molde de `AuditEvent`: polimórfica (aponta a ClassCharacterisation ou
 * EnrollmentCharacterisation), imutável por contrato.
 *
 * GUARDA SÓ O QUE MUDOU, NÃO UMA FOTOGRAFIA COMPLETA. `changed_sections`
 * lista as chaves de secção que mudaram; `previous_values` guarda apenas o
 * texto que lá estava, para essas secções. Uma caixa que sobrescreve tudo
 * sem deixar rasto perde trabalho e perde contexto, mas guardar um retrato
 * completo a cada gravação multiplica armazenamento de texto sobre menores
 * por cada vírgula corrigida — este é o meio-termo escolhido.
 *
 * Uma gravação que não muda nada não cria revisão nenhuma: a ausência de
 * linha é o caso comum, não um caso a preencher.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property string $characterisable_type
 * @property int $characterisable_id
 * @property array<int, string> $changed_sections
 * @property array<string, string|null> $previous_values
 * @property int|null $author_id
 * @property CharacterisationSource $source
 * @property int|null $import_batch_id
 * @property Carbon $created_at
 */
#[Fillable([
    'characterisable_type', 'characterisable_id', 'changed_sections', 'previous_values',
    'author_id', 'source', 'import_batch_id', 'created_at',
])]
class CharacterisationRevision extends Model
{
    use BelongsToOrganization, HasUlids;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('A characterisation revision is immutable and cannot be updated.');
        });
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
            'changed_sections' => 'array',
            'previous_values' => 'array',
            'source' => CharacterisationSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * The characterisation this revision belongs to — a class's or a student's.
     *
     * @return MorphTo<Model, $this>
     */
    public function characterisable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @return BelongsTo<CharacterisationImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(CharacterisationImportBatch::class, 'import_batch_id');
    }
}
