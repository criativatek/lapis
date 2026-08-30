<?php

namespace App\Actions\Commercial;

use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use App\Models\VoucherRedemption;
use App\Services\Audit\AuditLog;
use App\Support\Commercial\VoucherOutcome;
use App\Support\Commercial\Vouchers;
use App\Support\Commercial\VoucherUnavailable;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * O resgate a sério: a decisão sob lock que a leitura pública só pré-via.
 *
 * TODA A CAPACIDADE É DECIDIDA NUMA SÓ TRANSAÇÃO, com `lockForUpdate` na linha
 * do voucher — o mesmo desenho de `FounderSeats::claim()`. O lock serializa
 * quem quer que esteja a decidir sobre o MESMO código; a contagem que se segue
 * é feita já sem ninguém a poder mudá-la; e a inserção acontece antes de o lock
 * cair. Não há contador desnormalizado: as linhas vivas SÃO a contagem.
 *
 * A ORDEM DENTRO DO LOCK É DELIBERADA E OBRIGATÓRIA:
 *
 *  1. limpar reservas caducadas — devolvem capacidade ANTES de ela ser medida;
 *  2. IDEMPOTÊNCIA PRIMEIRO: se esta organização já tem uma linha viva para
 *     este código, a resposta é essa linha (reserva) ou `AlreadyRedeemed`
 *     (confirmação) — NUNCA `Exhausted`. Um duplo clique no último lugar de um
 *     `max_redemptions = 1` tem de receber de volta a própria reserva, não uma
 *     recusa por um lugar que a própria organização detém;
 *  3. só então a capacidade, contada sobre o que sobrou;
 *  4. e só então a inserção.
 *
 * O RESULTADO É CONGELADO AQUI. `result_price_cents` é a aritmética de
 * `Vouchers::resultPriceFor()` feita UMA vez, sobre o preço de tabela que o
 * chamador mediu — depois disto ninguém repete a conta, nem o arredondamento.
 */
class RedeemVoucher
{
    public function __construct(
        protected Vouchers $vouchers,
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    /**
     * Reserva o resgate de um voucher COM PREÇO (fixed_price/percent_discount)
     * para uma compra por transferência ainda por confirmar.
     *
     * @param  int  $listPriceCents  o preço de tabela do alvo, medido pelo chamador
     * @param  Carbon  $reservedUntil  a MESMA janela do pedido de transferência
     *
     * @throws VoucherUnavailable
     */
    public function reserve(
        Voucher $voucher,
        Organization $organization,
        User $buyer,
        Plan $target,
        int $listPriceCents,
        Carbon $reservedUntil,
    ): VoucherRedemption {
        if ($voucher->benefit_type === VoucherBenefitType::FreeUntil) {
            throw new VoucherUnavailable(
                VoucherOutcome::WrongPlan,
                __('Este código dá acesso gratuito até uma data e resgata-se na página do plano, não no checkout.'),
            );
        }

        return DB::transaction(function () use ($voucher, $organization, $buyer, $target, $listPriceCents, $reservedUntil): VoucherRedemption {
            $locked = $this->lockAndRevalidate($voucher, $organization, $target);

            $resultCents = $this->vouchers->resultPriceFor($locked, $listPriceCents);

            $redemption = $this->admit($locked, $organization, $buyer, [
                'reserved_until' => $reservedUntil,
                'confirmed_at' => null,
                'result_price_cents' => $resultCents,
                'result_currency' => (string) config('billing.currency'),
                'result_term_ends_at' => null,
            ]);

            $this->record('commercial.voucher_reserved', $organization, $buyer, $locked, $redemption, sprintf(
                'Voucher %s reservado: %s € sobre um preço de tabela de %s €.',
                $locked->code,
                number_format($resultCents / 100, 2, ',', ' '),
                number_format($listPriceCents / 100, 2, ',', ' '),
            ));

            return $redemption;
        });
    }

    /**
     * Resgata um voucher `free_until`: nasce CONFIRMADO, porque não há dinheiro
     * a esperar — o benefício é o termo, não uma quantia.
     *
     * NÃO MUDA O PLANO. Quem muda é o chamador, com o mecanismo normal
     * (`ChangeOrganizationPlan::to()`), a seguir e na mesma transação exterior
     * se a tiver — este método só regista o resgate e congela o termo.
     *
     * @throws VoucherUnavailable
     */
    public function redeemFreeUntil(
        Voucher $voucher,
        Organization $organization,
        User $redeemer,
        Plan $target,
    ): VoucherRedemption {
        if ($voucher->benefit_type !== VoucherBenefitType::FreeUntil) {
            throw new VoucherUnavailable(
                VoucherOutcome::WrongPlan,
                __('Este código define um preço e resgata-se no checkout, não na página do plano.'),
            );
        }

        return DB::transaction(function () use ($voucher, $organization, $redeemer, $target): VoucherRedemption {
            $locked = $this->lockAndRevalidate($voucher, $organization, $target);

            $redemption = $this->admit($locked, $organization, $redeemer, [
                'reserved_until' => null,
                'confirmed_at' => Carbon::now(),
                'result_price_cents' => 0,
                'result_currency' => (string) config('billing.currency'),
                'result_term_ends_at' => $locked->benefit_free_until,
            ]);

            $this->record('commercial.voucher_redeemed', $organization, $redeemer, $locked, $redemption, sprintf(
                'Voucher %s resgatado: gratuito até %s.',
                $locked->code,
                $locked->benefit_free_until?->format('d/m/Y') ?? '—',
            ));

            return $redemption;
        });
    }

    /**
     * A transferência chegou: a reserva passa a definitiva.
     *
     * IDEMPOTENTE: confirmar o que já está confirmado devolve a linha como
     * está. Uma reserva JÁ CADUCADA ainda se confirma — o dinheiro entrou, e
     * «chegou tarde» é problema da janela do pedido, que o fluxo bancário
     * decide; o resgate não segunda-adivinha um pagamento que um operador
     * aceitou.
     */
    public function confirm(VoucherRedemption $redemption, User $operator, ?SubscriptionPayment $payment = null): VoucherRedemption
    {
        return DB::transaction(function () use ($redemption, $operator, $payment): VoucherRedemption {
            /** @var VoucherRedemption $locked */
            $locked = VoucherRedemption::query()
                ->whereKey($redemption->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isConfirmed()) {
                $locked->confirmed_at = Carbon::now();
            }

            if ($payment !== null) {
                $locked->subscription_payment_id = $payment->getKey();
            }

            $locked->save();

            $organization = $locked->organization()->firstOrFail();

            $this->record('commercial.voucher_confirmed', $organization, $operator, $locked->voucher()->firstOrFail(), $locked,
                'Resgate de voucher confirmado com o pagamento recebido.');

            return $locked;
        });
    }

    /**
     * Liga o resgate confirmado desta organização ao contrato que dele saiu.
     *
     * Puramente informativo, como `FounderSeats::attachSubscription()`: um
     * operador que abra a ficha vê o resgate ao lado da subscrição que o
     * materializou. Nada de comercial depende deste fio.
     */
    public function attachSubscription(Organization $organization, OrganizationSubscription $subscription): ?VoucherRedemption
    {
        /** @var VoucherRedemption|null $redemption */
        $redemption = VoucherRedemption::query()
            ->where('organization_id', $organization->getKey())
            ->whereNotNull('confirmed_at')
            ->whereNull('organization_subscription_id')
            ->latest('confirmed_at')
            ->first();

        $redemption?->forceFill(['organization_subscription_id' => $subscription->getKey()])->save();

        return $redemption;
    }

    /**
     * Lock na linha do voucher e revalidação de tudo o que se disse cá fora.
     *
     * A limpeza das reservas caducadas acontece AQUI, já sob o lock: são elas
     * que devolvem capacidade, e medi-la antes de as tirar seria recusar
     * resgates por lugares que já ninguém segura.
     *
     * @throws VoucherUnavailable
     */
    protected function lockAndRevalidate(Voucher $voucher, Organization $organization, Plan $target): Voucher
    {
        /** @var Voucher $locked */
        $locked = Voucher::query()
            ->whereKey($voucher->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $now = Carbon::now();

        // 1. Limpar o que caducou. O apagar é o mecanismo que devolve a
        // capacidade E o que mantém o UNIQUE(voucher, organização) compatível
        // com «expirar e voltar a tentar»: a linha morta sai, o par volta a
        // caber. O rasto fica na auditoria, não numa linha zombie.
        $expired = $locked->redemptions()
            ->whereNull('confirmed_at')
            ->where('reserved_until', '<', $now)
            ->get();

        foreach ($expired as $stale) {
            $this->releaseQuietly($stale, $locked);
        }

        // 2. O resto das recusas — a mesma lista que a leitura pública fez,
        // repetida agora que ninguém pode mudar a resposta.
        $outcome = $this->vouchers->outcomeFor($locked, $organization, $target, $now);

        if (! $outcome->isValid()) {
            throw new VoucherUnavailable($outcome, $this->vouchers->messageFor($outcome));
        }

        return $locked;
    }

    /**
     * Idempotência primeiro, capacidade depois, inserção no fim — sob o lock
     * que `lockAndRevalidate()` já tomou.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws VoucherUnavailable
     */
    protected function admit(Voucher $locked, Organization $organization, User $actor, array $attributes): VoucherRedemption
    {
        $now = Carbon::now();

        // IDEMPOTÊNCIA ANTES DA CAPACIDADE. Se esta organização já detém uma
        // linha viva deste código, a resposta é ela — nunca `Exhausted` por um
        // lugar que ela própria ocupa. As caducadas já foram apagadas acima, e
        // uma confirmada já teria respondido `AlreadyRedeemed` na revalidação.
        /** @var VoucherRedemption|null $existing */
        $existing = $locked->redemptions()
            ->where('organization_id', $organization->getKey())
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        // Capacidade, contada só agora: sobre as linhas que sobraram à limpeza,
        // sem esta organização entre elas.
        if (! $locked->isUnlimited() && $locked->liveRedemptions($now)->count() >= (int) $locked->max_redemptions) {
            throw new VoucherUnavailable(VoucherOutcome::Exhausted, $this->vouchers->messageFor(VoucherOutcome::Exhausted));
        }

        try {
            return $locked->redemptions()->create($attributes + [
                'organization_id' => $organization->getKey(),
                'redeemed_by' => $actor->getKey(),
                'redeemed_at' => $now,
                // O benefício como era NESTE instante, copiado — a disciplina
                // de `founder_seats.price_cents`.
                'benefit_type' => $locked->benefit_type,
                'benefit_amount_cents' => $locked->benefit_amount_cents,
                'benefit_currency' => $locked->benefit_currency,
                'benefit_percent' => $locked->benefit_percent,
                'benefit_free_until' => $locked->benefit_free_until,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Sob o lock do voucher isto não devia acontecer — mas «não devia»
            // não é «não pode», e a resposta certa para a corrida é a mesma da
            // idempotência: devolver a linha que ganhou.
            /** @var VoucherRedemption $winner */
            $winner = $locked->redemptions()
                ->where('organization_id', $organization->getKey())
                ->firstOrFail();

            return $winner;
        }
    }

    /** A limpeza de uma reserva morta: apagar com rasto, sem cerimónia. */
    protected function releaseQuietly(VoucherRedemption $stale, Voucher $voucher): void
    {
        $organization = $stale->organization()->first();
        $stale->delete();

        if ($organization !== null) {
            $this->record('commercial.voucher_reservation_expired', $organization, null, $voucher, $stale,
                'Reserva de voucher caducada e libertada; a capacidade voltou ao código.');
        }
    }

    protected function record(
        string $event,
        Organization $organization,
        ?User $actor,
        Voucher $voucher,
        VoucherRedemption $redemption,
        string $summary,
    ): void {
        $this->currentOrganization->runFor($organization, fn () => $this->audit->record(
            $event,
            $organization,
            $actor,
            summary: $summary,
            properties: [
                'voucher_code' => $voucher->code,
                'voucher_id' => $voucher->getKey(),
                'redemption_ulid' => $redemption->ulid,
                'benefit_type' => $redemption->benefit_type->value,
                'result_price_cents' => $redemption->result_price_cents,
                'result_term_ends_at' => $redemption->result_term_ends_at?->toDateTimeString(),
            ],
        ));
    }
}
