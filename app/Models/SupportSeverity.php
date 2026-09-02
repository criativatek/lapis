<?php

namespace App\Models;

/**
 * Quanto é que este problema impede alguém de trabalhar.
 *
 * INTERNA, SEMPRE. Não é devolvida ao professor em ecrã nenhum — é uma leitura
 * do operador para ordenar a própria fila, como `SupportTechnicalCode`. A
 * ADR-0011 §13 rejeitou níveis e SLA porque um nível MOSTRADO é uma promessa, e
 * não há promessa nenhuma por trás. Esta não é mostrada, e por isso não promete.
 * Se algum dia aparecer num ecrã de utilizador, passou a ser a coisa que a §13
 * recusou.
 *
 * TRÊS, E NÃO CINCO. Uma escala com cinco degraus faz com que ninguém saiba
 * distinguir o segundo do terceiro, e o resultado é uma fila ordenada por
 * hesitação. A pergunta aqui é uma só, e responde-se sem pensar muito: a pessoa
 * consegue trabalhar?
 */
enum SupportSeverity: string
{
    /** Não consegue fazer o que ia fazer. Não há volta a dar. */
    case BlocksWork = 'blocks_work';

    /** Consegue, mas por outro caminho ou com trabalho a mais. */
    case Workaround = 'workaround';

    /** Incomoda e não impede. */
    case Cosmetic = 'cosmetic';

    public function label(): string
    {
        return match ($this) {
            self::BlocksWork => 'Impede o trabalho',
            self::Workaround => 'Tem alternativa',
            self::Cosmetic => 'Cosmético',
        };
    }

    /**
     * A ordem por que a fila se lê. Menor é mais urgente.
     */
    public function weight(): int
    {
        return match ($this) {
            self::BlocksWork => 1,
            self::Workaround => 2,
            self::Cosmetic => 3,
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $severity): array => ['value' => $severity->value, 'label' => $severity->label()],
            self::cases(),
        );
    }
}
