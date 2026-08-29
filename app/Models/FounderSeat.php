<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Um dos «primeiros 250» — o lugar em si, e não a subscrição que dele resulta.
 *
 * `seat_number` é o ordinal público que a landing promete: quem for o 250.º
 * sabe que foi o 250.º, e o `UNIQUE` da coluna é o que impede que exista um
 * 251.º sob concorrência. Ver a migração 2026_09_12_000100 para o raciocínio
 * completo.
 *
 * NÃO É UM DIREITO E NÃO É UM PLANO. Nada em `Entitlements` lê esta tabela: um
 * Membro Fundador é `plan = pro` com `commercial_condition = founder`, com
 * exatamente os mesmos módulos de um Pro normal. O lugar prova a CONDIÇÃO, e o
 * `ContractedTerms` gravado na subscrição prova o PREÇO.
 *
 * FORA DA TENANCY, como o resto do domínio comercial: os 250 são 250 na
 * plataforma inteira, e a tabela não leva o global scope de organização
 * precisamente porque contá-los dentro de um inquilino daria 250 a cada um.
 *
 * @property int $id
 * @property int $seat_number
 * @property int $organization_id
 * @property int $price_cents
 * @property string $currency
 * @property CarbonInterface $claimed_at
 * @property CarbonInterface|null $reserved_until
 * @property CarbonInterface|null $confirmed_at
 * @property int|null $subscription_payment_id
 * @property int|null $organization_subscription_id
 * @property int|null $claimed_by
 */
#[Fillable([
    'seat_number', 'organization_id', 'price_cents', 'currency',
    'claimed_at', 'reserved_until', 'confirmed_at',
    'subscription_payment_id', 'organization_subscription_id', 'claimed_by',
])]
class FounderSeat extends Model
{
    protected function casts(): array
    {
        return [
            'seat_number' => 'integer',
            'price_cents' => 'integer',
            'claimed_at' => 'datetime',
            'reserved_until' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<SubscriptionPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    /**
     * @return BelongsTo<OrganizationSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }

    /** Pago e definitivo. Nunca expira. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Ainda a segurar o lugar: ou já está confirmado, ou a reserva não venceu.
     *
     * Uma reserva sem `reserved_until` seria um lugar preso para sempre a um
     * carrinho abandonado, e por isso `FounderSeats` nunca cria uma — mas se
     * uma existisse, isto trata-a como viva em vez de a descartar em silêncio.
     */
    public function isHolding(?CarbonInterface $at = null): bool
    {
        if ($this->isConfirmed()) {
            return true;
        }

        return $this->reserved_until === null
            || $this->reserved_until->greaterThan($at ?? Carbon::now());
    }
}
