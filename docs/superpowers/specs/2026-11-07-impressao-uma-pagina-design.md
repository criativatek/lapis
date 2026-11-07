# Impressão numa página — grelha de correção, pauta e intercalares

Data: 2026-11-07 · Estado: aprovado em conversa, por rever no ficheiro · Versão alvo: 0.191.0

## 1. Problema

O professor imprime a grelha de correção de um instrumento a partir do browser
(«Imprimir / PDF» → Ctrl+P) e obtém seis páginas: o planeador de colunas
divide as perguntas em blocos, cada bloco sai numa página nova, e no fim sai a
legenda «Questões» com a descrição de cada pergunta. A leitura de conjunto
(todos os alunos × todas as perguntas numa vista) perde-se. No Excel a mesma
grelha cabia numa folha A4 com letra pequena.

A pauta de avaliação imprime o ecrã a 9 pt e não mostra a apreciação
qualitativa dos domínios de forma compacta. A avaliação intercalar não tem
impressão nenhuma.

## 2. Decisões tomadas com o utilizador

- Caminho de impressão alvo: **browser** (Ctrl+P sobre a pré-visualização). O
  PDF do servidor (dompdf) não muda nesta fase.
- Quando não cabe: **encolher até um piso legível de 6 pt**, com colunas e
  linhas tão estreitas quanto os números permitirem e cabeçalhos truncados ou
  em sigla. Abaixo do piso, as **linhas** (alunos) continuam na página seguinte
  com o cabeçalho repetido; as **colunas nunca se partem**.
- Papel: A4 horizontal, margem compacta de **10 mm** (a do `PrintableDocument`).
- Cabeçalho das perguntas: **só o número** («1», «2», «2.1»), nunca «Q1».
- Pauta: a página «Pauta de Avaliação» em si (não o documento «Impressão» da
  pauta), e a **apreciação qualitativa dos domínios sai sempre, em sigla**
  (I, S, B, MB).
- Intercalares: a **tabela do ecrã** (alunos × domínios + menção), numa página.
- Os documentos ganham um modo **«Uma página»**, ligado por defeito e lembrado
  no browser por tipo de documento; desligado, tudo fica como hoje.

## 3. Âmbito

Entra:

1. Utilitário partilhado de ajuste à página (`resources/js/lib/printFit.ts`).
2. Função partilhada de siglas (`resources/js/lib/abbreviations.ts` no cliente;
   `App\Support\Documents\Abbreviations` no servidor, com a mesma regra).
3. Grelha de correção do instrumento (`instruments/GridDocument.vue` +
   `InstrumentGridLayout`, `InstrumentGridOptions`, `InstrumentGrid`).
4. Pauta de avaliação (`evaluation-sheets/Show.vue` + `EvaluationSheetTable.vue`).
5. Avaliação intercalar (`results/InterimAssessment.vue`): impressão nova.
6. `PrintableDocument`: prop `fit` que aplica a escala.

Não entra:

- PDF do servidor compacto (dompdf não escala). Fica como hoje, em blocos.
- Documento «Impressão» da pauta (`evaluation-sheets/GridDocument.vue`): não é
  o que o utilizador imprime. Pode receber o mesmo modo mais tarde, pela mesma
  infraestrutura.
- Quadro-síntese e Estatística: já têm ajuste à largura; não se tocam.
- Comparação entre intercalares e gráficos: ficam fora do papel.

## 4. Ajuste à página (`printFit`)

Funções puras, no modelo de `quadroPrint.ts` e `statisticsPrint.ts`, que
passam a ser casos particulares desta biblioteca (sem alterar o seu
comportamento):

```ts
export const PAPER_MM = { A4: { short: 210, long: 297 }, A3: { short: 297, long: 420 } };
export const COMPACT_MARGIN_MM = 10;
export const PX_PER_MM = 96 / 25.4;

/** Área útil em px CSS, para o papel/orientação e a margem compacta. */
export function usableAreaPx(paper: 'A4' | 'A3', orientation: 'portrait' | 'landscape'): { width: number; height: number };

/** Base 7,5 pt e piso 6 pt ⇒ escala mínima 0,8. */
export const BASE_FONT_PT = 7.5;
export const FLOOR_FONT_PT = 6;
export const FLOOR_SCALE = FLOOR_FONT_PT / BASE_FONT_PT;

export type FitInput = {
    tableWidth: number;   // largura natural da tabela, px
    tableHeight: number;  // altura natural da tabela, px
    headerHeight: number; // cabeçalho do documento (título, meta, legenda), px
    usable: { width: number; height: number };
};

export type FitResult = {
    scale: number;        // o que se aplica (≤ 1, ≥ FLOOR_SCALE)
    fitsOnePage: boolean; // verdadeiro quando, com esta escala, cabe em altura
    pages: number;        // estimativa de páginas quando não cabe (≥ 1)
};

export function fitToPage(input: FitInput): FitResult;
export function fitNotice(result: FitResult): string | null;
```

Regras:

- `scale = min(1, widthScale, heightScale)`, arredondada por baixo ao milésimo.
- Se `scale < FLOOR_SCALE`: `scale = FLOOR_SCALE`; `fitsOnePage = false`;
  `pages = ceil(tableHeight·scale / (usable.height − headerHeight))`.
- Medições inválidas (0, NaN, negativas) devolvem `{ scale: 1, fitsOnePage: true, pages: 1 }`.
- `fitNotice`: `null` quando cabe a 100 %; «Imprime-se a N % para caber numa
  página.» quando cabe com escala < 1; «A 6 pt ainda não cabe numa página: sai
  em N páginas.» quando não cabe. O aviso aparece **no ecrã** (classe
  `print-hide`), nunca no papel.

Aplicação:

- `PrintableDocument` ganha a prop `fit?: number | null`. Quando definida, põe
  `--document-print-zoom: <scale>` no elemento raiz; o CSS de impressão do
  componente aplica `zoom: var(--document-print-zoom, 1)` ao elemento com a
  classe `document-print-fit` (a tabela e a sua legenda), nunca ao cabeçalho.
- Cada página mede no `onMounted` e num `watch` das opções que mudam a tabela,
  com a técnica do Quadro (anular `min-width` para obter a largura natural),
  mais `offsetHeight` para a altura. Em jsdom as medições são 0 e o resultado
  é escala 1 — as páginas não fazem aritmética.
- Em `@media print`, `thead { display: table-header-group }` (já existe) repete
  o cabeçalho quando as linhas transbordam; `tr { break-inside: avoid }` já
  existe.

## 5. Siglas (`abbreviations`)

Uma regra, em TypeScript e em PHP, com os mesmos casos de teste:

- `mentionSigla(label)`: iniciais das palavras com maiúscula inicial, sem
  partículas («de», «e», «do»): «Muito Bom» → «MB», «Insuficiente» → «I»,
  «Bom» → «B», «Suficiente» → «S», «Não Satisfaz» → «NS».
- `siglasUnicas(labels, codes)`: se duas menções derem a mesma sigla, **todas**
  as menções da escala usam o código da escala («1»…«5») em vez da sigla.
- `domainSigla(name)`: uma palavra → primeiras 4 letras com ponto («Oralidade»
  → «Oral.», «Leitura» → «Leit.», «Escrita» → «Escr.»); várias palavras →
  iniciais com ponto («Educação Literária» → «Ed. Lit.», «Gramática» → «Gram.»).
  Colisão entre domínios → acrescenta letras até distinguir.
- `groupSigla(label)`: numeral romano ou árabe presente no rótulo → «G.» +
  numeral («GRUPO V - ESCRITA» → «G.V», «Grupo 2» → «G.2»); sem numeral →
  iniciais até 4 letras («Expressão escrita» → «EE»). Grupo implícito (sem
  rótulo) → sem linha de grupo.
- `questionNumber(code)`: retira o prefixo «Q», «Item », «Pergunta », «P»
  seguidos de dígito: «Q12» → «12», «Item 3» → «3», «2.1» → «2.1», «A» → «A».
- Colunas-resumo (fixas): «Pontuação bruta» → «Bruta», «Resultado» → «Res.»,
  «Nível» → «Nív.», «Média ponderada» → «Média», «Nível atribuído» → «Nível».

Toda a sigla que não seja óbvia entra na **legenda de uma linha** por baixo da
tabela, no formato «sigla = texto completo», separada por « · ». A legenda
imprime-se; faz parte da tabela para efeitos de escala.

## 6. Grelha de correção do instrumento

Servidor:

- `InstrumentGridOptions` ganha `layout: 'blocos' | 'uma_pagina'` (query
  `disposicao=uma-pagina|blocos`, por defeito `uma_pagina` **só na
  pré-visualização**; o PDF usa sempre `blocos`).
- `InstrumentGridLayout::plan()` ganha `$layout`. Com `uma_pagina` devolve
  `orientation = landscape` e **um só bloco** com todas as perguntas e todas as
  colunas-resumo, sem consultar larguras.
- `InstrumentGrid` passa a incluir, por bloco, `header_short` (número da
  pergunta) e, por grupo e coluna-resumo e domínio, `short` + a lista
  `abbreviations: list<{short, long}>` para a legenda. A legenda «Questões»
  (`legend`) fica `false` por defeito em `uma_pagina`; se o professor a ligar,
  sai numa página própria no fim (`break-before: page`).

Cliente (`instruments/GridDocument.vue`):

- Seletor «Disposição: Uma página / Blocos» ao lado de «Orientação», lembrado em
  `localStorage` (`lapis:print-layout:instrument-grid`). Em «Uma página» o
  seletor de orientação fica inativo (é sempre horizontal).
- Tabela compacta: `font-size: 7.5pt`, `padding: 1pt 2pt`, colunas de pergunta
  `width: 3ch`, nome do aluno `max-width: 24ch` com `text-overflow: ellipsis`,
  `line-height: 1.15`, bordas de 0,5 pt. Os cabeçalhos mostram `header_short`;
  a linha de grupos mostra a sigla; a linha «Cotação» mantém-se.
- Gráficos: como hoje (desligados por defeito; quando ligados, depois da tabela,
  fora da escala).
- A legenda das siglas por baixo da tabela.

## 7. Pauta de Avaliação (`evaluation-sheets/Show.vue`)

- `EvaluationSheetTable` ganha a prop `printCompact` (booleana). No ecrã nada
  muda. No papel, com `printCompact`:
  - Cada domínio tem **uma** célula por aluno: a sigla da apreciação (a negrito
    se for decisão do professor) e, se o toggle «Quantitativo» estiver ligado,
    o valor antes dela: «78 % · B». A apreciação sai **mesmo com o detalhe dos
    domínios desligado** no ecrã.
  - Autoavaliação: como hoje, só com o toggle ligado (o expoente «A4» mantém-se).
  - Cabeçalhos: domínios em sigla; «Média ponderada» → «Média»; «Nível
    atribuído» → «Nível».
  - Letra 7,5 pt, preenchimento 1 pt 2 pt, nome truncado a 24 caracteres.
- O parágrafo explicativo longo recebe `print-hide`; em seu lugar sai a legenda
  das siglas (menções e domínios) numa linha.
- `Show.vue` mede e aplica o `fit` do `PrintableDocument`; mostra o aviso de
  escala junto ao botão «Imprimir».
- Modo «Uma página» ligado por defeito (`lapis:print-layout:evaluation-sheet`);
  desligado, o papel sai como hoje (9 pt, sem siglas).

## 8. Avaliação intercalar (`results/InterimAssessment.vue`)

- Botão «Imprimir» na barra de ações da página.
- Um `PrintableDocument orientation="landscape" compact :fit="…"` que só é
  visível no papel contém:
  - Cabeçalho: «Avaliação intercalar — {turma}», disciplina · ano letivo,
    «Referência: {reference_date_label}», «Impresso em {data}».
  - Tabela: n.º · nome · uma coluna por domínio («78 % · B») · «Menção» (código
    e sigla). Cobertura parcial → asterisco após o valor e nota de rodapé «*
    cobertura parcial».
  - Legenda das siglas.
- Tudo o resto da página (controlos, comparação, gráficos) fica fora do papel
  com as regras do `PrintableDocument`.
- Sem servidor: o retrato (`snapshot`) já traz tudo. A sigla da menção do
  domínio sai de `mention.label_snapshot`; a global de `band`.

## 9. PDF do servidor

Sem alterações funcionais. `InstrumentGridDocumentController::pdf` força
`layout = blocos`. Fica registado em `docs/reports-class-documents.md` que o
modo «Uma página» é do browser.

## 10. Testes

- `printFit.test.ts`: largura, altura, ambos, piso, arredondamento, medições
  inválidas, estimativa de páginas, textos do aviso.
- `abbreviations.test.ts` e `tests/Unit/Documents/AbbreviationsTest.php`: os
  casos da secção 5, iguais nos dois lados.
- `tests/Unit/Reporting/InstrumentGridLayoutTest.php`: `uma_pagina` → um bloco
  com todas as colunas; `blocos` inalterado.
- `tests/Feature/Reporting/InstrumentGridDocumentTest.php`: o documento traz
  `header_short`, siglas e legenda; o PDF ignora `disposicao`.
- `GridDocument.test.ts`, `Show.vue`/`EvaluationSheetTable` tests,
  `InterimAssessment.test.ts` (novo): variável de escala aplicada, seletor
  lembrado, siglas e legenda presentes, parágrafo longo escondido no papel,
  apreciação impressa com o detalhe desligado, documento da intercalar montado.
- `printStyles.test.ts`: continua a proibir `@page` estático e `body *`.
- QA manual no browser isolado (`lapispro-qa-browser-isolado`): instrumento com
  30 perguntas em 5 grupos e 28 alunos; pauta com 5 domínios; uma intercalar.
  Capturas da pré-visualização de impressão em A4 horizontal.

## 11. Entrega

Três PRs empilhadas sobre `main`, cada uma com CI verde:

1. `printFit` + `abbreviations` + prop `fit` do `PrintableDocument` + grelha de
   correção (0.191.0).
2. Pauta (0.191.1).
3. Intercalares (0.191.2).

Deploy só de código, sem migrations, com autorização expressa.
