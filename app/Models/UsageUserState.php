<?php

namespace App\Models;

use App\Support\Usage\UsageMilestone;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O estado agregado de uma pessoa: desde quando tem turmas disponíveis
 * (configuração) e os primeiros passos.
 *
 * Cada primeiro passo tem duas coisas (docs/usage-insights.md, «Datas dos
 * primeiros passos»):
 *
 * - o ESTADO «atingido» (`first_*_reached`), que fica enquanto a conta existir
 *   — é o que os sinais precisam para nunca acusar de «falta de utilização»
 *   quem já deu o passo há muito tempo;
 * - o DIA da primeira observação (`first_*_on`), guardado só durante o prazo
 *   dos agregados. `usage:prune` apaga-o depois e o passo fica «atingido» sem
 *   data. Uma data é pegajosa enquanto existe (não anda para a frente), e um
 *   passo atingido cuja data já saiu NUNCA volta a ganhar uma.
 *
 * A chave de rota é o `ulid` (§11.2): o detalhe de uma pessoa nunca expõe o id.
 * Sem `BelongsToOrganization`, pela razão que `UsageEvent` explica.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property Carbon|null $class_available_since
 * @property Carbon|null $first_class_created_on
 * @property bool $first_class_created_reached
 * @property Carbon|null $first_students_added_on
 * @property bool $first_students_added_reached
 * @property Carbon|null $first_useful_record_on
 * @property bool $first_useful_record_reached
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
     * Uma data só existe num passo atingido (e o MySQL tem um CHECK que o
     * exige): gravar uma data marca o passo, venha de onde vier a escrita.
     */
    protected static function booted(): void
    {
        static::saving(function (self $state): void {
            foreach (UsageMilestone::cases() as $milestone) {
                if ($state->getAttribute($milestone->stateColumn()) !== null) {
                    $state->setAttribute($milestone->reachedColumn(), true);
                }
            }
        });
    }

    /** O passo foi atingido — com ou sem a data ainda guardada. */
    public function hasReached(UsageMilestone $milestone): bool
    {
        return $this->getAttribute($milestone->reachedColumn()) === true
            || $this->getAttribute($milestone->stateColumn()) !== null;
    }

    /** O dia da primeira observação, enquanto está guardado. */
    public function observedOn(UsageMilestone $milestone): ?string
    {
        $date = $this->getAttribute($milestone->stateColumn());

        return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_available_since' => 'datetime',
            'first_class_created_on' => 'date',
            'first_class_created_reached' => 'boolean',
            'first_students_added_on' => 'date',
            'first_students_added_reached' => 'boolean',
            'first_useful_record_on' => 'date',
            'first_useful_record_reached' => 'boolean',
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
