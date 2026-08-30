<?php

namespace App\Models;

use App\Support\Commercial\VoucherCode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Um código emitido, e as condições em que vale.
 *
 * NÃO É UM PLANO E NÃO CONCEDE MÓDULOS. Nada em
 * `App\Support\Entitlements\Entitlements` lê esta tabela, exactamente como nada
 * lê `founder_seats` nem `commercial_condition`. O que um voucher move é o PREÇO
 * e o TERMO de um contrato — as quatro colunas de prova comercial de
 * `organization_subscriptions` — e um voucher que desbloqueasse uma
 * funcionalidade seria um plano com outro nome.
 *
 * O BENEFÍCIO É IMUTÁVEL DEPOIS DE EMITIDO, e isso é mais forte do que o
 * enunciado pede. A regra «pode-se editar enquanto ninguém resgatou» tem um
 * problema que a versão simples não tem: alguém que edite o benefício de um
 * código já distribuído altera uma promessa que já foi feita, sem que a base de
 * dados saiba distinguir «distribuído» de «resgatado». Um código emitido está
 * fora de mãos. Se estava errado, desactiva-se e emite-se outro — duas linhas e
 * um rasto, que é o mesmo mecanismo que `SubscriptionPayment` já impõe ao
 * dinheiro.
 *
 * NÃO HÁ CONTADOR DE RESGATES NESTA LINHA. A capacidade é sempre derivada das
 * linhas vivas de `voucher_redemptions`, contadas dentro da transação de
 * resgate sob `lockForUpdate` — ver `App\Actions\Commercial\RedeemVoucher`. Um
 * contador teria de descer quando uma reserva caduca, e cada decremento seria
 * uma segunda corrida; uma contagem derivada não tem estado para errar.
 *
 * FORA DA TENANCY, como o resto do domínio comercial: um voucher é da
 * plataforma, não de um inquilino, e não leva o global scope de organização.
 *
 * @property int $id
 * @property string $code
 * @property string $normalized_code
 * @property string $label
 * @property VoucherBenefitType $benefit_type
 * @property int|null $benefit_amount_cents
 * @property string|null $benefit_currency
 * @property int|null $benefit_percent
 * @property Carbon|null $benefit_free_until
 * @property int|null $plan_id
 * @property Carbon|null $valid_from
 * @property Carbon|null $valid_until
 * @property int|null $max_redemptions
 * @property Carbon|null $disabled_at
 * @property int|null $disabled_by
 * @property int|null $created_by
 */
#[Fillable([
    'code', 'label', 'benefit_type',
    'benefit_amount_cents', 'benefit_currency', 'benefit_percent', 'benefit_free_until',
    'plan_id', 'valid_from', 'valid_until', 'max_redemptions', 'created_by',
])]
class Voucher extends Model
{
    /**
     * Tudo o que pode mudar depois de a linha existir. Todo o resto é a promessa.
     *
     * `disabled_at`/`disabled_by` porque desactivar é a única correcção que
     * existe. `updated_at` vai atrás porque o Eloquent o carimba em qualquer
     * `save()` — é metadado sobre a linha, não sobre a promessa.
     *
     * @var list<string>
     */
    protected const MUTABLE_AFTER_CREATION = [
        'disabled_at', 'disabled_by', 'updated_at',
    ];

    protected static function booted(): void
    {
        // A forma normalizada NUNCA é escrita à mão por um chamador — nem sequer
        // é fillable: é derivada aqui, do código, uma vez. Deixá-la ao chamador
        // seria deixar que duas linhas discordassem sobre qual é a forma
        // canónica da mesma coisa — e a que ficasse errada seria simplesmente
        // inencontrável.
        static::creating(function (self $voucher): void {
            $voucher->normalized_code = VoucherCode::normalize((string) $voucher->code);
            $voucher->assertBenefitIsCoherent();
        });

        static::updating(function (self $voucher): void {
            $forbidden = array_diff(array_keys($voucher->getDirty()), self::MUTABLE_AFTER_CREATION);

            if ($forbidden !== []) {
                throw new LogicException(
                    'An issued voucher is immutable except for its disabled state: '
                    .'refused change to '.implode(', ', $forbidden).'. Disable it and issue another instead.'
                );
            }
        });
    }

    protected function casts(): array
    {
        return [
            'benefit_type' => VoucherBenefitType::class,
            'benefit_amount_cents' => 'integer',
            'benefit_percent' => 'integer',
            'benefit_free_until' => 'datetime',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
            'max_redemptions' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return HasMany<VoucherRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }

    /**
     * Procurar por código, sem que nenhum chamador tenha de saber normalizar.
     *
     * @param  Builder<Voucher>  $query
     * @return Builder<Voucher>
     */
    public function scopeCode(Builder $query, string $raw): Builder
    {
        return $query->where('normalized_code', VoucherCode::normalize($raw));
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** «Ilimitado» é NULL, dito explicitamente, e não um número muito grande. */
    public function isUnlimited(): bool
    {
        return $this->max_redemptions === null;
    }

    /** Derivado, nunca guardado: uma coluna `expired` seria uma data a precisar de um job. */
    public function hasExpired(?Carbon $at = null): bool
    {
        return $this->valid_until !== null && $this->valid_until->lessThan($at ?? Carbon::now());
    }

    public function hasNotStarted(?Carbon $at = null): bool
    {
        return $this->valid_from !== null && $this->valid_from->greaterThan($at ?? Carbon::now());
    }

    /**
     * Se este voucher pode aplicar-se ao plano-alvo dado.
     *
     * `plan_id` é uma RESTRIÇÃO, nunca uma instrução: o alvo vem sempre do
     * fluxo de contratação, e isto apenas responde se o código o serve. NULL
     * serve qualquer alvo.
     */
    public function appliesToPlan(Plan $target): bool
    {
        return $this->plan_id === null || $this->plan_id === $target->getKey();
    }

    /**
     * As linhas que CONSOMEM capacidade agora: confirmadas, e reservas dentro
     * do prazo. Uma reserva caducada ainda por limpar não conta.
     *
     * @return HasMany<VoucherRedemption, $this>
     */
    public function liveRedemptions(?Carbon $at = null): HasMany
    {
        $now = $at ?? Carbon::now();

        return $this->redemptions()
            ->where(function (Builder $alive) use ($now): void {
                $alive->whereNotNull('confirmed_at')
                    ->orWhere('reserved_until', '>=', $now);
            });
    }

    /**
     * Quantos resgates ainda cabem. NULL quando não há teto.
     *
     * Para o backoffice ler, nunca para decidir um resgate: quem decide é a
     * contagem feita sob `lockForUpdate` dentro da transação de
     * `RedeemVoucher`, e um número lido fora dela é apenas o que era verdade há
     * um instante.
     */
    public function remainingRedemptions(): ?int
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0, (int) $this->max_redemptions - $this->liveRedemptions()->count());
    }

    /**
     * Recusa uma linha cujo benefício não está completo.
     *
     * Duplica de propósito as CHECK constraints da migração — que só existem em
     * MySQL — e acrescenta o que o SQL não sabe dizer tão legivelmente: que a
     * família escolhida exige exactamente os seus campos. Um `percent_discount`
     * sem percentagem não é «um voucher com um campo por preencher», é um
     * voucher que não sabe quanto vale.
     */
    protected function assertBenefitIsCoherent(): void
    {
        $problema = match ($this->benefit_type) {
            VoucherBenefitType::FixedPrice => $this->benefit_amount_cents === null || $this->benefit_currency === null
                || $this->benefit_percent !== null || $this->benefit_free_until !== null
                ? 'fixed_price needs benefit_amount_cents and benefit_currency, and nothing else'
                : null,
            VoucherBenefitType::PercentDiscount => $this->benefit_percent === null
                || $this->benefit_amount_cents !== null || $this->benefit_currency !== null || $this->benefit_free_until !== null
                ? 'percent_discount needs benefit_percent, and nothing else'
                : null,
            VoucherBenefitType::FreeUntil => $this->benefit_free_until === null
                || $this->benefit_amount_cents !== null || $this->benefit_currency !== null || $this->benefit_percent !== null
                ? 'free_until needs benefit_free_until, and nothing else'
                : null,
        };

        if ($problema !== null) {
            throw new LogicException('Incoherent voucher benefit: '.$problema.'.');
        }

        if ($this->benefit_percent !== null && ($this->benefit_percent < 1 || $this->benefit_percent > 100)) {
            throw new LogicException('A percentage discount must be between 1 and 100.');
        }
    }
}
