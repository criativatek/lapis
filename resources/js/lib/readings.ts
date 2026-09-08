/**
 * AS PALAVRAS DAS DUAS LEITURAS DO ANO, do lado do browser.
 *
 * A CÓPIA CANÓNICA É `App\Support\Assessment\ReadingVocabulary`, em PHP, porque
 * o servidor também as escreve — a folha «Configuração» do Excel leva a legenda
 * inteira. `ReadingVocabularyTest` compara as duas, linha a linha: duas metades
 * de um produto a chamar nomes diferentes à mesma coluna é o que estas duas
 * cópias existem para tornar impossível.
 *
 * PORQUE HÁ DUAS EM VEZ DE UMA. A alternativa era mandar seis strings do
 * servidor em cada `Inertia::render` de cada ecrã que fala delas — mais tráfego,
 * mais props, e uma dependência nova entre um controlador e um rótulo. Duas
 * cópias com um teste a compará-las custa menos e falha mais alto.
 *
 * O QUE CADA UMA SIGNIFICA, porque a diferença é a razão de existirem:
 *
 *  AVALIAÇÃO CONTÍNUA — o indicador FORMAL. A média (ou média ponderada,
 *  conforme a configuração) dos RESULTADOS FORMAIS de cada unidade temporal. É
 *  daqui que sai a proposta formal de nível. Os momentos intercalares nunca
 *  entram.
 *
 *  DESEMPENHO ACUMULADO — o indicador ANALÍTICO. O resultado obtido
 *  considerando diretamente os elementos de avaliação acumulados até ao
 *  momento. Não é o indicador formal, e não tem o mesmo destaque.
 */

/** O indicador FORMAL — o que alimenta a proposta de nível. */
export const CONTINUOUS = 'Avaliação contínua';

/** O indicador ANALÍTICO, nomeado curto — para um cabeçalho estreito. */
export const ACCUMULATED = 'Desempenho acumulado';

/**
 * O MESMO NOME, ENCURTADO PARA UMA COLUNA QUE NÃO TEM LARGURA PARA ELE.
 *
 * «Desemp.» sozinho não diz desempenho de quê — pode ser lido como o desempenho
 * do período, que é outro número na mesma linha — e «Acum.» é precisamente o
 * nome que a distinção entre as duas leituras mandou abandonar. Abrevia-se a
 * segunda palavra e mais nada; o nome inteiro e a explicação seguem sempre no
 * `title` e no texto acessível.
 */
export const ACCUMULATED_SHORT = 'Desemp. acum.';

/** O mesmo, por extenso — para onde houver espaço para o dizer inteiro. */
export const ACCUMULATED_LONG = 'Resultado acumulado dos elementos de avaliação';

/**
 * O BLOCO QUE FECHA O ANO, nomeado por inteiro.
 *
 * «Avaliação contínua» é a LEITURA; «Avaliação Contínua Final» é o sítio da
 * grelha onde ela responde pelo ano — o global e cada domínio. Ter o nome numa
 * constante é o que impede o ecrã e o Excel de lhe chamarem coisas diferentes.
 */
export const CONTINUOUS_FINAL = 'Avaliação Contínua Final';

/**
 * «FINAL» SOZINHO NÃO DIZIA DE QUE É QUE ERA O FINAL.
 *
 * Uma coluna chamada apenas «Final», encostada ao desempenho acumulado dentro
 * do bloco de um domínio, lê-se como «o último valor» — e não é isso: é a média
 * ponderada dos resultados formais do ano naquele domínio. O nome tem de
 * afirmar as duas coisas de que depende ser entendido: que é uma MÉDIA e que é
 * FINAL.
 */
export const FINAL_AVERAGE = 'Média final';

export const FINAL_AVERAGE_EXPLANATION =
    'Média ponderada final deste domínio, calculada a partir dos resultados formais dos períodos/semestres.';

/**
 * «Aprec.» era uma abreviatura que não se dizia sozinha.
 *
 * O que a célula mostra é a banda da escala que vale no fim do ano — uma
 * menção. E «final» distingue-a da menção do desempenho acumulado, que vive no
 * bloco do domínio e responde a outra pergunta.
 */
export const FINAL_MENTION = 'Menção final';

export const CONTINUOUS_EXPLANATION =
    'Média dos resultados formais dos períodos/semestres, segundo os pesos configurados. Os momentos intercalares não entram nesta média.';

export const ACCUMULATED_EXPLANATION =
    'Resultado obtido considerando diretamente os elementos de avaliação acumulados até ao momento. É uma leitura complementar: a proposta formal sai da avaliação contínua.';

/**
 * A FRASE QUE DESFAZ A CONFUSÃO QUE O NÚMERO PROVOCA — 68 % num semestre, 25 %
 * no outro, 60 % no acumulado. Vive ao lado da conta que a demonstra.
 */
export const ACCUMULATED_NOT_AN_AVERAGE =
    'Este valor é calculado diretamente a partir das cotações dos elementos de avaliação acumulados ao longo do ano. Não é a média dos semestres.';

/** As duas leituras contrastadas numa frase, para onde as duas aparecem juntas. */
export const ACCUMULATED_VERSUS_CONTINUOUS =
    'A Avaliação Contínua usa os pesos formais dos períodos/semestres; o Desempenho acumulado usa diretamente os elementos de avaliação.';
