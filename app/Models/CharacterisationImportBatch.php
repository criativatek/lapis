<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A proveniência de uma importação assistida confirmada (§4.5 do desenho):
 * de onde veio a caracterização, quando e por quem — nada mais. Não guarda o
 * ficheiro carregado (lido e descartado no mesmo pedido, §4.2) nem as linhas
 * rejeitadas: só o mínimo que responde a essa pergunta.
 *
 * Uma linha só existe depois de o professor confirmar — não há aqui estado
 * intermédio nenhum, porque não há nada de estagiado a meio caminho (§4.2):
 * o parser não escreve, só propõe.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $class_id
 * @property string $source_kind
 * @property string|null $original_filename
 * @property int $row_count
 * @property int|null $confirmed_by
 * @property Carbon $confirmed_at
 */
#[Fillable(['class_id', 'source_kind', 'original_filename', 'row_count', 'confirmed_by', 'confirmed_at'])]
class CharacterisationImportBatch extends Model
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
            'confirmed_at' => 'datetime',
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
    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
