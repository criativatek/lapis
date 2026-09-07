<?php

namespace App\Support\Assessment;

/**
 * AS PALAVRAS DAS DUAS LEITURAS DO ANO — um sítio só, para os vários ecrãs e
 * ficheiros que falam delas.
 *
 * O PRODUTO TEM DUAS LEITURAS DO MESMO ANO LETIVO, e elas respondem a perguntas
 * diferentes. Confundi-las é dizer a um professor um número em vez do outro:
 *
 *  AVALIAÇÃO CONTÍNUA — o indicador FORMAL. A média (ou média ponderada, conforme
 *  a configuração) dos RESULTADOS FORMAIS de cada unidade temporal do ano. É
 *  daqui que sai a proposta formal de nível. Os momentos intercalares nunca
 *  entram: são fotografias informativas do caminho, não conclusões de unidade.
 *
 *  DESEMPENHO ACUMULADO — o indicador ANALÍTICO. O resultado obtido
 *  considerando diretamente os elementos de avaliação acumulados até ao
 *  momento, reprocessados pelo motor. Não é uma média de médias, e não é o
 *  indicador formal.
 *
 * PORQUE O SEGUNDO DEIXOU DE SE CHAMAR SÓ «ACUMULADO». Enquanto era a única
 * leitura do ano, «Acumulado» dizia tudo o que havia a dizer. A partir do
 * momento em que as duas aparecem lado a lado, «Acumulado» é ambíguo — um
 * professor que acabou de ler «avaliação contínua» pode razoavelmente supor que
 * a coluna ao lado é a mesma coisa somada de outra maneira, e não é. O nome
 * longo existe para os sítios onde há espaço para ele.
 *
 * O CÁLCULO NÃO MUDOU, E ESTA CLASSE NÃO CALCULA NADA. `ClassificationScope::
 * Accumulated` continua exatamente como estava; o que aqui vive são palavras.
 *
 * O BROWSER TEM A SUA CÓPIA em `resources/js/lib/readings.ts`, e
 * `ReadingVocabularyTest` compara as duas linha a linha — duas metades de um
 * produto a chamar nomes diferentes à mesma coluna é precisamente o que este
 * ficheiro existe para tornar impossível.
 */
final class ReadingVocabulary
{
    /** O indicador FORMAL — o que alimenta a proposta de nível. */
    public const CONTINUOUS = 'Avaliação contínua';

    /** O indicador ANALÍTICO, nomeado curto — para um cabeçalho estreito. */
    public const ACCUMULATED = 'Desempenho acumulado';

    /** O mesmo, por extenso — para onde houver espaço para o dizer inteiro. */
    public const ACCUMULATED_LONG = 'Resultado acumulado dos elementos de avaliação';

    public const CONTINUOUS_EXPLANATION = 'Média dos resultados formais dos períodos/semestres, segundo os pesos configurados. Os momentos intercalares não entram nesta média.';

    public const ACCUMULATED_EXPLANATION = 'Resultado obtido considerando diretamente os elementos de avaliação acumulados até ao momento. É uma leitura complementar: a proposta formal sai da avaliação contínua.';

    /**
     * As duas leituras, na ordem em que se leem — a formal primeiro.
     *
     * A ORDEM É A HIERARQUIA, e não uma preferência de apresentação: a
     * avaliação contínua é o indicador principal, e o desempenho acumulado é
     * complementar. Um ficheiro ou uma legenda que os listasse ao contrário
     * estaria a dizer o oposto ao leitor.
     *
     * @return list<array{name: string, explanation: string}>
     */
    public static function legend(): array
    {
        return [
            ['name' => self::CONTINUOUS, 'explanation' => self::CONTINUOUS_EXPLANATION],
            ['name' => self::ACCUMULATED_LONG, 'explanation' => self::ACCUMULATED_EXPLANATION],
        ];
    }
}
