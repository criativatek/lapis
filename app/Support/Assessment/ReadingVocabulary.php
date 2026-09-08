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

    /**
     * O MESMO NOME, ENCURTADO PARA UMA COLUNA QUE NÃO TEM LARGURA PARA ELE.
     *
     * «Desemp.» sozinho não diz desempenho de quê — pode ser lido como o
     * desempenho do período, que é outro número na mesma linha. E «Acum.» é
     * precisamente o nome que a distinção entre as duas leituras mandou
     * abandonar. O que aqui se abrevia é a segunda palavra e mais nada, para
     * que a coluna continue a afirmar as duas coisas de que depende ser
     * entendida: que é DESEMPENHO e que é ACUMULADO. O nome inteiro e a
     * explicação vão sempre no `title` e no texto acessível — a abreviatura
     * nunca é a única informação (§25).
     */
    public const ACCUMULATED_SHORT = 'Desemp. acum.';

    /** O mesmo, por extenso — para onde houver espaço para o dizer inteiro. */
    public const ACCUMULATED_LONG = 'Resultado acumulado dos elementos de avaliação';

    /**
     * O BLOCO QUE FECHA O ANO, nomeado por inteiro.
     *
     * «Avaliação contínua» é a LEITURA; «Avaliação Contínua Final» é o sítio
     * onde ela responde pelo ano — o global e cada domínio. O ecrã e a folha do
     * Excel escrevem os dois a partir daqui, e por isso não podem divergir.
     */
    public const CONTINUOUS_FINAL = 'Avaliação Contínua Final';

    /**
     * «FINAL» SOZINHO NÃO DIZIA DE QUE É QUE ERA O FINAL.
     *
     * Uma coluna chamada apenas «Final», encostada ao desempenho acumulado
     * dentro do bloco de um domínio, lê-se como «o último valor» — e não é
     * isso: é a média ponderada dos resultados formais do ano naquele domínio.
     * O nome tem de afirmar que é uma MÉDIA e que é FINAL.
     */
    public const FINAL_AVERAGE = 'Média final';

    public const FINAL_AVERAGE_EXPLANATION = 'Média ponderada final deste domínio, calculada a partir dos resultados formais dos períodos/semestres.';

    /**
     * «Aprec.» era uma abreviatura que não se dizia sozinha.
     *
     * O que a célula mostra é a banda da escala que vale no fim do ano — uma
     * menção. E «final» distingue-a da menção do desempenho acumulado, que
     * responde a outra pergunta no bloco do domínio.
     */
    public const FINAL_MENTION = 'Menção final';

    public const CONTINUOUS_EXPLANATION = 'Média dos resultados formais dos períodos/semestres, segundo os pesos configurados. Os momentos intercalares não entram nesta média.';

    public const ACCUMULATED_EXPLANATION = 'Resultado obtido considerando diretamente os elementos de avaliação acumulados até ao momento. É uma leitura complementar: a proposta formal sai da avaliação contínua.';

    /**
     * A FRASE QUE DESFAZ A CONFUSÃO QUE O NÚMERO PROVOCA.
     *
     * Um professor que veja 68 % num semestre, 25 % no outro e 60 % no
     * acumulado tem à frente um número que não é a média de dois. Esta é a
     * frase que diz porquê, e vive ao lado da conta que a demonstra.
     */
    public const ACCUMULATED_NOT_AN_AVERAGE = 'Este valor é calculado diretamente a partir das cotações dos elementos de avaliação acumulados ao longo do ano. Não é a média dos semestres.';

    /** As duas leituras contrastadas numa frase, para onde as duas aparecem juntas. */
    public const ACCUMULATED_VERSUS_CONTINUOUS = 'A Avaliação Contínua usa os pesos formais dos períodos/semestres; o Desempenho acumulado usa diretamente os elementos de avaliação.';

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
