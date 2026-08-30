<?php

namespace App\Support\Commercial;

use App\Models\Organization;
use App\Models\Plan;
use App\Models\Voucher;
use App\Models\VoucherBenefitType;
use Illuminate\Support\Carbon;

/**
 * A pergunta «este código vale?» e a aritmética do benefício — num só sítio.
 *
 * SÓ LÊ. Nada aqui toma locks nem escreve: a landing e o ecrã de checkout
 * chamam isto para FALAR com o utilizador, e o que se diz num GET é sempre «o
 * que era verdade há um instante». Quem DECIDE é `RedeemVoucher`, que repete
 * estas mesmas verificações dentro da transação, sob `lockForUpdate` — a mesma
 * divisão que `FounderAvailability` (mostrar) e `FounderSeats` (contratar) já
 * estabeleceram.
 *
 * A ARITMÉTICA VIVE AQUI E EM MAIS LADO NENHUM. Um `percent_discount` sobre o
 * preço de tabela tem um arredondamento, e dois sítios a arredondar são duas
 * respostas para «quanto custa». O resgate congela o RESULTADO desta conta em
 * `voucher_redemptions.result_price_cents`; depois disso ninguém a repete.
 */
class Vouchers
{
    /**
     * O código, resolvido para este alvo e esta organização — a que houver.
     *
     * `$organization` NULL é a landing: ainda não há conta, e por isso
     * `AlreadyRedeemed` não é verificável — será, no checkout. `$target` NULL
     * pergunta pelo código em si, sem plano em jogo.
     *
     * A ORDEM DOS CASOS É A DA CONVERSA, não a da implementação: primeiro «isto
     * é sequer um código?», depois «existe?», depois «está de pé?», depois «serve
     * este resgate?». O primeiro que falha responde.
     */
    public function resolve(
        string $raw,
        ?Organization $organization = null,
        ?Plan $target = null,
        ?Carbon $at = null,
    ): VoucherResolution {
        $at ??= Carbon::now();

        if (! VoucherCode::isWellFormed($raw)) {
            return new VoucherResolution(VoucherOutcome::Malformed);
        }

        /** @var Voucher|null $voucher */
        $voucher = Voucher::query()->code($raw)->first();

        if ($voucher === null) {
            return new VoucherResolution(VoucherOutcome::NotFound);
        }

        return new VoucherResolution($this->outcomeFor($voucher, $organization, $target, $at), $voucher);
    }

    /**
     * O veredicto sobre um voucher JÁ CARREGADO — partilhado com o resgate.
     *
     * `RedeemVoucher` chama isto de novo dentro da transação, sobre a linha
     * bloqueada: é a mesma lista de recusas nos dois momentos, e só o momento
     * difere. Excepção: a exaustão não se decide aqui — é uma contagem, e uma
     * contagem só vale sob o lock que impede outra transação de a mudar.
     */
    public function outcomeFor(
        Voucher $voucher,
        ?Organization $organization,
        ?Plan $target,
        Carbon $at,
    ): VoucherOutcome {
        if ($voucher->isDisabled()) {
            return VoucherOutcome::Disabled;
        }

        if ($voucher->hasNotStarted($at)) {
            return VoucherOutcome::NotStarted;
        }

        if ($voucher->hasExpired($at)) {
            return VoucherOutcome::Expired;
        }

        if ($target !== null && ! $voucher->appliesToPlan($target)) {
            return VoucherOutcome::WrongPlan;
        }

        if ($organization !== null) {
            $existing = $voucher->redemptions()
                ->where('organization_id', $organization->getKey())
                ->first();

            if ($existing !== null && $existing->isConfirmed()) {
                return VoucherOutcome::AlreadyRedeemed;
            }

            // Uma reserva viva desta organização NÃO é uma recusa: é o caminho
            // idempotente — a mesma compra a continuar. Quem a devolve é o
            // resgate; aqui ela apenas não invalida.
        }

        // Sem lock, a exaustão é apenas informativa — mas para MOSTRAR já chega:
        // se estava cheio há um instante, dizê-lo é mais útil do que deixar o
        // utilizador descobrir no clique seguinte.
        if (! $voucher->isUnlimited() && $voucher->liveRedemptions($at)->count() >= (int) $voucher->max_redemptions) {
            $held = $organization !== null && $voucher->redemptions()
                ->where('organization_id', $organization->getKey())
                ->exists();

            if (! $held) {
                return VoucherOutcome::Exhausted;
            }
        }

        return VoucherOutcome::Valid;
    }

    /**
     * O preço que sai do voucher sobre um preço de tabela, em cêntimos.
     *
     * SEMPRE SOBRE O PREÇO DE TABELA, nunca sobre o preço de fundador: as duas
     * condições não acumulam, e a comparação entre elas acontece no checkout —
     * ver `RequestBankTransferPayment`. `free_until` não tem preço (não há
     * transferência nenhuma) e é um erro perguntar-lho.
     *
     * NUNCA NEGATIVO, por construção: `fixed_price` é unsigned na base de dados
     * e `percent_discount` está preso a 1..100 — 100 % dá exactamente zero. O
     * `max(0, …)` é a rede para o caso que a aritmética já não deixa acontecer.
     */
    public function resultPriceFor(Voucher $voucher, int $listPriceCents): int
    {
        return match ($voucher->benefit_type) {
            VoucherBenefitType::FixedPrice => max(0, (int) $voucher->benefit_amount_cents),
            VoucherBenefitType::PercentDiscount => max(0, $listPriceCents - (int) round($listPriceCents * (int) $voucher->benefit_percent / 100)),
            VoucherBenefitType::FreeUntil => throw new VoucherUnavailable(
                VoucherOutcome::WrongPlan,
                __('Este código não define um preço: dá acesso gratuito até uma data e resgata-se na página do plano.'),
            ),
        };
    }

    /**
     * A frase para cada recusa, escrita uma vez.
     *
     * Para ecrãs AUTENTICADOS — o checkout, a página do plano. A landing
     * pública NÃO usa isto: usa `VoucherOutcome::publicCategory()`, que achata
     * os casos nas três categorias que se podem dizer sem confirmar
     * existências a quem anda a adivinhar códigos.
     */
    public function messageFor(VoucherOutcome $outcome): string
    {
        return match ($outcome) {
            VoucherOutcome::Valid => __('O código é válido.'),
            VoucherOutcome::Malformed => __('Isto não tem a forma de um código de voucher.'),
            VoucherOutcome::NotFound => __('Este código não é reconhecido.'),
            VoucherOutcome::NotStarted => __('Este código ainda não está ativo.'),
            VoucherOutcome::Expired => __('Este código já expirou.'),
            VoucherOutcome::Exhausted => __('Este código já atingiu o limite de utilizações.'),
            VoucherOutcome::Disabled => __('Este código já não está disponível.'),
            VoucherOutcome::WrongPlan => __('Este código não se aplica a este plano.'),
            VoucherOutcome::AlreadyRedeemed => __('Este código já foi utilizado por esta conta.'),
        };
    }
}
