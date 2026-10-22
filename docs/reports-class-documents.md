# Documentos da turma — gerados na hora

Relatórios tem, desde a 0.173.0, duas famílias de documentos. Este ficheiro
descreve a segunda e explica porque é que ela **não** é um `Report`. Tem hoje
três documentos: o **Mapa de Medidas Educativas** (0.173.0), a **grelha
imprimível de um instrumento de avaliação** (0.174.0) e a **grelha factual de
avaliação da turma** — a Pauta como documento (0.175.0).

| | Relatório (`reports`) | Documento da turma |
|---|---|---|
| O que é | Um objeto com vida: rascunho → finalizado → derivado ([ADR-0005](adr/0005-reports-are-documents-not-downloads.md)) | Uma leitura factual dos registos, no momento em que se abre |
| Guarda alguma coisa? | Sim — secções, texto do professor e, ao finalizar, um `document` congelado | **Não.** Nenhuma linha em `reports`, nenhum PDF guardado |
| Texto | Frases compostas pelo sistema, editadas pelo professor | Nenhuma frase interpretativa; só listas, contagens, valores e notas factuais |
| Formatos | Pré-visualização, impressão, PDF, Word | Pré-visualização, impressão, PDF |
| Exemplo | Relatório de turma, individual, por registos, de escola | **Mapa de Medidas Educativas** (0.173.0); **grelha de um instrumento** (0.174.0); **grelha da turma** (0.175.0) |

**Imprimir agora não é finalizar.** Um documento da turma leva impressa a data
de emissão e a frase «Documento gerado a partir dos registos à data de emissão;
não é um relatório finalizado.» Abrir a pré-visualização não grava nada. Quem
precisa de um documento congelado cria um relatório — a regra do snapshot
(ADR-0005 §4) continua a ser só dos relatórios, e não foi tocada.

## Uma estrutura, três renderizações

O princípio é o do ADR-0005 §5: **um builder produz uma estrutura neutra**, e o
ecrã, a impressão do browser e o PDF leem essa estrutura e nada mais. Uma
diferença entre o PDF e o ecrã teria de ser uma diferença entre renderizadores,
nunca entre duas ideias do que o documento diz.

```
ClassMeasuresMap::build()  ──►  props Inertia  ──►  MeasuresMap.vue  (pré-visualização + window.print)
                           └─►  reports/class-measures-map.blade.php  ──►  DompdfPrinter  ──►  PDF

InstrumentGrid::build()    ──►  props Inertia  ──►  instruments/GridDocument.vue  (idem)
                           └─►  instruments/grid-document.blade.php  ──►  DompdfPrinter  ──►  PDF

EvaluationSheetGrid::build() ──►  props Inertia  ──►  evaluation-sheets/GridDocument.vue  (idem)
                             └─►  evaluation-sheets/grid-document.blade.php  ──►  DompdfPrinter  ──►  PDF
```

As frases que aparecem nos dois lados (o resumo, a nota dos excluídos, o
critério, o aviso «não é um relatório finalizado») e os títulos das colunas
(`MeasureMapColumn::label()`) são compostos **no servidor**, uma vez, para que o
ecrã e o PDF não possam pluralizar nem titular de maneira diferente.

## Infraestrutura comum (extraída com utilização real)

Só se extraiu o que já tem dois utilizadores — os relatórios e o mapa — e que as
grelhas seguintes vão precisar.

- **`App\Services\Documents\Pdf\DompdfPrinter`** — o núcleo dompdf que vivia em
  `PdfRenderer`: opções isoladas (sem rede, `chroot` no disco privado, DejaVu),
  papel A4 e a paginação «Página N / T» desenhada depois do render (ver ADR-0005
  §5 sobre `counter(pages)`). `PdfRenderer` passou a usá-lo; o PDF dos
  relatórios é o mesmo.
- **`App\Services\Documents\Pdf\PaperOrientation`** — retrato ou paisagem. A
  posição do número de página acompanha a orientação. O mapa usa retrato; a
  grelha de um instrumento pede paisagem quando é larga, sem tocar no motor.
- **Timbre partilhado** — `resources/views/documents/partials/letterhead*.blade.php`,
  incluídos por `reports/document.blade.php` e pelo mapa. Um só timbre para
  todos os documentos impressos: as correções de espaçamento do cabeçalho
  (SUP-DTQLDG) não podem voltar a divergir entre dois ficheiros.
- **`resources/js/components/documents/PrintableDocument.vue`** — o gancho de
  impressão da pré-visualização, com `@page` a seguir a prop `orientation`.
  **Não** usa o truque `position: absolute; inset: 0` do relatório e da Pauta:
  esse prende a altura da folha à do `<main>` do layout (altura própria e
  `overflow`) e corta tudo o que passa da primeira página. Aqui a folha fica no
  fluxo, o que não a contém sai com `display: none` e os contentores dela deixam
  de cortar — tudo preso a `body:has(.document-print)`, para nada vazar para a
  impressão de outras páginas (um `<style>` de componente fica carregado depois
  de a página sair).

  *Resolvido na 0.173.1:* «Imprimir» em `reports/Show.vue` imprimia só a
  primeira página de um relatório longo e a Pauta usava o mesmo padrão. Ambas
  passaram a usar este componente (a Pauta com `orientation="landscape"
  compact`), sem as regras `body *`/`inset: 0`/`@page` próprias; o componente
  repõe a visibilidade da folha e reescreve o `@page` quando a orientação muda.

  *Impressão do browser da grelha de um instrumento (verificada em Chromium):*
  com `stamp` e `pageNumbers` o componente escreve o selo («PROVISÓRIO») e
  «Página N / T» nas **caixas de margem do `@page`**, que saem em todas as
  folhas, a última incluída; um `position: fixed` no corpo repetia-se mas
  falhava a última, e um selo só no corpo saía só na primeira. **Limitação:** só
  o Chromium recente desenha caixas de margem; noutros browsers o documento
  imprime-se na mesma, com o selo apenas na 1.ª folha e sem numeração. O PDF do
  servidor garante as duas coisas em todas as páginas e é o caminho fiável (em
  particular no telemóvel). Cabeçalhos da tabela repetidos, blocos de colunas
  numa folha cada e resultados completos não precisaram de correção.
  `resources/js/lib/printStyles.test.ts` impede o regresso (nenhum destes
  ficheiros pode voltar a ter `body *` solto, `position: absolute`/`inset: 0`
  em `@media print` nem `@page` estático). O PDF do servidor não tinha o
  problema.
- **`LetterheadLayout`** (`Standard`, `Compact`) — as medidas do timbre (margem
  da página, faixa do timbre, rodapé, posição de «Página N / T»), lidas pelo
  parcial Blade e pelo `DompdfPrinter`. `Standard` é o que os relatórios e o
  mapa usam; `Compact` (margem 26/14/16/14 mm) é o da grelha de um instrumento em
  folha horizontal (0.174.0).

O que **não** se criou: um motor genérico de «documentos», um modelo de snapshot
novo, um tipo de relatório novo. Os gráficos existem só na grelha de um
instrumento (ver adiante), como dados compostos no servidor.

## Mapa de Medidas Educativas

`GET reports/turma/mapa-de-medidas` (escolher a turma),
`GET reports/turma/{class}/mapa-de-medidas` (o mapa) e `…/pdf`. Dentro de
`module:reports` **e** `module:interventions` — ambos Base, porque listar o que
está registado é «Base regista e mostra». Autorização: `SchoolClassPolicy::view`
(leciona a turma); uma turma de outra organização é 404 pela binding e pelo
global scope.

### Implementada / a implementar — a leitura dos estados reais

Não existe, e não foi criado, um estado «implementada». O mapa lê
`InterventionStatus` através de `MeasureMapColumn::forStatus()`, um `match`
exaustivo — um estado novo obriga a decidir em que coluna cai.

| Estado (`InterventionStatus`) | Rótulo | Coluna do mapa | Porquê |
|---|---|---|---|
| `new` | Planeada | **A implementar** | O docblock do enum: «something decided and not yet under way» |
| `in_progress` | Em curso | **Implementadas** | Está a ser aplicada |
| `concluded` | Concluída | **Implementadas** | Foi aplicada até ao fim |
| `suspended` | Suspensa | **Implementadas** | Foi aplicada e está em pausa; o rótulo «Suspensa» aparece ao lado |
| `cancelled` | Cancelada | — (fora do mapa) | Abandonada; estado legado que já não é oferecido |

**Limitação conhecida, assumida e visível.** Todos os caminhos de criação gravam
`new`: o formulário de Estratégias e Medidas, «Adicionar medida» na
Caracterização e a importação da caracterização. Uma medida que já está a ser
aplicada aparece em «A implementar» até alguém a marcar «Em curso». O mapa não
infere nada pela data (`started_on` no passado não prova aplicação) e não
reescreve registos: mostra o estado registado, diz o critério no próprio
documento e liga diretamente a Estratégias e Medidas para o corrigir.

### O que entra

- **Âmbito:** a turma (`SchoolClass`, que já fixa o ano letivo). Não há filtro
  por período: o estado de uma medida é o de hoje, e `academic_period_id` não é
  escrito por nenhum caminho atual.
- **Alunos:** os ativos da turma (`activeEnrollments()`, a mesma base do
  «total de alunos» do relatório de turma), na ordem canónica `class_number`.
  Só aparecem os que têm pelo menos uma medida numa coluna mostrada, uma vez
  cada.
- **Medidas:** `available_for_reports = true` (a escolha do professor sobre o
  que sai da sala de aula, a mesma que o relatório de turma respeita), estado
  fora de `cancelled`, não apagadas. As indisponíveis são **contadas**, nunca
  listadas — e só as que teriam chegado a este mapa (turma inteira ou um aluno
  ainda ativo), para a nota não anunciar uma omissão que não o é.
- **Destinatário:** `target_type`, nunca `enrollment_id IS NULL` (que também é o
  valor dos grupos). Turma inteira → bloco próprio, uma vez, e não conta alunos
  «com medidas». Grupo → em cada participante ainda ativo, assinalado «em
  grupo». Aluno que saiu → não aparece.
  *Dívida encontrada, fora desta fatia:* `StudentReportSource::interventionFacts()`
  e `BuildStudentProgress` ainda leem `enrollment_id IS NULL` como «turma
  inteira», pelo que uma medida de **grupo** aparece no relatório individual e
  no Acompanhamento de qualquer aluno da turma, mesmo de quem não está no grupo.
  O mapa não herda esse defeito; corrigi-lo é trabalho próprio.
- **Aluno em várias turmas:** cada medida pertence a uma `class_id`; o mapa de
  uma turma nunca lê medidas de outra, incluindo turmas de apoio.
- **Designação: só nomes do catálogo, nunca texto do professor.**
  `Intervention::typeLabel()` (snapshot primeiro — uma medida importada sem tipo
  próprio guarda aí a designação legal). Um «Outro» sai «Outra medida» e uma
  linha sem tipo «Medida sem tipo registado» (a regra do relatório de turma: um
  registo sem tipo conta-se, não se cita). **Não** se usa `pedagogicalTitle()`:
  lê primeiro a estratégia e o título em texto livre, e uma estratégia pode dizer
  porque é que uma criança precisa dela. Nunca o `title` cru (ver
  [legal-frameworks.md](legal-frameworks.md), «A designação do tipo é
  estampada»). Nas medidas de suporte, o nível **guardado** (nunca recalculado).

### O que não entra (minimização)

Nome e número na turma, e mais nada sobre o aluno: sem data de nascimento,
número de processo, fotografia, blind index, ids ou ULIDs. Das medidas: sem
descrição, motivo, objetivo, estratégia em texto livre, acompanhamentos,
eficácia, datas, domínio ou autor. Os nomes só são desencriptados para os
alunos que aparecem no mapa. Nenhuma IA é chamada.

### Contagens

«N implementadas / N a implementar» contam **medidas distintas** presentes no
documento (alunos e turma inteira): uma medida de grupo com três alunos conta
uma vez. O número de consultas é constante — não depende do número de alunos
nem de medidas (testado).

## Grelha imprimível de um instrumento (0.174.0)

A grelha de um elemento de avaliação (teste, ficha, trabalho…) como documento
para ver, imprimir e guardar em PDF: um aluno por linha, uma coluna por questão,
e a pontuação bruta, o resultado, o nível e os domínios no fim. Gerada na hora,
nunca guardada.

### Rotas, acessos e quem pode abrir

Dentro de `module:instruments` + `usage:assessment` — Base, os três planos:

| Rota | O quê |
|---|---|
| `GET instruments/{instrument}/impressao` | Pré-visualização (`instruments.grid-document`, página `instruments/GridDocument`) |
| `GET instruments/{instrument}/impressao/pdf` | O mesmo documento em PDF (`instruments.grid-document.pdf`) |

**Não** é `/grelha`: essa é a folha de cálculo que o professor preenche offline
(«Descarregar grelha»). O acesso é «Imprimir / PDF», nos separadores da grelha de
correção e de Resultados (não aparece num elemento anulado).

- **Autorização** igual à da grelha de correção e de Resultados:
  `SchoolClassPolicy::view` (leciona a turma). Um colega da mesma organização que
  não leciona a turma tem 403; um elemento de outra organização é 404 pela
  binding e pelo global scope; módulo bloqueado é 403. São GET, por isso
  funcionam em modo só de leitura. A personificação pelo suporte está sujeita à
  mesma policy, e a exportação fica atribuída a quem personifica
  (`acting_as_user_id` na auditoria).
- **Onde não se aplica.** Um rascunho vai para o editor; um elemento anulado
  volta à sua página, com a razão — o mesmo para o PDF.
- **Provisório e concluído.** Qualquer elemento com grelha pode ser impresso;
  enquanto a correção não estiver concluída (`InstrumentStatus::isConcluded()`:
  concluído ou publicado — arquivado **não**) o documento leva o selo
  «PROVISÓRIO» e não tem gráficos. Reabrir a correção volta a torná-lo
  provisório.

### Uma só estrutura

```
InstrumentGrid::build()  ──►  props Inertia  ──►  instruments/GridDocument.vue  (pré-visualização + window.print)
                         └─►  instruments/grid-document.blade.php  ──►  DompdfPrinter  ──►  PDF
```

O princípio é o do mapa: **um builder produz uma estrutura neutra** e as duas
vistas só a desenham — a página recebe-a sem lhe mexer (um teste compara-a, ao
byte, com a do builder) e o Blade não lê mais nada. Todos os textos (cabeçalhos,
números formatados, abreviaturas, avisos, notas, legendas dos gráficos) são
compostos no servidor. Peças, em `App\Services\Reporting\ClassDocuments`:

- `InstrumentGrid` — monta o documento.
- `InstrumentGridOptions` — as opções, lidas da query contra uma lista branca.
- `InstrumentGridLayout` — função pura: orientação e blocos de colunas.
- `InstrumentGridCharts` — os gráficos, como dados.
- `InstrumentGridUnavailable` — rascunho ou anulado.

### O que mostra

| Coluna | Conteúdo |
|---|---|
| Nº, Nome | Número na turma (sem número: «—») e nome; só dos alunos a quem o elemento se aplica |
| Uma por questão | Pontos, ou o estado; no cabeçalho o código, a cotação («Cotação») e, se houver grupos com nome, o grupo — o mesmo código em dois grupos distingue-se |
| Pontuação bruta | Soma dos pontos das questões **avaliadas** sobre a cotação total |
| Resultado, Nível | O resultado do motor e a banda da escala (sem bandas, sem coluna «Nível») |
| Um por domínio | Só quando o elemento toca dois ou mais domínios (com um, é igual ao global) |

- **Estados** compactos, compostos no servidor: «—» = por avaliar (**não é
  zero**), `Aus`, `AusJ`, `Disp`, `N/A`, `Anul`, `Rev`; a legenda só lista os que
  aparecem. Uma linha parcialmente avaliada leva `*`, explicado numa nota.
- **A pontuação bruta é a única grandeza nova** e é calculada no servidor, em
  aritmética decimal (`Bc`): sem nada avaliado é «—», nunca zero; não há
  percentagem dela (seria uma segunda percentagem a divergir do motor). **Não é o
  resultado:** com dois ou mais domínios, ou com questões sem domínio, o valor do
  motor difere de pontos ÷ cotação de propósito, e o documento di-lo numa nota.
- **Resultado, nível e domínios** são as células do motor
  (`BuildResultsAnalysis::officialCellsFor`) — as mesmas da grelha de correção e
  de Resultados, formatadas como o ecrã as formata (uma casa decimal, ou as duas
  truncadas quando o arredondamento mudaria o nível). Nada se recalcula. O
  rótulo no papel é **«Resultado»**, com a nota «não é a classificação do
  período» (a grelha de correção diz «Classificação oficial/provisória»;
  proposta e classificação final não existem por instrumento e não se mostram).
- **Quem aparece.** Os alunos a quem o instrumento se aplica; os que o motor dá
  como `out_of_scope` (entraram depois, saíram antes, não frequentavam a
  disciplina) ficam de fora **e são contados** («N alunos não abrangidos…»).
- **Diagnóstico** vem identificado, com a nota de que não conta para as médias.

### Opções

Na query string, normalizadas no servidor: um valor em falta, repetido ou forjado
é o valor por omissão — nunca um erro nem uma coluna a mais. Nenhuma muda um
número; só escolhem o que se desenha. As questões estão sempre presentes.

| Parâmetro | Opção | Omissão |
|---|---|---|
| `bruta` | Pontuação bruta | ligada |
| `resultado` | Resultado e nível | ligada |
| `dominios` | Resultados por domínio (só com ≥ 2 domínios) | ligada |
| `legenda` | Legenda das questões | ligada |
| `graficos` | Gráficos (só com a correção concluída) | **desligada** |
| `orientacao` | `auto`, `vertical` ou `horizontal` | `auto` |

### Orientação e blocos de colunas

O dompdf **não pagina na horizontal**: uma coluna que passa a margem não vai para
outra página, perde-se sem aviso. Por isso o corte em blocos está na estrutura
(`InstrumentGridLayout::plan()`), e o ecrã e o PDF cortam no mesmo sítio.

- **Automática:** vertical enquanto tudo cabe num bloco, horizontal caso contrário;
  uma orientação escolhida substitui-a e os blocos recalculam-se para essa largura.
- **Blocos:** cada um repete «Nº» e «Nome»; as questões repartem-se pelo menor
  número de blocos, por igual, e cada corte desloca-se para uma fronteira de grupo
  próxima quando existe; o resumo (bruta, resultado, nível, domínios) segue no
  último, ou, se não couber ao lado das questões, num bloco só dele. Um grupo
  mais comprido do que um bloco parte-se. Cada bloco leva «Parte i de n».
  **Nenhuma questão nem coluna se perde** (propriedade testada de 1 a 90
  questões).
- As **larguras** (Nº 7, Nome 40, questão 9,5, bruta 16, resultado 14, nível 18,
  domínio 16 mm) são estimativas calibradas com PDFs reais; usam a largura útil
  com a margem `Standard` do timbre, o que as torna seguras também com a `Compact`.

### Pré-visualização e impressão do browser

`instruments/GridDocument.vue` sobre `PrintableDocument` (horizontal e compacto
quando o papel é horizontal). Cada alteração das opções volta a pedir o
documento ao servidor. O botão «PDF» é uma ligação normal (uma resposta binária
não passa pelo Inertia) com as mesmas opções que a pré-visualização mostra.

A impressão do browser foi verificada em Chromium com instrumentos curtos e de
60 questões × 30 alunos, em vertical e horizontal: questões, alunos, grupos e
resultados completos, cabeçalho da tabela repetido, uma parte por folha, nada
fora da folha, e nenhuma impressão em branco ao navegar entre relatório, Pauta,
Mapa e grelha. O selo «PROVISÓRIO» e «Página N / T» saem nas **caixas de margem
do `@page`**, em todas as folhas (ver `PrintableDocument`).

**Limitação.** Só o Chromium recente desenha caixas de margem; noutros browsers
a grelha imprime-se completa e com cabeçalhos repetidos, mas com o selo só na
primeira folha e sem numeração. **O PDF do servidor é o caminho fiável**, em
particular no telemóvel.

### PDF

`instruments/grid-document.blade.php`, escrito para o dompdf (tabelas; sem flexbox
nem grid) e lido sempre do mesmo array.

- Timbre partilhado: `LetterheadLayout::Standard` em folha vertical,
  `Compact` em horizontal (mais colunas e mais linhas por página). «Página N / T»
  desenhada pelo `DompdfPrinter`.
- **O cabeçalho inteiro da tabela repete-se** em todas as páginas (linha de
  grupos, códigos, cotações); uma linha nunca se parte; cada bloco depois do
  primeiro começa em página nova.
- Uma grelha **provisória** leva um selo fixo «PROVISÓRIO» em **todas** as páginas.
- Resposta: anexo `grelha-<turma>-<título>-<data>.pdf` (nunca nomes de alunos),
  `Cache-Control: private, no-store, max-age=0`.
- **Auditoria** `instruments.grid_document.exported`: só contagens e opções
  (alunos, excluídos, questões, blocos, orientação, provisório) — nunca um nome
  ou uma nota. Ver a pré-visualização não grava nada da avaliação nem gera evento
  de auditoria.
- Sem logótipo (como o mapa) e sem fotografias.

### Gráficos factuais (só correção concluída, `?graficos=1`)

Três gráficos, como **dados compostos no servidor** (cada barra com o texto e o
comprimento já medidos), por isso o ecrã e o PDF desenham o mesmo. A
pré-visualização usa caixas (que imprimem sem «gráficos de fundo»); o PDF usa
uma célula de tabela como barra, numa pista com moldura — **sem Chart.js, sem
flexbox, sem gradientes**. Abaixo do limiar da escala, a barra é mais clara com
uma aresta escura (legível a preto e branco). Uma barra de comprimento zero é uma
pista vazia, nunca uma barra a zero.

1. **Distribuição dos resultados** — por nível da escala, na ordem da própria
   escala, ou pelas dez classes de dez pontos quando a escala não tem bandas.
   São os números do `ResultsAnalyzer` que Resultados mostra. Quem não tem
   resultado (ausente, por avaliar…) conta-se à parte; e um resultado que
   nenhuma banda alcança (acima de 100 % por efeito de um bónus) é dito numa
   nota, para as barras não somarem menos do que a turma sem explicação.
2. **Média da turma** — global e por domínio (domínios só com dois ou mais), num
   eixo **absoluto** de 0 a 100 %, com o n de alunos de cada média.
3. **Média por questão** — pontos obtidos sobre a cotação **entre os alunos
   avaliados em cada questão**, com a cobertura ao lado («20 de 24 avaliados · 2
   ausentes»). É um cálculo novo, descritivo e fora do motor: ausências,
   dispensas e notas por dar **não contam como zero** — ficam fora da média e na
   cobertura. Uma questão sem avaliados, ou sem cotação (uma dedução), não tem
   barra.

Regras comuns: só com a correção concluída (a porta das estatísticas oficiais);
com ninguém para desenhar, o gráfico di-lo em vez de molduras vazias; abaixo de
cinco alunos, uma nota de cautela; **sem barras por aluno e sem rankings**.

### Base e Pro

- **Base:** tudo o que está descrito — a grelha, o PDF e os gráficos factuais.
  O módulo `instruments` está nos três planos; não se usa `advanced_analytics`.
  Nenhuma IA é chamada (um teste garante que o gateway nunca é tocado).
- **Reservado ao Pro e não implementado nestes documentos:** a **interpretação**
  dos resultados, a **comparação automática** com instrumentos e períodos
  anteriores e as **tendências e padrões**. A interpretação por IA que existe em
  Resultados (`ai_assessment`) é independente e nunca é chamada por esta grelha.
  Os gráficos factuais descrevem o que está registado; não o interpretam.

### Privacidade (minimização)

Só entram o número na turma e o nome, as notas e estados, e os resultados do
motor. **Nunca** viajam, nem nas props nem no PDF: fotografia, identificadores
(`id`, ULID, `enrollment_id`), data de nascimento, número de processo,
pseudónimo, índice cego, `state_reason`, notas internas do elemento, observações
de Resultados, medidas e caracterização, datas de matrícula. As linhas
distinguem-se pela posição. Os alunos não abrangidos não saem (ficam só na
contagem). Um título ou descrição com HTML sai como texto, nunca como marcação.

### Desempenho

O cálculo da turma faz-se **uma só vez**: `ClassResultsCalculator::forInstrumentCohort()`
carrega o elemento para a turma inteira (mesma configuração, mesmos *inputs* e
mesmo motor que `forInstruments()`, que um teste compara aluno a aluno nas quatro
regras de ausência) em vez de uma vez por aluno, e a grelha, as notas e os
gráficos partilham o mesmo contexto (`BuildResultsAnalysis::officialCellsFor` e
`dimensionsAnalysis`). O número de consultas não cresce com o número de alunos —
testado na pré-visualização, no PDF e nos gráficos. Isto também acelerou a grelha
de correção e Resultados, com os mesmos valores.

### Testes e validação

`InstrumentGridTest`, `InstrumentGridDocumentTest`, `InstrumentGridChartsTest`,
`InstrumentGridValidationTest`, `InstrumentGridLayoutTest`,
`InstrumentGridOptionsTest`, `ClassResultsCohortLoaderTest`,
`GridDocument.test.ts` e `PrintableDocument.test.ts`. O que nenhuma suite
unitária mostra — o PDF real, a impressão do browser e a navegação entre
documentos — foi validado com guiões Playwright + pypdf sobre uma base isolada
(turma de 30 alunos, instrumentos de 3, 40 e 60 questões); esses guiões **não**
fazem parte da suite do repositório.

### Limitações conhecidas

- A impressão do browser só traz o selo e a numeração em todas as folhas no
  Chromium recente; só se verificou em Chromium e por impressão para PDF.
- As larguras das colunas são estimativas; um nome muito comprido pode quebrar
  em duas linhas.
- A média por questão é descritiva e fora do motor; uma célula avaliada só com
  nível de escala (só chega por importação) mostra «—», embora o motor a leia
  como 0.
- Os gráficos só existem com a correção concluída.
- Não há cópia guardada, nem gráficos por aluno, nem interpretação (Pro).
- A grelha da turma (Pauta) é a Fatia C, na 0.175.0: ver «Grelha factual de avaliação da turma».

## Grelha factual de avaliação da turma (0.175.0)

A grelha de uma turma num momento intercalar ou no período/semestre **é a Pauta de
Avaliação** vista como documento. Não há uma segunda grelha nem um segundo cálculo:
o documento lê a Pauta (a de hoje ou um momento guardado) pelo mesmo caminho que o
CSV e o Excel dela e acrescenta o que faltava — pré-visualização, PDF e gráficos
factuais. A impressão do próprio ecrã da Pauta (`Show.vue`) não mudou.

```
BuildEvaluationSheet  ──►  EvaluationSheetDocument::fromLiveSheet()  ┐
EvaluationSheetExport ──►  EvaluationSheetDocument::fromSnapshot()   ┴─►  EvaluationSheetGrid::build()  ──►  props Inertia  ──►  evaluation-sheets/GridDocument.vue
(payload congelado)                                                        (+ EvaluationSheetGridCharts)  └─►  evaluation-sheets/grid-document.blade.php  ──►  DompdfPrinter  ──►  PDF
```

### Rotas, acessos e quem pode abrir

| | Rota | Nome |
|---|---|---|
| Pré-visualização, Pauta de hoje | `classes/{class}/pauta-avaliacao/impressao/{period}` (`?momento=interim\|final`) | `evaluation-sheets.grid-document` |
| PDF, Pauta de hoje | `…/impressao/{period}/pdf` | `evaluation-sheets.grid-document.pdf` |
| Pré-visualização, momento guardado | `classes/{class}/pauta-avaliacao/historico/{export}/impressao` | `evaluation-sheets.snapshot.grid-document` |
| PDF, momento guardado | `…/historico/{export}/impressao/pdf` | `evaluation-sheets.snapshot.grid-document.pdf` |

Acessos: «Grelha para imprimir» na Pauta (ao lado do «Imprimir» dela, que não mudou;
leva o período e o momento que se está a ver), no Histórico (por momento, junto de
CSV e Excel) e na pauta guardada. O botão «PDF» da pré-visualização leva as opções e
o momento escolhidos.

Autorização como a da Pauta: `SchoolClassPolicy::view` e módulo `results` (Base).
Um colega que não leciona a turma recebe 403; uma turma, período ou registo de outra
organização não se resolve (404); um registo de outra turma da mesma organização dá
404; uma fotografia cujo selo já não corresponde ao payload dá 409 e nenhum byte de
documento. São GET, por isso funcionam em modo só de leitura.

### Duas origens que nunca se cruzam

- **A Pauta de hoje** (`liveSource`) lê-se com `withProposalBasis: false`: a grelha
  não lê «a proposta de hoje» nem «desatualizada», e na unidade final do ano isso
  custaria apurar as outras unidades. O momento (`?momento=`) só dá o título —
  os dois momentos leem a mesma pauta. Um período que não seja do ano letivo da
  turma não se resolve.
- **Um momento guardado** (`snapshotSource`) lê-se **só do payload congelado**: as
  ações do guardado não recebem o construtor da pauta (há um teste por reflexão), e
  o documento diz que é um momento guardado, quando e por quem, e que não reflete
  alterações posteriores. Mudar uma decisão depois não mexe numa célula, numa barra
  nem numa letra do PDF desse momento.

### O que mostra

Um aluno por linha, as mesmas linhas que a Pauta. Colunas: **Nº** e **Nome**
(repetidos em cada bloco); por domínio, o **Resultado** (a percentagem calculada) e
a **Apreciação**; **Global** (o resultado e a banda onde cai); **Classificação**
(**Proposta do Lapispro**, **Nível atribuído** e **Estado**); avisos de cobertura; e,
só se se pedir, a autoavaliação.

**Calculado, proposto e decidido nunca partilham uma célula** (§3.3). O papel não tem
itálico: a distinção faz-se por colunas e por palavras, como no CSV.

| O que é | Onde | Como se distingue |
|---|---|---|
| Calculado | «Resultado» (percentagem do motor, uma casa; `*` quando assenta em elementos parciais ou em falta) | nunca é alterado por uma decisão |
| Proposto | «Proposta do Lapispro»; por domínio, a apreciação sem marca | é o que o Lapispro propôs |
| Decidido | «Nível atribuído»; por domínio, a apreciação com a marca «prof.» e a proposta que substituiu por baixo («Proposta: B») | é a decisão do professor |
| Estado | «Estado» em palavras: Proposta / Confirmada / Publicada | «—» quando não há classificação |

Um nível atribuído vazio diz porquê («não decidido» ou «sem classificação»): nunca
promove a proposta a decisão. Sem valor é «—», nunca zero. O texto das células é o
dos exportadores (`EvaluationSheetCellText`), partilhado pelo CSV, pelo Excel e por
este documento; a percentagem usa a mesma função (`percentage()`), com vírgula.

**`PROVISÓRIO`** — em todas as páginas do PDF e na primeira folha impressa — enquanto
algum aluno não tiver nível atribuído pelo professor; sem selo (e «Classificações
tomadas») quando todos têm. O documento diz também a origem («Estado à data de
emissão» ou «Momento guardado a …»), o período, o âmbito («Período»), a data de
referência de um momento guardado e os avisos que ficaram registados quando foi
guardado.

### Opções

Lidas da query numa lista branca (`EvaluationSheetGridOptions`): `autoavaliacao` (o
que os alunos disseram de si — **desligada** por omissão, e desativada quando a pauta
não a tem), `graficos` (**desligados** por omissão) e `orientacao`
(`horizontal` — por omissão —, `vertical`, `auto`). Um valor forjado é o valor por
omissão. Nenhuma opção altera um número.

### Orientação e blocos de colunas

Horizontal e compacta por omissão: uma Pauta é larga por natureza. O dompdf não
pagina na horizontal, por isso o corte está na estrutura: o planador
(`InstrumentGridLayout`, partilhado com a grelha de um instrumento, agora com a
largura do item como parâmetro) corta em **blocos de colunas**, e **o domínio é a
unidade que nunca se parte**. Cada bloco leva Nº e Nome, o cabeçalho de grupos e
todas as linhas; cada bloco depois do primeiro começa numa folha nova («Parte 2 de
3»). A largura de cada coluna vem de `EvaluationSheetGrid::columnWidth()`, a mesma
que o planeador usou, para que um bloco que cabe no plano caiba na folha. Um resumo
que cabe numa folha mas não deixa espaço a nenhum domínio fica num bloco só seu em
vez de passar a margem (defeito do planador que esta grelha expôs).

### Pré-visualização e impressão do browser

`evaluation-sheets/GridDocument.vue` só desenha a estrutura que o servidor compõe,
dentro do `PrintableDocument` (selo e «Página N / T» nas caixas de margem do `@page`,
só em Chromium recente). No ecrã estreito a tabela desloca-se dentro do seu
contentor; no papel nada se corta e cada bloco começa numa folha.

### PDF

Vista própria para o dompdf (`evaluation-sheets/grid-document`): tabelas, sem flex nem
grid, horizontal compacto por omissão (timbre `Compact`; vertical usa o `Standard`),
cabeçalho (grupos e colunas) repetido quando a lista passa de folha, linhas que não
se partem, `PROVISÓRIO` e «Página N / T» em todas as folhas. Anexo
`grelha-pauta-<turma>-<momento>-<data>.pdf`, `private, no-store`, nunca guardado e
**nunca nomeado com o nome de um aluno**. A exportação deixa o evento de auditoria
`evaluation_sheets.grid_document.exported` com **só contagens e opções** (formato,
origem, ulid do período ou do registo, momento, orientação, opções, provisório,
alunos, domínios, blocos, atribuídos, pendentes): nunca um nome, uma nota ou um
nível. A pré-visualização é um ecrã e não deixa rasto.

### Gráficos factuais (`?graficos=1`)

`EvaluationSheetGridCharts` conta **só a partir do documento** — as mesmas linhas
que a tabela —, sem consultas, sem relógio e sem chamar a Estatística: a aritmética
é a dela (decimal, uma casa, «half up») reproduzida sobre o que a pauta traz. Por
isso os gráficos de um momento guardado são os desse momento, e o ecrã, a impressão e
o PDF desenham as mesmas barras. Três, descritivos, sem ranking, tendência nem
comparação:

1. **Níveis propostos** (coluna «Proposta do Lapispro»): uma barra por nível da
   escala, por ordem, **incluindo os vazios**; numa escala de valores (0–20), um
   valor de cada vez em ordem numérica.
2. **Níveis atribuídos** (coluna «Nível atribuído»), **à parte** e **só quando alguém
   tem nível** («n = 4 de 30 alunos com nível atribuído»).
3. **Média da turma** do global e de cada domínio (um só domínio = só o global), em
   eixo absoluto 0–100, com o n de cada média.

Quem não tem proposta, nível ou resultado conta-se numa linha própria e **nunca é uma
barra a zero**; um domínio sem resultados tem «—»; um nível que a escala não tem e um
resultado acima de 100 % (bónus) dizem-se numa nota (a barra pára no fim do eixo);
abaixo de cinco alunos há uma nota de cautela. Desenhados como no gráfico da grelha de
um instrumento: caixas no ecrã e na impressão, células de tabela no PDF.

**A escala viaja com a Pauta.** Para ler os níveis sem ir buscar a escala ao
presente, `BuildEvaluationSheet` devolve `scale` (nome, tipo e níveis por ordem, sem
identificadores) e a fotografia congela-a (payload v3, aditivo). **Uma fotografia
anterior não a traz**: os dois gráficos por nível dizem que não estão disponíveis em
vez de adivinhar uma ordem, e a média desenha-se na mesma.

**Paridade com a Estatística (`BuildClassStatistics`).** Onde os universos coincidem —
o período com o âmbito «Período», alunos que frequentam — a média global, a média e
o n de cada domínio, a distribuição dos níveis atribuídos (contagens, percentagens,
negativos, ordem da escala) e a distribuição por níveis das médias coincidem com as da
Estatística, e há testes sobre a Pauta real que o provam. **Diferenças legítimas:** a
Estatística acompanha o âmbito primário (num período acumulado lê o acumulado), aceita
um universo escolhido (alunos que não frequentam com resultado externo) e decide o
domínio pela data da evidência; a grelha conta os alunos da Pauta e o âmbito
«Período». E os «níveis propostos» são a proposta **guardada** de cada aluno (o que a
coluna mostra), que pode estar desatualizada ou em falta para quem ainda não foi
proposto; a Estatística bandeia a média de hoje.

### Base e Pro

- **Base:** tudo o que está descrito — a pré-visualização, o PDF, os momentos
  guardados e os gráficos factuais. O módulo é `results`.
- **Pro, e não implementado aqui:** interpretação dos resultados, comparação
  automática entre momentos ou períodos e tendências (`advanced_analytics`). Os
  gráficos descrevem um documento; não o interpretam nem o comparam com outro.

### Privacidade (minimização)

O documento identifica cada aluno só pelo número e pelo nome. **Nunca** viajam
`override_reason` (texto livre), identificadores (matrículas, domínios, níveis), as
explicações de cobertura, os campos da proposta de hoje (`current_*`,
`proposal_is_stale`) nem a escala em que se decidiu; a autoavaliação só viaja se se
pedir. O PDF não é guardado nem indexado, e a auditoria não leva nomes. Um nome com
marcação (`<b>`, `<script>`) sai como texto, na página e no PDF.

### Desempenho

Ler a Pauta custa o mesmo número de consultas para 6 e para 30 alunos (pré-visualização
e PDF, ao vivo), e um momento guardado custa o mesmo para qualquer tamanho (só o
registo, a turma e a sessão: o documento é o payload). Gerar o PDF é só dompdf —
medido: 6 domínios × 30 alunos em ~0,7 s, 14 × 40 em ~1,9 s, 14 × 60 em ~2,8 s, com
autoavaliação e gráficos.

### Testes e validação

- Unit: o compositor (`EvaluationSheetGridTest`), os gráficos
  (`EvaluationSheetGridChartsTest`), o planador (`InstrumentGridLayoutTest`) e a
  caracterização do CSV e do Excel (`EvaluationSheetWritersCharacterizationTest`,
  com ficheiros gerados antes da extração do texto das células).
- Feature, sobre a Pauta real: `EvaluationSheetGridSourceTest` (paridade com o
  ecrã e o CSV; um momento guardado é o seu payload),
  `EvaluationSheetGridDocumentTest` (rotas, portas, privacidade, modo só de leitura),
  `EvaluationSheetGridPdfTest` (PDFs reais lidos de volta: paridade com a
  pré-visualização, numeração, selo, cabeçalho repetido, 12 domínios × 30 alunos,
  auditoria), `EvaluationSheetGridChartsTest` (paridade com `BuildClassStatistics`,
  página e PDF iguais, fotografia sem escala) e `EvaluationSheetGridValidationTest`
  (a Pauta, o CSV, o Excel, a pré-visualização e o PDF dizem o mesmo de cada aluno,
  ao vivo e guardado; fotografias antigas em todos os consumidores sem consultar o
  presente; os três estados; turma sem perfil; nomes hostis; desempenho).
- Vitest da página e da Pauta; QA em browser real (Chromium, base isolada) por
  etapa e no fecho: navegação Pauta → grelha → Histórico → grelha guardada,
  impressão horizontal e vertical, PDFs descarregados pelo botão, telemóvel a 375 px.
  Os guiões **não** fazem parte da suite do repositório.

### Limitações conhecidas

- A impressão do browser só traz o selo e a numeração em todas as folhas no
  Chromium recente; o PDF é o caminho fiável.
- Uma fotografia guardada antes da 0.175.0 não tem a escala: os dois gráficos por
  nível dizem que não estão disponíveis (a média desenha-se); só as de agora em
  diante os têm.
- O documento e os gráficos contam os alunos da Pauta no âmbito «Período»; não
  acompanham o âmbito acumulado da Estatística.
- O `PROVISÓRIO` depende de haver ou não nível atribuído; o documento só conhece o
  título do momento, não distingue o intercalar do final. Os dois momentos de um
  período leem a mesma pauta: um intercalar «ao vivo» mostra os mesmos números que o
  final, e só um momento guardado é um estado congelado.
- O CSV e o Excel da Pauta ao vivo não recebem o momento: exportar a partir do
  separador «intercalar» dá o título do momento final (inalterado desde antes da
  Fatia C).
- Um aluno sem dados lista nos avisos de cobertura todos os domínios, o que ocupa
  várias linhas e reduz as linhas por folha numa turma com muitos alunos assim (é o
  texto do CSV).
- As larguras das colunas são estimativas; um nome muito comprido quebra em duas
  linhas, sem se perder.
- **Fora desta fase, por decisão:** o aviso de proposta desatualizada no documento
  (custaria apurar as outras unidades) e o **Quadro Síntese** anual.

## Propostas para a revisão da Matriz Mestre V3

Registadas aqui; a Matriz canónica não foi editada. Estado na 0.175.0 — os quatro
requisitos originais:

1. *(implementado na 0.173.0)* «O professor pode gerar, por turma e ano letivo,
   um mapa factual dos alunos com medidas educativas, distinguindo medidas
   implementadas das medidas a implementar.»
2. *(implementado na 0.174.0)* «O professor pode visualizar, imprimir e gerar PDF
   da grelha factual de resultados de um instrumento de avaliação, respeitando
   itens, domínios e escala.» Ver «Grelha imprimível de um instrumento».
3. *(implementado na 0.175.0)* «O professor pode visualizar, imprimir e gerar PDF
   da grelha factual de avaliação de uma turma num momento intercalar ou
   período/semestre configurado.» É a Pauta de Avaliação como documento, ao vivo e
   num momento guardado; o período/semestre vem da configuração do ano letivo e o
   «intercalar» é o momento estrutural da Pauta (e os momentos guardados), **não** a
   «avaliação intercalar» da Estatística (corte por data). Ver «Grelha factual da
   turma». **Fora:** o aviso de proposta desatualizada e o Quadro Síntese anual.
4. *(implementado na 0.175.0 para os dois documentos)* «As grelhas podem incluir
   gráficos factuais derivados dos mesmos dados; interpretação, comparação
   automática entre períodos e tendências permanecem capacidades Pro.» **Feito:** os
   gráficos factuais da grelha de um instrumento (0.174.0: distribuição, média por
   domínio e média por questão, só com a correção concluída) e os da grelha da
   turma (0.175.0: níveis propostos, níveis atribuídos, média por domínio),
   opcionais, no plano Base. **Continua reservado ao Pro e não implementado nestas
   grelhas:** interpretação, comparação automática entre períodos e tendências.

**Limitações dos requisitos 3 e 4** (detalhe nas secções respetivas): a impressão do
browser com selo e numeração em todas as folhas é só Chromium recente (o PDF é o
caminho fiável); as fotografias anteriores à 0.175.0 não têm gráficos por nível; o
âmbito é «Período»; o `PROVISÓRIO` não distingue intercalar de final.

**Atualização (0.177.0 — Fatia D).** O que ficou reservado ao Pro nas grelhas — comparação
automática entre períodos e tendências, e a leitura de pontos fortes e dificuldades — existe agora na
«Análise da Avaliação» (`classes/{turma}/analise-avaliacao`), no módulo `advanced_analytics`, com o seu
próprio documento (pré-visualização, impressão e PDF) na mesma infraestrutura documental. As grelhas
do Base continuam como estão: factuais, sem interpretação. Ver
[advanced-assessment-analysis.md](advanced-assessment-analysis.md).
