<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O estado agregado de uma pessoa: desde quando tem turmas disponíveis
 * (configuração) e as datas de primeira OBSERVAÇÃO dos primeiros passos.
 *
 * As datas `first_*_on` são pegajosas — a conservação apaga eventos, e a data
 * já observada não pode andar para a frente. Ver `AggregateUsage`.
 *
 * A chave de rota é o `ulid` (§11.2): o detalhe de uma pessoa nunca expõe o id.
 * Sem `BelongsToOrganization`, pela razão que `UsageEvent` explica.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property Carbon|null $class_available_since
 * @property Carbon|null $first_class_created_on
 * @property Carbon|null $first_students_added_on
 * @property Carbon|null $first_useful_record_on
 * @property Carbon $computed_at
 */
#[Fillable([
    'user_id', 'class_available_since', 'first_class_created_on', 'first_students_added_on',
    'first_useful_record_on', 'computed_at',
])]
class UsageUserState extends Model
{
    use HasUlids;

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_available_since' => 'datetime',
            'first_class_created_on' => 'date',
            'first_students_added_on' => 'date',
            'first_useful_record_on' => 'date',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
