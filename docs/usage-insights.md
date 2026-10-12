# Utilização e acompanhamento (`/admin/usage`)

Painel **interno** do backoffice para perceber a adoção do Lapispro — quem o usa,
com que regularidade, que funcionalidades usa e onde encontra dificuldades — e
para identificar situações em que um professor **poderá beneficiar de apoio**.

> **Não avalia professores.** Compara a regularidade e as ações concluídas na
> aplicação. Não avalia a qualidade do trabalho pedagógico, a competência nem a
> produtividade de ninguém, e nenhum número deste painel deve ser lido assim.

Desenho técnico: [superpowers/specs/2026-10-11-usage-insights-design.md](superpowers/specs/2026-10-11-usage-insights-design.md).

## Finalidade

1. Compreender a adoção do serviço: utilização por semana e por mês, por
   funcionalidade, e o regresso nas semanas seguintes à adesão.
2. Encontrar onde a aplicação falha a quem a usa (erros técnicos em operações
   relevantes).
3. Sugerir à equipa interna situações que **podem justificar uma verificação**
   (sinais de acompanhamento).

Nada disto produz efeitos na conta: não há envio automático de emails, de
notificações nem de contactos, não há decisões automatizadas, e a posição no
ranking não desencadeia nada.

## Quem tem acesso

Só pessoal interno **expressamente autorizado**: é preciso ser administrador da
plataforma (`is_platform_admin`) **e** ter a autorização própria
`can_view_usage_insights`. Ser administrador da plataforma não chega — a
autorização não é concedida por omissão.

- Primeira concessão, no servidor: `php artisan lapis:grant-usage-insights <email>`
  (revogar: `--revoke`). Só aceita quem já é administrador da plataforma.
- Depois, no backoffice, quem já tem a autorização pode concedê-la ou revogá-la
  a outro administrador na ficha da conta. Fica na auditoria da plataforma
  (`admin.usage_insights_granted` / `admin.usage_insights_revoked`).
- Revogar o estatuto de administrador revoga também esta autorização.
- A autorização é verificada **no servidor em todas as rotas** (`usage-insights`
  → `EnsureUsageInsightsAccess`). Esconder a entrada de menu é apresentação.

**Nunca** é mostrado a colegas, ao responsável de uma organização nem ao
administrador institucional. Não há rota fora de `/admin/usage` que devolva estas
métricas.

## O que é recolhido — e o que não é

Cada linha de `usage_events` tem exatamente:

| Campo | O quê |
|---|---|
| `user_id`, `organization_id` | identificadores internos de quem agiu e da organização em que estava |
| `occurred_at`, `occurred_on` | o instante e o dia (fuso da aplicação, `Europe/Lisbon`) |
| `feature` | funcionalidade, de uma lista fechada |
| `event` | tipo de evento, de um catálogo fechado (`App\Support\Usage\UsageEventType`) |
| `kind` | `view` (consulta), `action` (ação concluída) ou `failure` (falha técnica) |
| `item_count` | só nas ações: número de elementos afetados (1, ou o tamanho do lote) |
| `failure_code` | só nas falhas: código técnico de lista fechada (`database`, `storage`, `external_service`, `timeout`, `unexpected`) |
| `dedupe_key` | resumo HMAC usado para impedir duplicados; não é reversível e não contém conteúdo |

**Não é recolhido**: textos de sumários, do caderno, de intervenções ou de
qualquer formulário; nomes, números ou identificadores de alunos;
classificações; anexos; termos de pesquisa; URLs, parâmetros ou mensagens de
erro; endereço IP ou navegador; teclas, cliques, capturas de ecrã ou gravações
de sessão. Não há propriedades livres: a tabela não tem coluna de texto livre
nem JSON, e um teste afirma-o.

Não há serviços externos de analytics. Tudo vive na base de dados do Lapispro.

## Definições

Todas as contagens usam o fuso da aplicação (`Europe/Lisbon`) e **semanas de
segunda a domingo**. Um dia é um dia de calendário local — a mudança de hora não
o encurta nem o alonga.

**Interação elegível** — uma de duas coisas, feita por um utilizador autenticado
dentro de uma funcionalidade:

- **Consulta deliberada**: abrir uma página de uma funcionalidade (visita
  completa). Não contam recarregamentos parciais, pré-carregamentos, pedidos
  JSON automáticos, polling, redirecionamentos nem o painel inicial
  (`/dashboard`) — entrar na aplicação não é, por si, usar uma funcionalidade.
  Fica no máximo **uma** consulta por utilizador, funcionalidade e dia.
- **Ação concluída** (ver abaixo).

**Utilizador ativo** (num intervalo) — teve pelo menos uma interação elegível
nesse intervalo. Um utilizador conta uma só vez, mesmo que pertença a várias
organizações.

**Dia ativo** — dia com pelo menos uma interação elegível.

**Semana com atividade** — semana (segunda a domingo) com pelo menos um dia
ativo.

**Última atividade observada** — o dia da última interação elegível registada.
**Não é o último login**: o Lapispro não guarda a data do último login, e este
painel não a deduz de nada.

**Ação concluída** — uma operação **confirmada** pelo servidor (depois de a
gravação ficar efetivamente feita) sobre um registo, contada **uma vez por
registo e por dia**:

- gravar o mesmo sumário cinco vezes no mesmo dia é **uma** ação;
- uma gravação que não altera nada **não conta** (o servidor compara antes e
  depois);
- uma tentativa falhada, um erro de validação ou um cancelamento **não conta**;
- uma operação em lote é **uma** ação com N **elementos afetados**: importar 100
  alunos é 1 ação com 100 elementos, nunca 100 ações.

**Elementos afetados** — o número de registos que uma ação tocou (alunos
importados, células de classificação alteradas, aulas marcadas). É mostrado à
parte das ações e nunca é somado a elas.

**Falha técnica** — uma operação numa funcionalidade que terminou com erro do
servidor (HTTP 5xx com exceção) ou que o código apanhou como falha técnica (o
restauro de uma cópia de segurança que falha). **Não são falhas técnicas**: erros
de validação, recusas de permissão esperadas (403/404), sessões expiradas (419),
limites de pedidos (429), manutenção (503) nem cancelamentos. Repetições do mesmo
pedido falhado no mesmo minuto contam uma vez.

### Catálogo de ações por funcionalidade

| Funcionalidade | Ações concluídas | Registo útil? |
|---|---|---|
| Turmas e alunos | turma criada · aluno adicionado | — |
| Aulas e sumários | sumário guardado (inclui preparar a aula) · assiduidade registada · aula marcada como lecionada (individual ou em lote) · resultado da aula registado · sequência aplicada (lote) | sumário, assiduidade |
| Avaliação | instrumento criado · classificações lançadas (elementos = células alteradas) · classificação decidida · classificações publicadas (lote) · pauta guardada no histórico · avaliação intercalar guardada · instrumento editado (só se o instrumento, os grupos ou os itens mudaram) | instrumento, classificações, decisão, pauta |
| Registos | registo criado (elementos = alunos abrangidos) · trabalhos de casa em lote | ambos |
| Estratégias e medidas | medida criada (elementos = medidas criadas) · acompanhamento registado · medida editada (só se algum campo mudou, incluindo o estado) | ambos (a edição não) |
| Caracterização pedagógica | caracterização atualizada (elementos = secções alteradas) · importação confirmada (lote) | atualização |
| Caderno da turma | entrada criada · entrada editada | entrada criada |
| Relatórios | relatório criado · relatório finalizado · relatório exportado · relatório editado (só se o relatório ou alguma secção mudou: texto, ordem, regenerar, repor) | relatório criado |
| Importação e exportação | lista de alunos importada (lote) · horário importado (lote) · calendário da escola importado (lote) · cópia de segurança exportada · cópia de segurança restaurada (lote) · exportação INOVAR | — |
| Calendário | acontecimento criado · acontecimento editado (só se os campos ou as turmas mudaram) | — |

As edições são ações de **uma linha por registo e por dia**, nunca «registo útil»: o primeiro registo útil continua a ser criação ou registo. A comparação antes/depois é feita em memória e nunca é guardada.

**Lotes discriminados.** Uma ação em lote pode dizer o que aconteceu aos elementos: `criados`, `alterados` e `inalterados`. Os *elementos afetados* são criados + alterados; os inalterados dizem só quantos vieram no lote sem mudar nada. A lista de alunos reimportada compara a matrícula e a identidade antes e depois (em memória) e conta uma ação `lista de alunos importada` só se algum aluno foi criado ou alterado; uma reimportação só com linhas iguais não conta, e uma sem alunos criados não marca «primeiros alunos adicionados». O horário (criados / já existentes) e o calendário (criados) usam o que o controlador já sabe.

### Primeiros passos

| Passo | O que o prova |
|---|---|
| Primeira turma criada | ação «turma criada» **pelo próprio** |
| Primeiros alunos adicionados | «aluno adicionado» ou «lista de alunos importada» com pelo menos 1 aluno novo, **pelo próprio** |
| Primeiro registo útil | uma das ações marcadas «registo útil» na tabela acima |

**Configuração não é utilização.** Uma turma criada por outro professor, por
importação ou por restauro de uma cópia mostra que a turma **existe** para o
utilizador («turma disponível desde…») — não prova que ele a criou. O painel
mostra as duas coisas em separado e nunca converte uma na outra.

Os primeiros passos são datas de **primeira observação**. Numa conta criada
antes do início da recolha, o passo pode ter acontecido antes, e o painel di-lo.

## Cobertura dos dados

- A recolha começou com a versão **0.163.0**. **Não há histórico anterior**:
  nada foi reconstruído a partir de outras tabelas, e os períodos anteriores ao
  início da recolha aparecem como **«Sem recolha»**, nunca como zeros.
- O dia em que a recolha começou é **parcial**. Uma semana ou um mês que
  começou antes da recolha, ou que ainda não terminou, é marcado como parcial
  e não é comparado em silêncio com períodos completos.
- A recolha pode ser suspensa (`LAPIS_USAGE_ENABLED=false`); a interrupção fica
  registada em `usage_collection_periods` e os dias afetados aparecem como sem
  recolha.
- Os números de atividade vêm de uma **agregação diária** feita a cada 15
  minutos (`usage:aggregate`); o painel diz a hora da última agregação. As
  contagens de contas (novas contas, adesões) são lidas no momento.

### Porque não se usou o histórico existente

`audit_events` foi analisado e **não** é usado:

- só regista escritas — não tem consultas, logo não mede atividade;
- regista operações que não alteraram nada (arquivar uma turma já arquivada,
  gravar o mesmo sumário, regravar as mesmas classificações) e não distingue
  lotes de ações;
- tem lacunas deliberadas (o Caderno da turma não tem eventos; a pauta guardada
  também não) e conhecidas (issue #13);
- durante um acesso técnico, atribui as ações ao operador;
- a finalidade dele é a segurança e a rastreabilidade. Reaproveitá-lo para medir
  adoção seria dar a esses dados uma finalidade diferente daquela para que
  existem.

A tabela `sessions` também não serve: só guarda a sessão ativa.

## Classificação das organizações e segmentos

O painel separa a atividade por **classificação explícita** de cada organização
(`organizations.usage_classification`), decidida por uma pessoa:

| Classificação | Quer dizer |
|---|---|
| `unclassified` — Por classificar | Ninguém decidiu ainda. É o valor de todas as organizações, existentes e novas. |
| `real` — Utilização real | Professores a usar o produto no seu trabalho, incluindo convidados e períodos experimentais. |
| `test` — Teste e demonstração | Dados fictícios, QA, demonstrações e ensaios. |
| `internal` — Interna | Organizações da própria equipa, usadas para operação. |

**`is_test_account` não é lido.** Essa marca é comercial («ninguém prometeu nada
a esta conta») e só o pré-voo comercial a usa; «teste» comercial não quer dizer
dados fictícios (em produção as organizações estão todas marcadas, incluindo
professores parceiros que usam o produto a sério). A classificação das métricas
é independente dela e não altera nada na conta.

**Nada é inferido.** Não há backfill nem dedução a partir do nome, do email, do
plano ou da atividade: todas as organizações nascem `unclassified` e só uma
pessoa as classifica, na ficha da conta («Classificação para métricas de
utilização», só para quem tem `can_view_usage_insights`) ou por
`php artisan lapis:usage-classify` (`--list` para ver; `{organização}
{classificação} --operator=<email> --note=` para aplicar). Cada decisão fica na
auditoria **de plataforma** (`admin.usage_classification_set`), nunca na da
organização.

Os segmentos do painel são quatro e não se sobrepõem (inicial: `real`):

- **Utilização real** — organizações `real`, sem o pessoal interno;
- **Teste e demonstração** — organizações `test`;
- **Interno** — organizações `internal` e a atividade do pessoal interno
  (`is_platform_admin` ou `is_support_technician`) em qualquer organização;
- **Por classificar** — organizações `unclassified`, sem o pessoal interno.

O painel avisa quando há organizações por classificar («N organizações estão por
classificar e não entram na utilização real»). A classificação é lida no momento
da consulta: reclassificar uma organização muda o segmento de todo o seu
histórico.

**Nunca são recolhidos**: o acesso técnico do suporte (impersonação), tarefas
agendadas, jobs, comandos, pedidos automáticos e pré-carregamentos, nem a
atividade de quem se opôs à recolha (`usage_opted_out_at`).

> Em produção, até alguém classificar as organizações, o segmento «Utilização
> real» fica vazio e os dados aparecem em «Por classificar».

## Filtros

Período (últimos 7, 30 ou 90 dias, ou intervalo personalizado até 366 dias,
nunca anterior ao prazo de conservação), plano, organização e segmento. O
plano é o **plano em vigor hoje** em cada organização; mudanças de plano
passadas não são reconstruídas.

Todas as percentagens mostram o denominador («4 de 9»). Sem denominador não há
percentagem.

## Regresso nas semanas seguintes à adesão

Coortes por semana de adesão (segunda a domingo), só com contas criadas
**depois** do início da recolha (as outras não têm a primeira semana observada).
Para a semana da adesão (semana 0) e para cada uma das quatro seguintes:

- semana terminada → «n de N»;
- semana terminada com dias sem recolha → «n de N», marcada **recolha parcial**;
- semana sem nenhum dia de recolha → «sem recolha», nunca «0 de N»;
- semana em curso → «n até agora», marcada **em curso**, nunca como falta de regresso;
- semana que ainda não começou → «ainda não decorreu».

## Sinais de acompanhamento

Sugestões de **verificação** — não diagnósticos de desinteresse, abandono ou
necessidade de formação. Cada sinal mostra o motivo e a janela temporal. Ficam
apenas no painel.

| Sinal | Regra (configurável em `config/usage.php`) | Cobertura exigida |
|---|---|---|
| Conta sem turma | conta criada há pelo menos **7 dias** e sem nenhuma turma disponível | nenhuma (lê o estado das turmas) |
| Turma sem primeiro registo útil | turma disponível há pelo menos **7 dias** e nenhum registo útil observado desde então | a recolha tem de cobrir desde o dia em que a primeira turma ficou disponível |
| Sem atividade recente | atividade em pelo menos **2 das 4** semanas completas anteriores e nenhuma nos últimos **14 dias** | a recolha tem de cobrir as 4 semanas e os 14 dias |
| Falhas técnicas repetidas | pelo menos **3** falhas técnicas nos últimos **7 dias** | qualquer: uma cobertura parcial só pode subcontar |

**Recolha insuficiente não gera sinal.** O painel conta à parte quantos
utilizadores ficaram por avaliar por esse motivo.

**Interrupções letivas.** Quando as organizações do utilizador têm interrupções
letivas, feriados ou dias não letivos registados no calendário do ano letivo
(`academic_calendar_exceptions`), esses dias **não contam** para os prazos dos três
primeiros sinais, e o motivo di-lo («sem contar 3 dias não letivos»). Quando não
há nenhum dia não letivo registado na janela — por não haver calendário, ou por
não haver interrupções nesses dias —, os prazos são **dias de calendário**, e o
motivo também o diz. Basta um dia ser não letivo numa das organizações do
utilizador para não contar (é a leitura que gera menos sinais).

**Agregação parada.** Se a última agregação tiver mais de 60 minutos
(`usage.max_staleness_minutes`), os três primeiros sinais ficam por avaliar — a
atividade e o estado das turmas que leem estariam parados no tempo, e isso
acusaria toda a gente de não ter atividade recente — e o painel avisa. O sinal
de falhas técnicas continua: dados atrasados só o podem subcontar.

Contas em encerramento, desativadas ou anonimizadas não geram sinais. Continuam
na lista e no ranking com o estado escrito («Conta em encerramento»), porque o
encerramento ainda é reversível; os seus dados de utilização são eliminados no
fim da janela de recuperação, com o resto da conta. Uma conta
que a agregação ainda não viu (criada há menos de 15 minutos) ou que se opôs à
recolha não é avaliada.

## Ranking de utilização

Exclusivamente interno, com as mesmas permissões.

> «Compara a regularidade e as ações concluídas na aplicação. Não avalia a
> qualidade do trabalho pedagógico.»

Ordenações disponíveis, sempre com as métricas mostradas **separadas** — não há
pontuação composta:

1. **Dias ativos no período** (inicial);
2. **Ações concluídas** (com os elementos afetados ao lado, nunca somados);
3. **Semanas com atividade no período**;
4. **Funcionalidades utilizadas** — indicador complementar: usar poucas
   funcionalidades não é pior, quem usa só as de que precisa não é penalizado.

Não entram tempo de sessão, cliques, consultas como pontuação nem número de
alunos. Empates partilham a posição. Utilizadores sem atividade no período não
são ordenados (aparecem contados à parte).

Cada linha mostra os **dias de observação** — os dias do período em que havia
recolha e a conta já existia. Uma conta recente aparece como «observação parcial
(12 de 30 dias)» e pode ser filtrada à parte (antiguidade: com observação
completa, menos de 30 dias, 30 a 90, mais de 90).

## Conservação

| Dados | Prazo | Quem o executa |
|---|---|---|
| `usage_events` e `usage_daily_rollups` | **365 dias** (`retention.usage_metrics_days`, `LAPIS_USAGE_RETENTION_DAYS`) | `usage:prune`, diário às 04:05 |
| Datas de primeira observação (`usage_user_states`) | enquanto a conta existir | apagadas no encerramento |
| Tudo o que é deste painel, para uma conta encerrada | eliminado na anonimização da conta (dia 60) | `retention:execute` → `AnonymiseClosedAccount` |

Os dados de utilização viajam no backup diário integral da base de dados, como
o resto da base, e saem dele quando a cópia roda. **Não** fazem parte da
exportação «Exportar os meus dados» (não são dados pedagógicos nem da conta, e a
exportação é sobre o trabalho do professor).

## Fiabilidade

- A ação só é registada **depois** de a transação da operação ser confirmada
  (`DB::afterCommit`); uma transação revertida não deixa rasto.
- Uma falha na recolha **nunca** impede a gravação do trabalho do professor: o
  registo é apanhado, escrito no log como `usage.record_failed` (só o nome do
  evento e a classe da exceção) e a operação continua.
- Duplicados (pedido repetido, job reexecutado, duplo clique) são recusados pela
  chave única `dedupe_key`.
- O painel lê apenas tabelas de utilização agregadas e as tabelas de contas;
  nunca consulta conteúdos pedagógicos ao abrir. A disponibilidade de turmas é
  calculada pela agregação (`class_teachers`), não pelo painel.

## Fundamento e ponderação (interesse legítimo)

**Interesse prosseguido.** Manter e melhorar um serviço que os professores usam
para trabalhar: saber se funciona para quem o usa, onde falha, e quando pode
valer a pena oferecer ajuda.

**Necessidade.** Sem medir a utilização não é possível saber se uma
funcionalidade é usada, se uma falha afeta pessoas reais, nem se uma conta nova
ficou presa no primeiro passo. A recolha foi reduzida ao mínimo que responde a
estas perguntas: dia, funcionalidade, tipo de evento, resultado. Afastámos
alternativas mais intrusivas (tempo ativo, gravação de sessões, mapas de cliques,
ferramentas externas) e alternativas que não respondem à pergunta (inquéritos
apenas, ou os registos de auditoria — ver acima).

**Impacto.** Baixo: sem conteúdos, sem alunos, sem perfis comerciais, sem
decisões automatizadas e sem efeitos na conta. O risco residual é a leitura
errada de um número como juízo sobre uma pessoa — tratado pela linguagem do
painel (estados descritivos, «sugestões de verificação»), pela ausência de
pontuação composta e pelo acesso restrito a quem foi autorizado.

**Expectativas razoáveis.** Quem usa um serviço profissional espera que o
fornecedor saiba que partes são usadas e onde falham; não espera ser avaliado
pelo uso, nem ser contactado automaticamente — e não é.

**Direito de oposição.** O titular pode opor-se (Política de Privacidade, «Os
seus direitos»). Uma oposição procedente executa-se com
`php artisan lapis:usage-opt-out <email>`: grava `users.usage_opted_out_at`, a
recolha deixa de registar o que essa pessoa faz, e os seus eventos, agregados e
datas de primeiros passos são eliminados no mesmo passo. `--undo` volta a ligar a
recolha a partir desse momento (sem recuperar nada). Não há, nesta versão, um
interruptor no ecrã do professor.

A redação pública está na Política de Privacidade (secção «Métricas de
utilização do serviço»), versão de 2026-10-12. A validação jurídica desta
ponderação está em [legal.md](legal.md#pontos-que-continuam-a-exigir-validação-jurídica).

## Limitações conhecidas desta versão

- Sem tempo ativo, sem duração de sessão, sem último login — de propósito.
- Uma reimportação da lista de alunos que só corrige alunos já existentes não é
  contada: o código de importação não distingue linhas alteradas de linhas
  iguais, e contar todas seria contar gravações sem alteração.
- Edições de relatórios, de intervenções, de instrumentos e de acontecimentos
  não são contadas nesta versão — só a criação, a finalização e a exportação.
- Uma aula marcada como lecionada individualmente e depois incluída num lote no
  mesmo dia conta nas duas ações.
- O plano é o de hoje; não há reconstrução de planos passados.
- A lista, o ranking e os sinais são calculados no servidor sobre todas as
  pessoas do âmbito a cada abertura (sobre os agregados, nunca sobre dados
  pedagógicos). É folgado para centenas de contas; com milhares, os sinais e os
  dias não letivos devem passar a ser calculados pela agregação.
- A última atividade da lista é a do âmbito filtrado; a do detalhe é a de todas
  as organizações da pessoa, e o ecrã di-lo.
