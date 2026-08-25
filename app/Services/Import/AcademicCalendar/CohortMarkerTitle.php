<?php

namespace App\Services\Import\AcademicCalendar;

/**
 * «Fim 5/6/7/8.º» escrito como uma pessoa o diria: «Fim das atividades letivas
 * — 5.º/6.º/7.º/8.º anos».
 *
 * PORQUE É QUE ISTO EXISTE. A célula do documento é uma abreviatura escrita
 * para caber dentro de um quadradinho de calendário, e a partir do momento em
 * que o professor aceita a proposta ela deixa de estar num quadradinho: passa a
 * ser o TÍTULO de um acontecimento do calendário dele, lido meses depois, fora
 * da coluna de junho que lhe dava o contexto todo. «Fim 5/6/7/8.º» não diz o
 * fim de quê, e a única altura em que ainda se sabe é aqui.
 *
 * NÃO SE INVENTA NADA QUE O RÓTULO NÃO DIGA, e é essa a linha. Expande-se o que
 * é abreviatura sem alternativa — «Pré» é o pré-escolar e «1.ºC» é o 1.º Ciclo,
 * que é como o sistema de ensino português lhes chama —, escreve-se por extenso
 * o que estava implícito na coluna («fim» de quê), e mais nada: nenhum ano de
 * escolaridade que não esteja lá escrito, nenhum ciclo que não esteja lá
 * escrito. Um rótulo que este normalizador NÃO reconheça sai daqui exatamente
 * como entrou — dizer menos é honesto, dizer errado não é.
 *
 * O PLURAL CONTA-SE, não se adivinha: «9.º ano» com um ano de escolaridade só e
 * «5.º/6.º/7.º/8.º anos» com quatro. Cada algarismo leva o seu «º» — o
 * documento escreve-o uma vez ao fim de todos, o que é abreviatura de calendário
 * e não português — e o «anos» aparece uma vez só, para o grupo inteiro.
 *
 * É PURO E NÃO VÊ FICHEIRO NENHUM: entra uma string, sai uma string. É por isso
 * que vive aqui e não dentro do parser, e é por isso que se testa sem abrir um
 * .xlsx.
 */
final class CohortMarkerTitle
{
    /**
     * O rótulo do documento («Fim 9.º ano»), já limpo pelo parser, transformado
     * na frase que vai ser o título do acontecimento — ou devolvido tal e qual
     * se não houver uma coorte reconhecível lá dentro.
     */
    public static function normalise(string $label): string
    {
        $cohort = self::cohortIn($label);

        return $cohort === null
            ? $label
            : __('Fim das atividades letivas — :cohort', ['cohort' => $cohort]);
    }

    /**
     * A coorte escrita por extenso: «9.º ano», «5.º/6.º/7.º/8.º anos»,
     * «Pré-escolar e 1.º Ciclo». Nulo quando alguma das partes não é
     * reconhecível — e então nada disto acontece.
     */
    private static function cohortIn(string $label): ?string
    {
        // «Fim», «Fim do», «Fim das» — a palavra que já sabemos que lá está (é
        // ela que faz do rótulo um marcador de coorte) e o artigo que às vezes a
        // segue. O que sobra é a coorte e mais nada.
        $remainder = trim((string) preg_replace('/^fim(?![\p{L}])\s*(?:d[oae]s?\s+)?/iu', '', $label));

        if ($remainder === '') {
            return null;
        }

        $parts = array_values(array_filter(
            array_map(trim(...), explode('/', $remainder)),
            fn (string $part): bool => $part !== '',
        ));

        if ($parts === []) {
            return null;
        }

        /** @var list<int> $grades */
        $grades = [];
        /** @var list<string> $phrases */
        $phrases = [];
        $onlyGrades = true;

        foreach ($parts as $part) {
            $grade = self::gradeIn($part);

            if ($grade !== null) {
                $grades[] = $grade;
                $phrases[] = __(':grades ano', ['grades' => self::ordinal($grade)]);

                continue;
            }

            $group = self::namedGroupIn($part);

            if ($group === null) {
                return null;
            }

            $onlyGrades = false;
            $phrases[] = $group;
        }

        if ($onlyGrades) {
            $written = implode('/', array_map(self::ordinal(...), $grades));

            return count($grades) === 1
                ? __(':grades ano', ['grades' => $written])
                : __(':grades anos', ['grades' => $written]);
        }

        return self::listed($phrases);
    }

    /**
     * «9», «9.º», «9º», «9.º ano» — o mesmo ano de escolaridade, escrito de
     * quatro maneiras no mesmo documento.
     *
     * O intervalo 1–12 não é decoração: sem ele, um «Fim 30 junho» que
     * escapasse à leitura da data entrava aqui como o «30.º ano».
     */
    private static function gradeIn(string $part): ?int
    {
        if (preg_match('/^(\d{1,2})\s*\.?\s*[º°o]?\s*(?:anos?)?$/iu', $part, $match) !== 1) {
            return null;
        }

        $grade = (int) $match[1];

        return $grade >= 1 && $grade <= 12 ? $grade : null;
    }

    /**
     * As duas coortes que não se escrevem com um ano de escolaridade: o
     * pré-escolar e um ciclo inteiro.
     *
     * «1.ºC» É EXPANDIDO PARA «1.º Ciclo» e não para nada mais ambicioso. É a
     * única leitura possível de um «C» a seguir a um número num calendário
     * escolar português, e mesmo assim a expansão fica pelo que lá está: não se
     * acrescenta que o 1.º Ciclo são os anos 1 a 4, porque isso é conhecimento
     * nosso e não uma coisa que o documento tenha dito.
     */
    private static function namedGroupIn(string $part): ?string
    {
        // «Pré», «Pré-escolar», «Pre escolar». O `(?![\p{L}])` é o que impede
        // uma palavra qualquer começada por «pre» de passar por pré-escolar.
        if (preg_match('/^pr[ée](?![\p{L}])/iu', $part) === 1) {
            return __('Pré-escolar');
        }

        if (preg_match('/^(\d)\s*\.?\s*[º°o]?\s*c(?:iclo)?\.?$/iu', $part, $match) === 1) {
            return __(':cycle Ciclo', ['cycle' => self::ordinal((int) $match[1])]);
        }

        return null;
    }

    /**
     * «Pré-escolar e 1.º Ciclo» — a vírgula até ao penúltimo e um «e» antes do
     * último, como se escreve uma enumeração e não como se junta uma lista.
     *
     * @param  list<string>  $phrases
     */
    private static function listed(array $phrases): string
    {
        if (count($phrases) < 2) {
            return $phrases[0] ?? '';
        }

        $last = array_pop($phrases);

        return __(':first e :last', ['first' => implode(', ', $phrases), 'last' => $last]);
    }

    private static function ordinal(int $number): string
    {
        return $number.'.º';
    }
}
