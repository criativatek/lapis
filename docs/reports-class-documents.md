# Documentos da turma — gerados na hora

Relatórios tem, desde a 0.173.0, duas famílias de documentos. Este ficheiro
descreve a segunda e explica porque é que ela **não** é um `Report`.

| | Relatório (`reports`) | Documento da turma |
|---|---|---|
| O que é | Um objeto com vida: rascunho → finalizado → derivado ([ADR-0005](adr/0005-reports-are-documents-not-downloads.md)) | Uma leitura factual dos registos, no momento em que se abre |
| Guarda alguma coisa? | Sim — secções, texto do professor e, ao finalizar, um `document` congelado | **Não.** Nenhuma linha em `reports`, nenhum PDF guardado |
| Texto | Frases compostas pelo sistema, editadas pelo professor | Nenhuma frase interpretativa; só listas e contagens |
| Formatos | Pré-visualização, impressão, PDF, Word | Pré-visualização, impressão, PDF |
| Exemplo | Relatório de turma, individual, por registos, de escola | **Mapa de Medidas Educativas** (0.173.0) |

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
  posição do número de página acompanha a orientação. O mapa usa retrato; uma
  grelha larga poderá pedir paisagem sem tocar no motor.
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
  parcial Blade e pelo `DompdfPrinter`. `Standard` é o que todos os documentos
  usam; `Compact` (margem 26/14/16/14 mm) existe para grelhas largas em
  paisagem e ainda não é usado por nenhum documento.

O que **não** se criou: um motor genérico de «documentos», um modelo de snapshot
novo, um tipo de relatório novo, gráficos. Ficam para quando houver uso.

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

## Para as grelhas seguintes (não implementadas)

Notas para quem fizer a grelha de um instrumento e a grelha intercalar/semestral
— **nada disto existe ainda**:

- Colunas dinâmicas a partir do instrumento/perfil reais; nunca número fixo de
  questões, domínios ou escala.
- `PaperOrientation::Landscape` e `PrintableDocument orientation="landscape"`
  para grelhas largas.
- Gráficos factuais em HTML/CSS (barras com largura em percentagem) renderizam
  igual no ecrã e no dompdf; um `<canvas>` do chart.js não chega ao PDF.
- Proposta matemática ≠ classificação final do professor; sem evidência, «—»,
  nunca zero.

## Propostas para a revisão da Matriz Mestre V3

Registadas aqui; a Matriz canónica não foi editada.

1. *(implementado na 0.173.0)* «O professor pode gerar, por turma e ano letivo,
   um mapa factual dos alunos com medidas educativas, distinguindo medidas
   implementadas das medidas a implementar.»
2. *(futuro)* «O professor pode visualizar, imprimir e gerar PDF da grelha
   factual de resultados de um instrumento de avaliação, respeitando itens,
   domínios e escala.»
3. *(futuro)* «O professor pode visualizar, imprimir e gerar PDF da grelha
   factual de avaliação de uma turma num momento intercalar ou período/semestre
   configurado.»
4. *(futuro)* «As grelhas podem incluir gráficos factuais derivados dos mesmos
   dados; interpretação, comparação automática entre períodos e tendências
   permanecem capacidades Pro.»
