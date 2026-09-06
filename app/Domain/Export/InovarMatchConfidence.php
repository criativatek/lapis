<?php

namespace App\Domain\Export;

/**
 * QUANTA CERTEZA HÁ DE QUE ESTA LINHA DA GRELHA É ESTE ALUNO.
 *
 * Quatro respostas, e nenhuma delas é «talvez, escreve lá». O que separa as
 * duas primeiras das duas últimas é exatamente uma coisa: se o Lapispro pode
 * preencher sozinho ou se tem de perguntar.
 *
 *  - FORTE — preenche. O N.º de processo coincide dos dois lados, ou o nome
 *    coincide no primeiro e no último e não há outro candidato possível.
 *  - PROVÁVEL — pergunta. Há uma correspondência plausível e uma razão
 *    concreta para não a assumir: por exemplo, o N.º de processo do ficheiro
 *    diverge do que o Lapispro tem para essa pessoa.
 *  - AMBÍGUA — pergunta, e não sugere nenhum. Dois alunos são candidatos
 *    igualmente plausíveis, e escolher um seria escolher à sorte.
 *  - SEM CORRESPONDÊNCIA — não escreve nada. A linha fica exatamente como
 *    estava na grelha: nunca um zero, nunca um F, nunca um travessão.
 *
 * O QUE ESTA ESCALA NÃO TEM é uma aproximação de nomes. «Ana Martins» e «Ana
 * Martin» não geram uma correspondência provável com 90% de confiança — geram
 * nenhuma. Uma nota escrita no aluno errado não é um erro que alguém apanhe a
 * ler a grelha (§26).
 */
enum InovarMatchConfidence: string
{
    case Strong = 'strong';

    case Probable = 'probable';

    case Ambiguous = 'ambiguous';

    case None = 'none';

    /** Se o Lapispro pode preencher esta linha sem perguntar nada. */
    public function isAutomatic(): bool
    {
        return $this === self::Strong;
    }

    /**
     * Se o professor tem de se pronunciar antes de a exportação avançar.
     *
     * «Sem correspondência» NÃO está aqui, e é uma decisão de produto: uma
     * linha que não é de ninguém desta turma fica em branco, e isso é um
     * resultado legítimo — a grelha da escola pode ter alunos de outra turma.
     * O que não pode avançar é uma linha onde há uma pessoa em jogo e a
     * identidade dela ainda não está estabelecida (§28).
     */
    public function needsTeacher(): bool
    {
        return $this === self::Probable || $this === self::Ambiguous;
    }

    public function label(): string
    {
        return match ($this) {
            self::Strong => 'Correspondido',
            self::Probable => 'Correspondência provável — confirmar',
            self::Ambiguous => 'Correspondência ambígua — escolher',
            self::None => 'Sem correspondência',
        };
    }
}
