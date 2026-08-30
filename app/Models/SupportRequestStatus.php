<?php

namespace App\Models;

/**
 * Onde está um pedido de suporte — quatro estados, e nenhum a mais.
 *
 * NÃO EXISTE `closed`, e a ausência é a decisão (ADR-0011 §2). Dois estados
 * finais que ninguém sabe distinguir é a mesma armadilha que
 * `CommercialCondition` documenta entre `Other` e NULL: quem os escreve hesita,
 * quem os lê adivinha, e a estatística mistura os dois. Um pedido ou está por
 * fechar, ou está fechado.
 *
 * `WaitingForUser` é o único estado com relógio. `waiting_since` existe lá e só
 * lá — a restrição da base de dados di-lo em SQL, e é dela que a retenção conta
 * os 23 e os 30 dias.
 */
enum SupportRequestStatus: string
{
    /** Chegou. Ninguém lhe pegou ainda. */
    case Open = 'open';

    /** Um operador pegou nele. */
    case InProgress = 'in_progress';

    /** A bola está do lado de quem perguntou. É aqui que o relógio anda. */
    case WaitingForUser = 'waiting_for_user';

    /** Fechado. É daqui que os 24 meses de retenção contam. */
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Open => __('Aberto'),
            self::InProgress => __('Em curso'),
            self::WaitingForUser => __('À espera de resposta'),
            self::Resolved => __('Resolvido'),
        };
    }

    /** Se o relógio da espera corre neste estado. */
    public function waits(): bool
    {
        return $this === self::WaitingForUser;
    }

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
