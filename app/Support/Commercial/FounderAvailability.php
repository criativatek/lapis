<?php

namespace App\Support\Commercial;

use App\Models\FounderSeat;
use Illuminate\Support\Carbon;

/**
 * Quantos lugares de Membro Fundador restam.
 *
 * A LANDING PROMETE UM NÚMERO E UMA DATA, e o que os verificava era um contador
 * a ler a tabela errada. `taken()` contava as subscrições com
 * `commercial_condition = founder` — uma população que **nenhum fluxo escrevia**:
 * o checkout marcava a condição no PAGAMENTO, e a subscrição só passava a
 * fundadora se, dias depois, um operador o dissesse à mão. O número que o
 * comprador via («Restam 250 lugares») era, por isso, verdadeiro por acidente e
 * ficaria em 250 para sempre.
 *
 * Agora conta os lugares que o próprio checkout atribui — ver `FounderSeats`,
 * que é quem os dá, e a migração 2026_09_12_000100, que explica porque é que
 * eles precisam de uma tabela. Esta classe só LÊ: não atribui, não liberta e
 * não sabe de concorrência. Quem escreve é o `FounderSeats`, e é lá que vive a
 * garantia de que não existe um 251.º.
 *
 * LEITURA ENTRE ORGANIZAÇÕES: os 250 são 250 na plataforma inteira, não por
 * inquilino. `founder_seats` não leva o global scope de organização por
 * construção, precisamente para que contá-los não dependa de quem pergunta.
 */
class FounderAvailability
{
    public function capacity(): int
    {
        return (int) config('billing.founder.seats');
    }

    /**
     * Lugares a segurar agora: os confirmados, mais as reservas que ainda não
     * venceram.
     *
     * Uma reserva vencida não conta — o lugar já voltou ao bolo, quer a linha
     * já tenha sido varrida quer não. É por isso que a data entra na conta e
     * não apenas a existência da linha: quem lê este número não pode depender
     * de uma limpeza ter corrido primeiro.
     */
    public function taken(): int
    {
        $now = Carbon::now();

        return FounderSeat::query()
            ->where(fn ($query) => $query
                ->whereNotNull('confirmed_at')
                ->orWhereNull('reserved_until')
                ->orWhere('reserved_until', '>', $now))
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
     * Só o prazo, sem olhar à lotação.
     *
     * Separado de `isOpen()` porque `FounderSeats::claim()` já está prestes a
     * tentar tomar um lugar — voltar a perguntar «restam?» antes de inserir
     * seria perguntar duas vezes a mesma coisa e agir sobre a resposta mais
     * velha das duas.
     */
    public function isWithinDeadline(): bool
    {
        return Carbon::now()->lessThanOrEqualTo($this->deadline());
    }

    /**
     * Lugares OU prazo — o que acabar primeiro fecha a condição, que é o que a
     * página diz.
     */
    public function isOpen(): bool
    {
        return $this->remaining() > 0 && $this->isWithinDeadline();
    }

    public function priceCents(): int
    {
        return (int) config('billing.founder.price_cents');
    }
}
