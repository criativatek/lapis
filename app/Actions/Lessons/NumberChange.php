<?php

namespace App\Actions\Lessons;

/**
 * O que o professor pediu para o número de uma aula (0.169.0): indicar um
 * número ou repor a sugestão automática.
 *
 * UM SÓ NÚMERO (0.172.0, ADR-0017): uma aula corresponde a uma só lição. Já não
 * há fim de intervalo — um pedido antigo que ainda o traga é recusado pela
 * validação (`ValidatesLessonNumber`) antes de aqui chegar.
 */
final readonly class NumberChange
{
    private function __construct(
        public bool $manual,
        public ?int $start,
    ) {}

    public static function manual(int $start): self
    {
        return new self(true, $start);
    }

    public static function automatic(): self
    {
        return new self(false, null);
    }

    public function mode(): string
    {
        return $this->manual ? 'manual' : 'automatic';
    }
}
