<?php

namespace App\Domain\AcademicCalendar;

/**
 * O que uma exceção letiva PROPOSTA encontrou no calendário que já existe — ou
 * uma admissão honesta de que não encontrou nada.
 *
 * Os quatro desfechos, e o que cada um autoriza a quem propõe:
 *
 *   (nenhum)        — nada de parecido lá está. Quem propõe pode criar.
 *   já existente    — está lá, com a MESMA espécie, as MESMAS datas e uma
 *                     designação que normaliza para a mesma coisa. Não há nada
 *                     a fazer: nem se recria, nem se oferece escolha nenhuma.
 *   correspondência — está lá, com a mesma espécie e as mesmas datas, mas com
 *                     OUTRA designação. «Dia do Trabalhador» e «1.º de Maio»
 *                     são o mesmo dia com dois nomes, e nenhum dos dois é mais
 *                     verdadeiro do que o outro: não se cria uma segunda linha,
 *                     e as duas designações vão lado a lado para o professor
 *                     escolher qual fica. NUNCA se resolve por regra (§32).
 *   conflito        — está lá algo da mesma espécie a sobrepor-se, sem ser o
 *                     mesmo intervalo. Também não se resolve por aproximação:
 *                     mostram-se os dois e decide quem sabe.
 *
 * `current` é o retrato do que já lá está — a MESMA forma para os dois ecrãs que
 * a mostram (a pré-visualização da importação e o diálogo de sugestão de
 * feriados), porque a pergunta que ela responde é a mesma nos dois: «e o que é
 * que eu já tenho?».
 */
final readonly class AcademicCalendarExceptionMatch
{
    public const STATE_EXISTS = 'exists';

    public const STATE_CORRESPONDENCE = 'correspondence';

    public const STATE_CONFLICT = 'conflict';

    /**
     * @param  string|null  $state  `null` quando nada foi encontrado
     * @param  array<string, mixed>|null  $current  o retrato da linha encontrada, quando houve uma
     */
    private function __construct(
        public ?string $state,
        public ?array $current,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function exists(array $current): self
    {
        return new self(self::STATE_EXISTS, $current);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function correspondence(array $current): self
    {
        return new self(self::STATE_CORRESPONDENCE, $current);
    }

    /**
     * @param  array<string, mixed>  $current
     */
    public static function conflict(array $current): self
    {
        return new self(self::STATE_CONFLICT, $current);
    }

    public function matched(): bool
    {
        return $this->state !== null;
    }

    public function is(string $state): bool
    {
        return $this->state === $state;
    }
}
