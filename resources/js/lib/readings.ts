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

/** O mesmo, por extenso — para onde houver espaço para o dizer inteiro. */
export const ACCUMULATED_LONG = 'Resultado acumulado dos elementos de avaliação';

export const CONTINUOUS_EXPLANATION =
    'Média dos resultados formais dos períodos/semestres, segundo os pesos configurados. Os momentos intercalares não entram nesta média.';

export const ACCUMULATED_EXPLANATION =
    'Resultado obtido considerando diretamente os elementos de avaliação acumulados até ao momento. É uma leitura complementar: a proposta formal sai da avaliação contínua.';
