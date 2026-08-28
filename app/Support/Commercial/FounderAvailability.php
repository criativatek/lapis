<?php

namespace App\Support\Commercial;

use App\Models\CommercialCondition;
use App\Models\OrganizationSubscription;
use Illuminate\Support\Carbon;

/**
 * Quantos lugares de Membro Fundador restam.
 *
 * A LANDING PROMETE UM NÚMERO E UMA DATA, e até agora nada os verificava: os
 * 250 estavam em `commercial.ts`, do lado do browser, onde não se conta nada.
 * Isto conta — e conta a partir de um FACTO REGISTADO, não de uma dedução: o
 * número de subscrições cuja condição comercial um operador marcou como
 * `founder`. Pagar 29,90 € continua a não fazer de ninguém fundador, tal como
 * o `RecordSubscriptionPayment` insiste.
 *
 * LEITURA ENTRE ORGANIZAÇÕES, e por isso `withoutGlobalScope`: os 250 são 250
 * na plataforma inteira, não por inquilino — contá-los dentro de um daria 250
 * a cada um. É o «agregado institucional» que a ADR-0002 permite, e fica
 * confinado a esta classe para continuar a ser raro o suficiente para se notar.
 */
class FounderAvailability
{
    public function capacity(): int
    {
        return (int) config('billing.founder.seats');
    }

    public function taken(): int
    {
        return OrganizationSubscription::query()
            ->withoutGlobalScope('organization')
            ->where('commercial_condition', CommercialCondition::Founder->value)
            ->count();
    }

    public function remaining(): int
    {
        return max(0, $this->capacity() - $this->taken());
    }

    /** Inclusivo: um pedido feito nesse dia ainda conta. */
    public function deadline(): Carbon
    {
        return Carbon::parse((string) config('billing.founder.deadline'))->endOfDay();
    }

    /**
     * Lugares OU prazo — o que acabar primeiro fecha a condição, que é o que a
     * página diz.
     */
    public function isOpen(): bool
    {
        return $this->remaining() > 0 && Carbon::now()->lessThanOrEqualTo($this->deadline());
    }

    public function priceCents(): int
    {
        return (int) config('billing.founder.price_cents');
    }
}
