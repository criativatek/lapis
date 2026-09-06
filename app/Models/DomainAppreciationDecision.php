<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * O que o professor decidiu sobre UM domínio de UM aluno, num momento.
 *
 * A pauta calcula uma proposta para cada domínio — a banda em que o
 * quantitativo cai — e essa proposta continua a ser derivada, não guardada.
 * Esta linha é a outra metade da mesma conversa: o professor a dizer «neste
 * domínio a minha leitura é outra» (§3.3).
 *
 * O QUE ESTA LINHA NÃO GUARDA é tão importante como o que guarda: não guarda o
 * quantitativo (49% continua a ser 49%), não guarda a proposta (continua a ser
 * NS), não guarda médias recalculadas e não guarda nomes. Guarda um nível de
 * escala, quem o escreveu e quando.
 *
 * NÃO ALTERA CÁLCULO NENHUM. A decisão do professor sobre «Oralidade» não entra
 * na média ponderada dos domínios nem toca no resultado global: é uma leitura
 * qualitativa daquele domínio, não um valor a somar (§13.3).
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_id
 * @property int $enrollment_id
 * @property int $academic_period_id
 * @property ClassificationScope $scope
 * @property int $domain_id
 * @property int $scale_level_id
 * @property int $decided_by
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'enrollment_id', 'academic_period_id', 'scope', 'domain_id',
    'scale_level_id', 'decided_by',
])]
class DomainAppreciationDecision extends Model
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
            'scope' => ClassificationScope::class,
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
     * @return BelongsTo<AcademicPeriod, $this>
     */
    public function academicPeriod(): BelongsTo
    {
        return $this->belongsTo(AcademicPeriod::class);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class);
    }

    /**
     * @return BelongsTo<ScaleLevel, $this>
     */
    public function scaleLevel(): BelongsTo
    {
        return $this->belongsTo(ScaleLevel::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
