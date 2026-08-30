<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * O acto de uma organização resgatar um código — reserva primeiro, contrato
 * depois.
 *
 * DUAS FASES, NAS MESMAS DUAS COLUNAS DOS `founder_seats`:
 *
 *  - **Reserva** — `reserved_until` preenchido, `confirmed_at` NULL. O checkout
 *    foi concluído e a transferência ainda não chegou. Consome capacidade do
 *    voucher enquanto o prazo não passa; caducada, é APAGADA (com rasto na
 *    auditoria) e a capacidade volta — ver `ReleaseVoucherRedemption`.
 *  - **Confirmada** — `confirmed_at` preenchido. DEFINITIVA: o guard abaixo
 *    recusa apagá-la e recusa reescrever o que ela provou. Um `free_until`
 *    nasce já confirmado, porque não há dinheiro a esperar.
 *
 * O BENEFÍCIO E O RESULTADO VÊM COPIADOS do voucher no instante do resgate — a
 * mesma disciplina de `founder_seats.price_cents`: o voucher pode ser
 * desactivado amanhã, e o que esta organização recebeu não muda por isso.
 *
 * FORA DA TENANCY, como o resto do domínio comercial.
 *
 * @property int $id
 * @property string $ulid
 * @property int $voucher_id
 * @property int $organization_id
 * @property Carbon|null $reserved_until
 * @property Carbon|null $confirmed_at
 * @property int|null $organization_subscription_id
 * @property int|null $subscription_payment_id
 * @property int|null $redeemed_by
 * @property Carbon $redeemed_at
 * @property VoucherBenefitType $benefit_type
 * @property int|null $benefit_amount_cents
 * @property string|null $benefit_currency
 * @property int|null $benefit_percent
 * @property Carbon|null $benefit_free_until
 * @property int|null $result_price_cents
 * @property string|null $result_currency
 * @property Carbon|null $result_term_ends_at
 */
#[Fillable([
    'voucher_id', 'organization_id', 'reserved_until', 'confirmed_at',
    'organization_subscription_id', 'subscription_payment_id',
    'redeemed_by', 'redeemed_at',
    'benefit_type', 'benefit_amount_cents', 'benefit_currency', 'benefit_percent', 'benefit_free_until',
    'result_price_cents', 'result_currency', 'result_term_ends_at',
])]
class VoucherRedemption extends Model
{
    use HasUlids;

    /**
     * O que ainda pode mudar numa linha JÁ CONFIRMADA: nada além do fio ao
     * contrato e do carimbo do Eloquent. Uma reserva ainda pode ser confirmada
     * (é a transição do ciclo de vida); uma confirmação nunca volta atrás.
     *
     * @var list<string>
     */
    protected const MUTABLE_AFTER_CONFIRMATION = [
        'organization_subscription_id', 'subscription_payment_id', 'updated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $redemption): void {
            // Só vigia linhas que JÁ ESTAVAM confirmadas antes desta escrita:
            // a transição reserva → confirmada é o ciclo de vida a acontecer.
            if ($redemption->getOriginal('confirmed_at') === null) {
                return;
            }

            $forbidden = array_diff(array_keys($redemption->getDirty()), self::MUTABLE_AFTER_CONFIRMATION);

            if ($forbidden !== []) {
                throw new LogicException(
                    'A confirmed voucher redemption is immutable: refused change to '.implode(', ', $forbidden).'.'
                );
            }
        });

        static::deleting(function (self $redemption): void {
            if ($redemption->confirmed_at !== null) {
                throw new LogicException(
                    'A confirmed voucher redemption is the proof of a commercial condition and is never deleted. '
                    .'Only an unconfirmed reservation may be released.'
                );
            }
        });
    }

    /** @return array<int, string> */
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
            'reserved_until' => 'datetime',
            'confirmed_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'benefit_type' => VoucherBenefitType::class,
            'benefit_amount_cents' => 'integer',
            'benefit_percent' => 'integer',
            'benefit_free_until' => 'datetime',
            'result_price_cents' => 'integer',
            'result_term_ends_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<OrganizationSubscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(OrganizationSubscription::class, 'organization_subscription_id');
    }

    /**
     * @return BelongsTo<SubscriptionPayment, $this>
     */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPayment::class, 'subscription_payment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function redeemedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'redeemed_by');
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /** Uma reserva ainda dentro do prazo — a única coisa viva além da confirmação. */
    public function isHolding(?Carbon $at = null): bool
    {
        return ! $this->isConfirmed()
            && $this->reserved_until !== null
            && $this->reserved_until->greaterThanOrEqualTo($at ?? Carbon::now());
    }
}
