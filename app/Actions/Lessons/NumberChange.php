<?php

namespace App\Actions\Lessons;

/**
 * O que o professor pediu para o número de uma aula (0.169.0): indicar um
 * número (ou um intervalo «11–12») ou repor a sugestão automática.
 *
 * Imutável e já normalizado: um fim igual ao início, ou anterior a ele, não é
 * intervalo e fica `null` — a validação do pedido recusa os fins inválidos antes
 * de aqui chegar, e isto é só a garantia de que um fim nunca contradiz o início.
 */
final readonly class NumberChange
{
    private function __construct(
        public bool $manual,
        public ?int $start,
        public ?int $end,
    ) {}

    public static function manual(int $start, ?int $end = null): self
    {
        return new self(true, $start, $end !== null && $end > $start ? $end : null);
    }

    public static function automatic(): self
    {
        return new self(false, null, null);
    }

    public function mode(): string
    {
        return $this->manual ? 'manual' : 'automatic';
    }
}
