# ADR-0005 — Um relatório é um documento, não um download

- **Status:** Accepted
- **Date:** 2026-08-19

## Context

O módulo Relatórios tinha, até aqui, um único artefacto: a **pauta** — a tabela
das classificações decididas, imprimível e exportável para CSV. Útil, e
insuficiente. O briefing do módulo (§2) é explícito sobre o que falta:

> O professor não deve receber simplesmente 72%, 61%, 68%, 66% para depois
> escrever o relatório.

O que se pede é um documento: frases completas, informação organizada, descrição
factual, análise pedagógica quando o plano o permitir, texto editável, PDF e
DOCX. E, sobretudo, **com validação do professor** — o sistema organiza,
descreve, sugere; o professor valida, edita, decide, finaliza (§82).

Três riscos estruturais tornam isto diferente de «gerar um PDF»:

1. **Um relatório pode mentir de forma credível.** Um gerador que escreve «o
   comportamento foi bom» numa turma cujo professor não respondeu nada produz um
   parágrafo que se lê perfeitamente e que é falso. Este é o modo de falha
   central do módulo, e não se parece com um bug.
2. **Um relatório finalizado é um documento oficial.** Se a nota de um aluno for
   corrigida em maio, o relatório assinado em fevereiro não pode mudar. Uma
   escola que não consegue reproduzir o que emitiu tem um problema que não é
   informático.
3. **Um segundo motor académico destruiria a confiança nos dois.** Se o relatório
   calculasse a sua própria média, um professor poderia ler 66,4% na Estatística
   e 66,1% no documento que a cita.

## Decision

### 1. O relatório é um objeto da aplicação

Três tabelas. `reports` é o envelope — tipo, âmbito, estado, proveniência.
`report_sections` é o conteúdo **enquanto rascunho**, uma linha por secção,
porque o professor edita, reordena, exclui e regenera uma de cada vez e cada
secção tem de guardar **dois textos**: o que o sistema escreveu
(`generated_body`) e o que vai ser impresso (`body`). `report_library_entries` é
a biblioteca pedagógica, com a forma de `scales` — `organization_id` NULL é uma
entrada do sistema, uma linha com valor pertence a uma escola.

### 2. Os relatórios leem; não calculam

O pipeline é `dados canónicos → interpretação estruturada → texto → revisão
humana → documento`. Uma `ReportSource` faz exatamente **uma** chamada a
`BuildClassStatistics` — que por sua vez faz uma a `BuildResultsProgression` — e
remodela a resposta. Os *composers* recebem um `ReportContext` e **não têm
acesso à base de dados**: é isso que torna a regra executável em vez de
declarada.

O relatório de escola conta **decisões** (classificações atribuídas), nunca
resultados recalculados. Contar decisões não é um segundo motor académico.

### 3. O que os planos distinguem é o que o relatório pode PERGUNTAR

`reports` permanece no plano Base. As secções descritivas — contagens, médias,
distribuições, e as declarações do próprio professor sobre a planificação — são
Base em toda a linha. `report_pedagogical_analysis` (novo, Pro e Institucional) é
o que uma secção precisa para **interpretar**: caracterizar comportamento ou
atitude, nomear uma dificuldade, propor uma medida.

A fronteira é a de §13: um resultado baixo em Escrita sustenta «resultados menos
consistentes no domínio da Escrita» e não sustenta «falta de estudo» — e nenhum
plano muda isso.

### 4. Finalizar congela; corrigir deriva

Ao finalizar, tudo é copiado para `document` e o modelo recusa qualquer escrita
posterior. O **logótipo é copiado em bytes** para `report-logos/`, porque o URL
do cabeçalho é estável e substituir o ficheiro por trás dele alteraria
silenciosamente todos os documentos já assinados. Só é copiado para relatórios
cuja opção `show_logo` está ligada: um documento que nunca vai imprimir o
logótipo não deixa uma cópia de um ficheiro da escola por aí (§65). O URL
congelado é **relativo** — um absoluto levaria consigo o `APP_URL` do momento e
apontaria, na instalação seguinte, para uma máquina que já não existe.

**`show_logo` é uma escolha do rascunho que a finalização congela.** Nasce falsa
— pôr o logótipo de uma escola num documento que uma família recebe é uma decisão
que alguém toma, não uma consequência de ter carregado um ficheiro (§50) — e o
professor liga-a e desliga-a enquanto o relatório for rascunho. `options` não
está em `EDITABLE_AFTER_FINALIZING`, pelo que finalizar congela a escolha com
tudo o resto, e nenhuma alteração posterior à identidade da escola a muda.

**O que esta versão deliberadamente não faz é reescrever documentos antigos.**
Relatórios finalizados antes de a opção existir não têm decisão registada e
`showsLogo()` responde `false` por eles. Isso é uma mudança de apresentação em
documentos já assinados e está fora do âmbito por decisão de produto: o objetivo
é que **os relatórios daqui para a frente saiam corretos**. Se algum dia se
quiser preservar o aspeto dos antigos, o caminho é derivar o valor **uma vez**
de `document.identity.logo_path` — o que **aquele** documento congelou — e
escrevê-lo na coluna, nunca inferi-lo em cada render.

Não existe «desfinalizar». Corrigir um relatório terminado significa derivar um
novo a partir dele, que herda **juízos** (caracterização, dificuldades validadas,
estratégias, texto reescrito pelo professor) e nunca **números**.


### 5. Dois formatos, um conteúdo

`ReportDocumentBuilder` produz uma estrutura neutra. É o único input que o
renderizador de PDF e o de DOCX têm — nenhum lê um relatório, uma secção ou um
documento congelado. Uma diferença entre os dois ficheiros teria de ser uma
diferença entre renderizadores, não entre duas ideias do que o documento diz.

**Três, na verdade: a pré-visualização online conta.** É o ecrã onde o professor
verifica o documento antes de o exportar e, quando compunha o seu próprio
cabeçalho, era uma terceira opinião sobre como o relatório se chama.
`ReportDocumentBuilder::heading()` é público exatamente por isso, e
`DocumentHeading` decide a hierarquia uma vez para os três: o título guardado é
escrito para uma listagem, e um título ainda gerado dissolve-se nos próprios
metadados em vez de ser impresso por cima de um subtítulo que o repete.

**O rodapé do PDF não é CSS.** `counter(pages)` não existe no dompdf — a folha
de estilos do motor define `page` e nada define `pages` —, pelo que o total
vinha sempre a zero e cada página era numerada «1 / 0». O rótulo é desenhado
pelo `PdfRenderer` depois do render, pelo `page_script` do canvas, que é o
primeiro momento em que o total é um número. Sem total fiável imprime «Página
3», nunca «Página 3 / 0».

## Consequences

**O que isto compra.** Um relatório reproduzível anos depois. Um módulo que não
pode discordar da Estatística. Uma fronteira de plano que é uma decisão de
produto e não uma limitação técnica. E um conjunto de testes que afirmam o que o
módulo **não** diz — que é onde as falhas perigosas vivem.

**O que isto custa.** Muitas classes pequenas: um *composer* por secção, cerca de
trinta. A alternativa — um método por secção em quatro geradores — duplicaria as
secções partilhadas e faria com que o relatório individual e o de turma pudessem
divergir sobre o que é uma dificuldade.

**Dívidas assumidas**, e por que razão foram assumidas:

- **Escrita restrita ao autor.** Um colega que leciona a turma lê o relatório mas
  não o reescreve. O esquema não tem coluna de papéis dentro de uma turma; a
  alternativa seria inventar uma (§78).
- **O relatório de escola é do dono da organização.** `organizations.owner_id` é
  a única autoridade acima de um professor que esta aplicação tem.
- **Sem eliminação de relatórios finalizados.** O projeto não tem padrão de
  retenção para documentos assinados. Recusar é o comportamento seguro até
  existir uma decisão (§55).
- **Sem IA.** Não existe infraestrutura de IA segura no projeto. A camada
  determinística está construída e a extensão fica óbvia; nada foi bloqueado.
- **`left_on` nem sempre preenchido.** Onde a data de saída não existe, o
  relatório diz que o aluno saiu e não afirma quando (§58).

## Alternatives considered

**Gerar o texto com um LLM.** Rejeitado por agora, e não por prudência
tecnológica: sem uma camada determinística por baixo não há forma de testar que o
documento não inventa. Construída a camada, a IA pode aperfeiçoar redação sobre
conteúdo já validado — que é o que §43 pede.

**Guardar o relatório finalizado em tabelas normalizadas.** Rejeitado pela mesma
razão que `interim_assessments` e `calculation_snapshots`: um documento congelado
é lido inteiro e nunca filtrado por dentro, e normalizá-lo faria com que cada
mudança futura ao que um relatório regista exigisse uma migração **sobre o
histórico**.

**Renderizar o PDF com Chromium.** Produz melhor tipografia e exige Node,
Chromium e uma sandbox funcional em cada máquina onde o Lapispro corre — incluindo o
Herd de um professor. `dompdf` é PHP puro e sem binário externo.
