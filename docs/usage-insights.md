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

**Ligar e desligar a recolha é outra autorização**, separada de consultar o
painel: `can_control_usage_collection`, também só com o estatuto de
administrador (`User::canControlUsageCollection()`), e também **não** concedida
por omissão. Quem só consulta o painel não liga nem desliga nada, nem se pode
atribuir esta autorização.

- Primeira concessão, no servidor:
  `php artisan lapis:grant-usage-insights <email> --collection-control`
  (retirar: `--revoke`). Só aceita quem já é administrador da plataforma. Fica na
  auditoria da plataforma sem causador (é a consola) e com quem a recebeu
  (`admin.usage_collection_control_granted` / `…_revoked`, `via: console`).
- Depois, no backoffice («Controlo da recolha de métricas», na ficha da conta),
  só quem já a tem a concede ou retira a **outro** administrador — nunca a si
  próprio —, com a mesma auditoria e o causador. A rota passa por
  `usage-collection-control` (`EnsureUsageCollectionControl`).
- `lapis:usage-collection start|stop` exige-a no `--operator`, e a própria ação
  (`SetUsageCollection`) volta a verificá-la, lida da base, para valer em
  qualquer ponto de entrada.
- Revogar o estatuto de administrador (comando `lapis:make-admin --revoke` ou
  backoffice) retira também esta autorização.

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
pessoa, organização, registo e dia — seja criação ou edição**:

- gravar o mesmo sumário cinco vezes no mesmo dia é **uma** ação;
- criar um relatório e editá-lo três vezes no mesmo dia é **uma** ação; editá-lo
  no dia seguinte é outra;
- registos diferentes nunca se fundem, mesmo com o mesmo id: o instrumento 5 e
  as classificações do instrumento 5 são duas ações, a caracterização da turma 5
  e a do aluno 5 também;
- uma gravação que não altera nada **não conta** (o servidor compara antes e
  depois);
- uma tentativa falhada, um erro de validação ou um cancelamento **não conta**;
- uma operação em lote é **uma** ação com N **elementos afetados**: importar 100
  alunos é 1 ação com 100 elementos, nunca 100 ações.

> **As ações são contagens deduplicadas, não o registo de todas as operações.**
> Cinco gravações do mesmo sumário no mesmo dia são uma ação; uma gravação igual
> à anterior não é nenhuma; dez edições de um relatório num dia são uma. O número
> de ações diz em quantos registos, e em quantos dias, houve trabalho concluído —
> não quantas vezes se carregou em «Guardar». É também por isso que não serve
> para medir esforço nem produtividade.

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

**A identidade de uma ação** é o *tipo de registo* do catálogo (`UsageEventType::record()`) mais o registo, nunca o tipo de evento. Os tipos que atuam sobre o mesmo registo partilham-no — e são só estes: relatório (criado, editado, finalizado), instrumento (criado, editado), medida (criada, editada), entrada do caderno (criada, editada), acontecimento (criado, editado) e o estado da aula (lecionada, resultado registado). Num registo partilhado, a ação do dia fica com o tipo da **primeira** operação; por isso, em cada grupo, só o tipo de criação pode ser «registo útil» ou marco, e uma edição a seguir nunca o apaga (`UsageDedupeTest` fixa os grupos). A identidade é um resumo HMAC na coluna única `dedupe_key`: dois pedidos em paralelo sobre o mesmo registo gravam uma linha, e os agregados e o ranking contam linhas, por isso seguem a mesma definição.

Exemplos de registo: a pauta é a da turma, do período e do âmbito (guardar três versões diferentes no mesmo dia é uma ação); a avaliação intercalar é a da turma no período; cada acompanhamento de uma medida é um registo novo; a exportação INOVAR das classificações e a da pauta são registos diferentes.

As edições nunca são «registo útil»: o primeiro registo útil continua a ser criação ou registo. A comparação antes/depois é feita em memória e nunca é guardada.

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

Os primeiros passos são de **primeira observação**. Numa conta criada antes do
início da recolha (ou que retomou a recolha), o passo pode ter acontecido antes,
e o painel di-lo. Cada passo está num de três estados, que o painel nunca
confunde:

- **com data** — o dia da primeira observação, guardado durante o prazo dos
  agregados (180 dias, proposta);
- **«Atingido — data já não conservada»** — passado esse prazo, a data sai e fica
  só o estado; o painel não mostra nenhuma data aproximada;
- **«Ainda não observado»** — o passo não foi visto desde que a pessoa é
  observada. A ausência de recolha diz-se à parte, na nota.

Um passo atingido cuja data saiu **nunca** volta a ganhar uma: nem por eventos
posteriores (uma turma nova não é a primeira) nem por reconstrução a partir de
dados pedagógicos (a agregação só lê eventos de utilização para os primeiros
passos).

## Ativação e desativação

**A única fonte de verdade é a base de dados**: a recolha está ligada enquanto
houver um período aberto em `usage_collection_periods`. Não há chave de
configuração nem variável de ambiente para isto — não há duas fontes que se
possam contradizer. A migração não abre período nenhum: **a recolha nasce
desligada** até à decisão de ativação — ver
[usage-insights-decisions.md](usage-insights-decisions.md).

```bash
php artisan lapis:usage-collection status                       # só lê
php artisan lapis:usage-collection start --operator=<email> --note="…"
php artisan lapis:usage-collection stop  --operator=<email> --note="…"
```

- **Explícito e auditado.** Só `App\Actions\Usage\SetUsageCollection` abre e
  fecha períodos; o operador tem de ter a autorização de **controlo** da recolha
  (ver «Quem tem acesso» — consultar o painel não chega), e cada transição fica
  na auditoria **de plataforma** (`admin.usage_collection_started` /
  `admin.usage_collection_stopped`, com o instante e a nota).
- **No instante da transição.** `start` abre o período nesse segundo, sem
  esperar por atividade; `stop` fecha-o nesse segundo, sem esperar pela
  agregação. Nenhum evento e nenhuma passagem da agregação abrem ou fecham
  períodos.
- **Repetir não duplica.** `start` com a recolha ligada e `stop` com ela
  desligada não escrevem nem auditam; dois `start` em simultâneo esbarram no
  índice único de `is_open`.
- **Desligar bloqueia de imediato.** O recorder prepara o registo durante o
  pedido, mas a escrita (depois do commit) é um único `INSERT … SELECT` que só
  insere se, nesse momento, houver um período aberto que tenha começado antes do
  evento — um pedido que estava a meio quando a recolha foi desligada não grava
  nada.
- **Religar preserva o intervalo.** Um `start` depois de um `stop` abre um
  período novo; o intervalo desligado fica «sem recolha», nunca preenchido.
- **A informação ao titular começa com a recolha.** O instante do primeiro
  `start` é a versão em vigor da Política de Privacidade, que passa a ter a
  secção «Métricas de utilização do serviço»; o aviso não bloqueante reaparece
  a toda a gente com o aviso de oposição, e a opção «Não registar a minha
  utilização» aparece nas definições. Desligar **não** tira a secção: as
  métricas já recolhidas continuam conservadas pelos prazos descritos.

### Procedimento operacional

O estado é lido da base em cada pedido, por isso **o efeito é imediato em todos
os processos**, sem passos à parte:

- **Caches.** Nada a refazer: `config:cache`, `route:cache` e `view:cache` não
  guardam o estado. A memória do estado vive no pedido (instâncias `scoped`:
  `UsageRecorder`, `PrivacyVersionMemo`), nunca no processo.
- **Processos persistentes.** PHP-FPM lê o estado em cada pedido. Um worker de
  filas esquece as instâncias `scoped` antes de cada job (e o recorder não grava
  fora de pedidos com utilizador). O agendador lança cada comando num processo
  novo. Não é preciso `queue:restart` nem reiniciar o FPM para ligar ou
  desligar — só num deploy de código, como sempre.
- **Antes de `start`**: confirmar que o agendador corre `usage:aggregate` (15 em
  15 minutos) e `usage:prune` (de hora a hora, aos 7 minutos), com
  `php artisan schedule:list`; classificar as organizações; ter a validação
  jurídica e a decisão de ativação por escrito.
- **Depois de `start`**: `lapis:usage-collection status`; abrir `/privacidade`
  e confirmar a secção e a data «em vigor desde»; abrir o painel e confirmar a
  nota de início da recolha.

### Publicar com a recolha desligada

O deploy desta versão **não** liga a recolha: as migrações não abrem período
nenhum, e nada na configuração a liga. Depois do deploy normal (runbook em
[deployment.md](deployment.md)):

1. `php artisan migrate:status` mostra as três migrações `2026_11_18_*` como
   `Ran`.
2. `php artisan lapis:usage-collection status` responde «A recolha nunca foi
   ligada.» — e `usage_collection_periods` está vazia.
3. `php artisan schedule:list` mostra `usage:aggregate` (a cada 15 minutos) e
   `usage:prune` (de hora a hora, aos 7). Com a recolha desligada, ambos correm
   sem ler dados de pessoas e sem gravar nada.
4. `/privacidade` continua na versão `2026-09-15`, sem a secção das métricas, e
   a faixa de privacidade não reaparece a ninguém.
5. Nenhuma autorização é concedida pelo deploy: `can_view_usage_insights` e
   `can_control_usage_collection` nascem a `false` para toda a gente.

### Primeira concessão

Feita no servidor, **por email** — nunca pelo nome, que se repete em contas
diferentes. A conta tem de ser já administradora da plataforma; o comando
recusa as outras.

```bash
# 1. Confirmar, só em leitura, que o email é o da conta certa e que é administradora.
php artisan tinker --execute 'echo json_encode(App\Models\User::where("email", "<email>")->first(["id","name","email","is_platform_admin","can_view_usage_insights","can_control_usage_collection"]));'
# 2. Ver o painel (opcional, independente do controlo).
php artisan lapis:grant-usage-insights <email>
# 3. Ligar e desligar a recolha — NÃO liga nada; só autoriza.
php artisan lapis:grant-usage-insights <email> --collection-control
```

A concessão do controlo fica na auditoria de plataforma como
`admin.usage_collection_control_granted`, com `via: console`. A partir daí, quem
tem cada autorização pode concedê-la no backoffice a outro administrador, também
com auditoria.

## Cobertura dos dados

- Desligada a recolha, nada é gravado, a agregação não lê dados de pessoas, o
  painel diz que a recolha está desligada e não há sinais.
- **Não há histórico anterior**: nada foi reconstruído a partir de outras
  tabelas, e os períodos anteriores à ativação aparecem como **«Sem recolha»**,
  nunca como zeros.
- O dia em que a recolha começou é **parcial**. Uma semana ou um mês que
  começou antes da recolha, ou que ainda não terminou, é marcado como parcial
  e não é comparado em silêncio com períodos completos.
- Os dias depois de um `stop` aparecem como «sem recolha», nunca como zeros, e
  os sinais que dependem da atividade ficam por avaliar.
- **Lacunas.** Um dia cujos eventos expiraram antes de serem agregados
  (`usage_coverage_gaps`, ver «Conservação») é «sem recolha» para o painel e
  para os sinais, e o painel diz quais são.
- **Cobertura própria de cada pessoa.** Quem retirou a oposição, ou cancelou um
  pedido de encerramento, é observado só a partir desse instante
  (`users.usage_resumed_at`): os dias anteriores são «sem recolha» para ela.
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
atividade de quem se opôs à recolha (`usage_opted_out_at`) ou pediu o
encerramento da conta.

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

Contas desativadas ou anonimizadas não geram sinais. **Quem se opôs à recolha
ou pediu o encerramento da conta não entra em número nenhum** — nem na lista,
nem no ranking (nem contado como «sem atividade»), nem nos denominadores, nem
nos sinais, nem como «por avaliar»: as suas métricas foram eliminadas, e contá-lo
fá-lo-ia parecer inativo por ter exercido um direito. O painel diz só quantas
pessoas se opuseram. Uma conta que a agregação ainda não viu (criada há menos de
15 minutos) não é avaliada.

**Lacunas e retomas.** Um dia de lacuna ou anterior à retoma da recolha de uma
pessoa conta como recolha insuficiente: «Turma sem primeiro registo útil» e «Sem
atividade recente» ficam por avaliar se a janela da regra os incluir, e o motivo
de «Falhas técnicas repetidas» diz «desde … (retoma da recolha)».

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
recolha e a conta já existia (ou, para quem retomou a recolha, desde a retoma).
Uma conta recente aparece como «observação parcial (12 de 30 dias)» e pode ser
filtrada à parte (antiguidade: com observação completa, menos de 30 dias, 30 a
90, mais de 90).

## Conservação

Valores **propostos e configuráveis**, por validar juridicamente (a validação
**não** está concluída) — fundamentação em
[usage-insights-decisions.md](usage-insights-decisions.md) §2.

| Dados | Prazo | Quem o executa |
|---|---|---|
| Eventos detalhados (`usage_events`) | **35 dias** a contar do instante do evento (`retention.usage_events_days`, `LAPIS_USAGE_EVENTS_RETENTION_DAYS`) — **agregados ou não** | `usage:prune`, de hora a hora (aos 7 minutos) |
| Agregados diários por pessoa (`usage_daily_rollups`) | **180 dias** depois do dia a que se referem (`retention.usage_rollups_days`, `LAPIS_USAGE_ROLLUPS_RETENTION_DAYS`); é também o período mais antigo que o painel aceita | `usage:prune` |
| Lacunas (`usage_coverage_gaps`: só o dia e o motivo) | o mesmo dos agregados | `usage:prune` |
| Data de cada primeiro passo (`usage_user_states.first_*_on`) | **180 dias** — o mesmo prazo dos agregados; depois sai e fica só «atingido» | `usage:prune`, de hora a hora |
| Estado «atingido» de cada primeiro passo (`first_*_reached`, sem data) | enquanto a conta existir | apagado na oposição, no pedido de encerramento e na anonimização |
| Tudo o que é deste painel, para quem pediu o encerramento da conta | **eliminado no pedido**, não no dia 60; cancelar o pedido não recupera nada | `RequestPersonalAccountClosure` → `ForgetUsageData` |
| Tudo o que é deste painel, para quem se opôs | eliminado no momento | definições da conta (ou `lapis:usage-opt-out`) → `SetUsageOptOut` → `ForgetUsageData` |

### Precisão dos prazos

A limpeza corre de hora a hora, e o prazo dos eventos conta-se ao segundo
(`occurred_at` mais antigo do que agora − 35 dias). Por isso **nenhum evento vive
mais do que 35 dias e uma hora** (mais a duração da própria passagem). Antes,
com uma passagem diária às 04:05 e o corte por dia, um evento podia chegar a 36
dias e 4 horas.

Os agregados, as lacunas e as datas dos primeiros passos são por dia: os do dia
D saem na primeira passagem do dia D+181 (às 00:07), isto é, ficam 180 dias
completos depois de D e, no máximo, mais uma hora. A data de um primeiro passo
observado a D é substituída por «atingido» nessa mesma passagem.

Se o agendador parar, nada é apagado enquanto estiver parado: o atraso é o tempo
da paragem. Cada falha do comando é reportada (`onFailure`), como as das outras
rotinas de retenção.

### A limpeza não depende da agregação

- Os eventos saem ao prazo, mesmo com a agregação parada e mesmo com a recolha
  desligada.
- Se a agregação esteve parada mais tempo do que o prazo, os dias cujos eventos
  expiram **sem terem sido agregados** — o dia D com `aggregated_through ≤ D` —
  ficam registados em `usage_coverage_gaps`, só com o dia e o motivo
  (`expired_unaggregated`). Nada do que expirou é guardado, e os agregados
  parciais desses dias (de uma passagem a meio do dia) saem.
- Para o painel e para os sinais, uma lacuna é um dia **sem recolha**: nunca
  zero, nunca inatividade. O painel lista os dias e explica porquê.
- **Sem reconstituição**: quando a agregação volta, recalcula a partir de onde
  parou mas salta os dias de lacuna, e em cada passagem apaga agregados que
  uma corrida com a limpeza lhes tenha escrito.

**Nenhuma agregação reconstitui o que foi apagado:**

- o prazo efetivo dos eventos nunca é maior do que o dos agregados;
- a agregação nunca recalcula um dia anterior ao limite dos agregados, nem um
  dia de lacuna;
- a agregação ignora, ao ler os eventos, quem se opôs, pediu o encerramento ou
  foi anonimizado, e apaga em cada passagem qualquer agregado ou estado que lhes
  reste (a corrida com uma eliminação a decorrer).

### Datas dos primeiros passos

`usage_user_states` guarda, por pessoa e para cada primeiro passo (primeira
turma, primeiros alunos, primeiro registo útil):

- a **data** da primeira observação (`first_*_on`, o dia sem hora), durante **180
  dias** — o prazo dos agregados, e por isso o horizonte de tudo o que o painel
  mostra;
- o **estado** «atingido» (`first_*_reached`), enquanto a conta existir. Uma data
  só existe num passo atingido (CHECK em MySQL).

Passados os 180 dias, `usage:prune` apaga a data e o passo fica «atingido» sem
data — também com a recolha desligada. A agregação nunca volta a datá-lo.

**Porque fica o estado:** sem ele, ao fim de 180 dias o painel esqueceria que a
pessoa já deu o passo, e «Turma sem primeiro registo útil» acusaria de falta de
utilização quem usa o Lapispro há um ano. O sinal só precisa do sim/não.

**Os cálculos que precisam de datas não usam «atingido»:** a atividade num
intervalo, as semanas, as coortes, o ranking e a última atividade leem os
agregados diários, e nenhum lê a data de um primeiro passo. A visão geral conta
«primeiro registo útil: n de N» como **estado até hoje** das contas criadas no
período — e uma conta criada no período (no máximo 180 dias) tem ainda a data
guardada. O detalhe de uma pessoa mostra «Atingido — data já não conservada»,
sem data aproximada.

Há ainda uma data de **configuração** (`class_available_since`: quando a primeira
turma ficou disponível), recalculada em cada passagem a partir de
`class_teachers`. Não é uma métrica de utilização nem um primeiro passo, e não
dá data a nenhum.

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
- **Nada é lido para medir quando nada vai ser registado.** As impressões
  digitais das edições (que leem o relatório, a medida, o instrumento, o
  acontecimento ou a matrícula) só são calculadas quando o pedido pode registar
  — recolha ligada, sem acesso técnico, sem oposição, sem encerramento pedido,
  com organização resolvida (`UsageRecorder::isRecording()`).
- **A escrita confirma o estado.** Depois do commit, o evento é gravado por um
  único `INSERT … SELECT` que só insere se, nesse instante, houver um período de
  recolha aberto (iniciado antes do evento) e a pessoa não se tiver oposto, não
  tiver pedido o encerramento, não estiver desativada ou anonimizada e não tiver
  retomado a recolha depois do evento. Um pedido a meio quando a recolha é
  desligada, ou quando a pessoa se opõe, não grava nada.
- Só pode haver **um** período de recolha aberto: um índice único
  (`usage_collection_periods.is_open`) impede que dois `start` simultâneos o
  abram duas vezes.
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

**Direito de oposição.** O titular opõe-se nas **definições da conta** — «Não
registar a minha utilização», secção «Métricas de utilização» do perfil
(`PUT /settings/usage-metrics`, só a própria pessoa e nunca durante um acesso de
suporte). Na mesma transação, com a linha da pessoa bloqueada
(`App\Actions\Usage\SetUsageOptOut`):

- grava `users.usage_opted_out_at`, e a recolha deixa de registar o que a pessoa
  faz;
- elimina os seus eventos, agregados e datas de primeiros passos;
- um pedido que estava a meio não grava depois (a escrita confirma a oposição),
  e a agregação ignora-a e volta a apagar os restos em cada passagem;
- **nada mais muda**: dados pedagógicos, funcionalidades e acesso ficam iguais,
  e a pessoa sai de todos os números do painel — nunca aparece inativa nem «por
  avaliar» por se ter oposto.

Voltar a desmarcar liga a recolha **a partir desse instante**
(`users.usage_resumed_at`), sem histórico: o que foi eliminado não volta, e um
pedido de antes da oposição que só escrevesse depois não é aceite. Para essa
pessoa, os dias anteriores são «sem recolha», os primeiros passos dizem «Recolha
retomada a …» e os sinais que precisariam do passado ficam por avaliar.

Uma oposição recebida por outro canal (email para o contacto de privacidade)
executa-se com `php artisan lapis:usage-opt-out <email>` (`--undo` para
retomar), que usa a mesma ação.

**Encerramento da conta.** O pedido de encerramento pára a recolha e elimina de
imediato as métricas da pessoa (`RequestPersonalAccountClosure`), não no dia 60.
Cancelar o pedido não as recupera; a recolha recomeça a partir do cancelamento,
com cobertura própria, salvo se a pessoa se tiver oposto antes.

**Aviso proposto** (texto de comunicação, **não** validação jurídica), na
Política, no aviso não bloqueante da ativação e nas definições:

> «Pode opor-se à recolha de métricas de utilização nas definições da sua conta.
> Ao fazê-lo, a recolha é interrompida e as métricas associadas à sua conta são
> eliminadas, sem afetar o acesso às funcionalidades do Lapispro.»

A redação pública está na Política de Privacidade (secção «Métricas de
utilização do serviço»), que **só a mostra depois de a recolha ter sido
ativada**: até lá a Política fica como estava, e o instante da ativação passa a
ser a versão em vigor, para o aviso não bloqueante reaparecer exatamente quando
a recolha começa (`App\Support\Legal\PrivacyVersion`). A validação jurídica
desta ponderação continua pendente — ver
[usage-insights-decisions.md](usage-insights-decisions.md) e
[legal.md](legal.md#pontos-que-continuam-a-exigir-validação-jurídica).

## Limitações conhecidas desta versão

- Sem tempo ativo, sem duração de sessão, sem último login — de propósito.
- Um lote é um registo próprio (a operação conjunta). Editar depois, no mesmo
  dia, um dos elementos que o lote criou ou tocou é uma ação sobre outro registo
  e conta à parte — por exemplo, criar duas medidas de uma vez e corrigir uma
  delas, ou marcar as aulas do dia em lote e depois registar o resultado de uma.
  Uma criação individual seguida de edições, no mesmo dia, é sempre uma ação.
- Na importação do calendário da escola só se sabem os elementos criados; o
  ecrã mostra só as parcelas conhecidas, nunca «inalterados 0».
- O plano é o de hoje; não há reconstrução de planos passados.
- A lista, o ranking e os sinais são calculados no servidor sobre todas as
  pessoas do âmbito a cada abertura (sobre os agregados, nunca sobre dados
  pedagógicos). É folgado para centenas de contas; com milhares, os sinais e os
  dias não letivos devem passar a ser calculados pela agregação.
- A última atividade da lista é a do âmbito filtrado; a do detalhe é a de todas
  as organizações da pessoa, e o ecrã di-lo.
