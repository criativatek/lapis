<?php

namespace App\Models;

use App\Support\Usage\UsageEventKind;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Uma linha de utilização (docs/usage-insights.md). Imutável por contrato, como
 * `AuditEvent`: uma linha de métricas que se reescreve não prova nada.
 *
 * Todas as colunas são números, datas ou palavras de uma lista fechada. Não há
 * onde guardar um texto, um nome de aluno ou um URL — não porque se confie em
 * quem escreve, mas porque não há coluna para isso.
 *
 * DELIBERADAMENTE SEM `BelongsToOrganization`: são dados de plataforma, só o
 * backoffice os lê, e a recolha acontece depois de a organização estar
 * resolvida. O âmbito global esconderia as linhas de todas as outras.
 *
 * @property int $id
 * @property int $user_id
 * @property int $organization_id
 * @property Carbon $occurred_at
 * @property Carbon $occurred_on
 * @property UsageFeature $feature
 * @property UsageEventType $event
 * @property UsageEventKind $kind
 * @property int|null $item_count
 * @property int|null $items_created
 * @property int|null $items_changed
 * @property int|null $items_unchanged
 * @property UsageFailureCode|null $failure_code
 * @property string $dedupe_key
 * @property Carbon|null $created_at
 */
#[Fillable([
    'user_id', 'organization_id', 'occurred_at', 'occurred_on', 'feature', 'event',
    'kind', 'item_count', 'items_created', 'items_changed', 'items_unchanged', 'failure_code', 'dedupe_key', 'created_at',
])]
class UsageEvent extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \LogicException('A usage event is immutable and cannot be updated.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'occurred_on' => 'date',
            'feature' => UsageFeature::class,
            'event' => UsageEventType::class,
            'kind' => UsageEventKind::class,
            'failure_code' => UsageFailureCode::class,
            'item_count' => 'integer',
            'items_created' => 'integer',
            'items_changed' => 'integer',
            'items_unchanged' => 'integer',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
