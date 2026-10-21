# Documentos da turma — gerados na hora

Relatórios tem, desde a 0.173.0, duas famílias de documentos. Este ficheiro
descreve a segunda e explica porque é que ela **não** é um `Report`. Tem hoje
dois documentos: o **Mapa de Medidas Educativas** (0.173.0) e a **grelha
imprimível de um instrumento de avaliação** (0.174.0).

| | Relatório (`reports`) | Documento da turma |
|---|---|---|
| O que é | Um objeto com vida: rascunho → finalizado → derivado ([ADR-0005](adr/0005-reports-are-documents-not-downloads.md)) | Uma leitura factual dos registos, no momento em que se abre |
| Guarda alguma coisa? | Sim — secções, texto do professor e, ao finalizar, um `document` congelado | **Não.** Nenhuma linha em `reports`, nenhum PDF guardado |
| Texto | Frases compostas pelo sistema, editadas pelo professor | Nenhuma frase interpretativa; só listas, contagens, valores e notas factuais |
| Formatos | Pré-visualização, impressão, PDF, Word | Pré-visualização, impressão, PDF |
| Exemplo | Relatório de turma, individual, por registos, de escola | **Mapa de Medidas Educativas** (0.173.0); **grelha de um instrumento** (0.174.0) |

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
- A grelha intercalar/semestral **não** está implementada.

## Para a grelha intercalar/semestral (não implementada)

**O documento ainda não existe para o utilizador.** A grelha de uma turma num
momento intercalar ou no período/semestre é a Fatia C; estão feitas a preparação
técnica (C1) e a fonte de dados (C2, `EvaluationSheetGrid`), sem rotas nem ecrã
(C3–C6). O que já existe, e o que as etapas seguintes têm de respeitar:

- `EvaluationSheetGrid::build(EvaluationSheetDocument, EvaluationSheetGridOptions)`
  devolve a estrutura neutra da grelha da turma. É uma função pura do documento:
  sem consultas, sem relógio, sem autorização, sem timbre nem data de emissão (são
  do controlador). Colunas: Nº e Nome; por domínio, Resultado (a percentagem
  calculada) e Apreciação; Global (resultado e a banda onde cai); Classificação
  (Proposta do Lapispro, Nível atribuído, Estado); avisos de cobertura; e a
  autoavaliação, só com `?autoavaliacao=1`. Calculado, proposto e decidido nunca
  partilham uma célula: a apreciação decidida leva «prof.» e guarda por baixo a
  proposta que substituiu; um nível atribuído vazio diz «não decidido» ou «sem
  classificação». `PROVISÓRIO` enquanto algum aluno não tiver nível atribuído.
- Nunca viajam `override_reason`, ids, explicações de cobertura nem os campos da
  proposta de hoje; o controlador da C3 deve ler a Pauta com
  `withProposalBasis: false`.
- Horizontal por omissão (`?orientacao=vertical|auto`); a unidade que nunca se
  parte é o domínio.

- A Pauta de Avaliação é a grelha: o documento consome o `EvaluationSheetDocument`
  (`fromLiveSheet`, que já aceita o `SheetMomentKind` e só muda o título, ou
  `fromSnapshot`, que lê só o payload congelado), sem segundo motor de cálculo.
- `EvaluationSheetCellText` é a única leitura do texto das células (nível
  proposto, decisão do domínio, proposta e decisão da classificação, e a origem
  dita por palavras), partilhada pelo CSV e pelo Excel; o documento usa a mesma.
- `DompdfPrinter`, `PaperOrientation`, `LetterheadLayout`, o timbre partilhado e
  `PrintableDocument` (com `stamp` e `pageNumbers`): a impressão do browser e o
  PDF resolvem-se como na grelha de um instrumento.
- `InstrumentGridLayout::plan()` é uma função pura de orientação e blocos de
  colunas e aceita a largura de cada item (`$itemWidth`, por omissão a de uma
  questão); a grelha da turma terá as suas (domínios e momentos do perfil), e o
  dompdf continua a não paginar na horizontal. Um resumo que cabe numa folha mas
  não deixa espaço a nenhum item fica num bloco só seu, em vez de passar a margem.
- Colunas dinâmicas a partir do perfil e do momento reais; nunca número fixo de
  domínios ou de escala.
- Gráficos factuais como dados compostos no servidor (barras com largura em
  percentagem, desenhadas por caixas no ecrã e por células de tabela no PDF); um
  `<canvas>` do Chart.js não chega ao PDF.
- Proposta matemática ≠ classificação final do professor — ao nível do período
  existem as duas (`classifications`); sem evidência, «—», nunca zero.

## Propostas para a revisão da Matriz Mestre V3

Registadas aqui; a Matriz canónica não foi editada. Estado na 0.174.0:

1. *(implementado na 0.173.0)* «O professor pode gerar, por turma e ano letivo,
   um mapa factual dos alunos com medidas educativas, distinguindo medidas
   implementadas das medidas a implementar.»
2. *(implementado na 0.174.0)* «O professor pode visualizar, imprimir e gerar PDF
   da grelha factual de resultados de um instrumento de avaliação, respeitando
   itens, domínios e escala.» Ver «Grelha imprimível de um instrumento».
3. *(futuro — não iniciado)* «O professor pode visualizar, imprimir e gerar PDF
   da grelha factual de avaliação de uma turma num momento intercalar ou
   período/semestre configurado.»
4. *(parcialmente implementado na 0.174.0)* «As grelhas podem incluir gráficos
   factuais derivados dos mesmos dados; interpretação, comparação automática
   entre períodos e tendências permanecem capacidades Pro.» **Feito:** os
   gráficos factuais da grelha de um instrumento (distribuição, média por
   domínio e média por questão, só com a correção concluída), no plano Base.
   **Falta:** os gráficos da grelha da turma (o requisito 3 ainda não existe).
   **Continua reservado ao Pro e não implementado nestas grelhas:**
   interpretação, comparação automática entre períodos e tendências.
