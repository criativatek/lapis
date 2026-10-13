# Registos — consulta por pesquisa e acompanhamento à 3.ª ocorrência (0.164.0)

Pedido de 2026-10-12. Três coisas numa só fatia, porque partilham a mesma
contagem: a consulta de registos deixa de abrir numa lista extensa; três
acessos diretos mostram TPC não realizados, atrasos e faltas de material do
período; e a 3.ª ocorrência do mesmo tipo passa a deixar um alerta persistente,
com uma mensagem pronta a copiar para o Inovar e a confirmação, pelo professor,
de que a registou lá.

## O que já existia (e o que lhe acontece)

- **Registos** (`evidence_records`, `EvidenceKind`): `homework` com
  `homework_status` (`done` · `partially_done` · `not_done`), `lateness`,
  `missing_material`. Uma linha por aluno (a grelha multi-aluno cria linhas
  independentes); `enrollment_id` nulo = registo da turma inteira.
  `occurred_at` é DATETIME (a app grava meia-noite; a importação pode trazer
  hora). Soft delete. **Não há ligação à aula** (`lesson_id` nunca foi
  criado) e a app **nunca preenche `academic_period_id`** (só a importação).
- TPC novo só nasce pela grelha (`updateHomeworkBatch`): turma + dia é a
  identidade do lote; «sem informação» = não há linha (apaga a existente).
  Não existe «dispensado»: o que não foi verificado simplesmente não tem linha.
- **Aviso de acumulação** (`DetectEvidenceAccumulationWarnings`, 0.125.0):
  toast ao gravar, só `lateness`/`missing_material`, contagem na turma sobre o
  ano inteiro, repetido em cada múltiplo de 3. Não persistia, a grelha de TPC
  e a importação nunca o disparavam e regravar o 3.º registo voltava a
  mostrá-lo. **Decisão do dono do produto (2026-10-12): substituir** — ver
  abaixo. A classe é removida; não fica uma segunda contagem.
- Assiduidade das aulas (`lesson_attendances`) só tem presente/falta: os
  atrasos vivem só nos registos.
- Períodos (`academic_periods`): livres por ano letivo, sem estado útil
  (nascem todos `draft`), sem validação de lacunas nem sobreposições. Não havia
  «período atual» em lado nenhum.
- Notificações: não há tabela `notifications` nem caixa de entrada. O que
  existe é o toast (`Inertia::flash`) e avisos calculados (painel do aluno).
- Sessões de apoio técnico: escrevem registos (ADR-0014, acesso total por
  desenho); as escritas pessoais recusam-se com `RefusesDuringImpersonation`.

## Regras de contagem (uma só, usada em todo o lado)

`App\Services\Evidence\Followup\RecordFollowupCounter` é a única porta. Resumos,
listas, detalhe, alertas e mensagem chamam-na; nada recalcula por conta própria.

- **Ocorrência** = uma linha de `evidence_records` não eliminada, com aluno
  (`enrollment_id` não nulo), de uma das três categorias
  (`RecordFollowupCategory`):
  - `homework_not_done` — `kind = homework` **e** `homework_status = not_done`.
    `partially_done` e `done` não contam; TPC sem linha (não verificado) não
    existe para a contagem. Nada é convertido.
  - `lateness` — `kind = lateness`.
  - `missing_material` — `kind = missing_material`.
- **Unidade**: cada linha conta uma vez. Duas linhas do mesmo aluno no mesmo
  dia contam duas (ex.: dois tempos); a mensagem di-lo («03/10 (duas
  ocorrências)») sem inventar um segundo dia. Nenhuma junção multiplica
  linhas: nenhuma consulta junta tabelas que multipliquem linhas (grupos,
  horários e aulas não entram). «Alunos» nos cartões conta o que a lista
  mostra: um por aluno e turma (inscrição) — um aluno seguido em duas
  disciplinas é duas linhas lá e dois aqui.
- **Chave**: turma (`class_id` — uma turma é uma disciplina de um ano letivo) ×
  inscrição × categoria × período. Categorias nunca se somam. Um aluno inscrito
  em duas turmas da mesma disciplina (ex.: turma de apoio) tem contagens
  separadas — cada uma pertence a quem ensina essa turma.
- **Data pedagógica**: `occurred_at`, nunca `created_at` nem
  `academic_period_id`. Um registo pertence ao período cujo intervalo
  `[starts_on, ends_on]` (inclusivo, dia inteiro) contém a sua data.
- **Sobreposição de períodos** (configuração possível, não validada): cada data
  pertence ao **primeiro período por sequência** que a contém — a mesma
  convenção do calendário (`AcademicYearCalendarController::days`). Assim um
  registo nunca conta em dois períodos. O ecrã avisa quando há sobreposição.
- **Sem período**: um ano sem períodos, ou uma data fora de todos, não tem
  contagem por período nem alerta. Mostra-se sempre: «Para receber alertas de
  acumulação, configure os períodos de avaliação e confirme as respetivas
  datas.» **Nunca se assume o ano inteiro como período.** A consulta por
  intervalo de datas continua a funcionar.
- Correções e eliminações refletem-se logo: tudo é lido ao vivo dos registos.

## Alerta à 3.ª ocorrência

- **Limiar 3, uma vez por chave.** Dispara quando uma operação faz a contagem
  passar de **< 3 para ≥ 3** (2→3, ou 2→4 numa só operação: um alerta, com a
  contagem real). 3→4, 5→6, 6→9 não alertam. Sem ciclos.
- Operações que podem cruzar: criar registo(s), editar registo (tipo, aluno,
  data, estado do TPC), gravar a grelha de TPC. Eliminar só desce. A
  importação de backup **não** alerta (restaura história; os casos ficam
  identificáveis na lista). Mudar as datas dos períodos não alerta.
- **Persistência**: `record_followup_cases`, uma linha por chave
  (`UNIQUE(class_id, enrollment_id, academic_period_id, category)`),
  `alerted_at` + `alerted_count`. A contagem antes é lida dentro da mesma
  transação, depois de bloquear a turma (`lockForUpdate`, como a grelha já
  fazia); o alerta entra com `insertOrIgnore` (ou passa `alerted_at` de nulo
  para agora numa linha criada por uma confirmação). Regravar, repetir o pedido
  ou gravar em concorrência nunca cria segundo alerta; abrir páginas nunca
  escreve.
- **Casos anteriores** (≥ 3 antes desta versão): sem migração de dados, sem
  notificação retroativa. Aparecem nas listas como «Por tratar», com a
  indicação «anterior aos alertas»; nada os marca como tratados.
- **Onde se vê**: «Alertas por tratar» no topo da consulta de Registos (todo o
  ano letivo, não só o período escolhido, com o período em cada linha) e um
  cartão no Início. O toast ao gravar complementa (só quando o alerta acabou de
  nascer), nunca é a única marca.
- Um alerta sai de «por tratar» quando o professor confirma o registo no Inovar
  ou quando a contagem desce abaixo de 3 (correção/eliminação); volta se subir
  outra vez, na mesma linha.

## Mensagem para o Inovar

Gerada no servidor (`InovarFollowupMessage`), editável no ecrã, nunca
gravada. Modelos impessoais pedidos, com a quantidade por extenso no feminino
(«três faltas», «três situações»), as datas `dd/mm` agrupadas por dia, o
período pelo seu nome («no 1.º Período») ou, num intervalo personalizado, «no
período de dd/mm/aaaa a dd/mm/aaaa». Nome = primeiro e último nome de
`display_name` (sufixos Júnior/Filho/Neto acompanham o apelido). Falta de
número ou nome: a frase perde esse troço sem deixar «null» nem vírgulas soltas,
e o ecrã pede revisão. A mensagem cobre exatamente as ocorrências
apresentadas — com quatro, diz «quatro» e as quatro datas.

## Confirmação «Registado no Inovar»

- `record_followup_confirmations`: quem, quando, quantas e quais
  (`occurrences` = lista `{ulid, date}`), e `undone_at`/`undone_by` para
  desfazer sem apagar história. Uma ativa por caso (bloqueio da linha do caso).
- Pedido leva os ULID que o professor viu; se os registos mudaram entretanto,
  recusa e pede revisão (nunca confirma um conjunto diferente do mostrado).
- Copiar não confirma. Confirmar exige um diálogo explícito («A aplicação não
  verifica o Inovar»). Só em modo período (a chave tem período) e só com três
  ou mais ocorrências — uma declaração abaixo do limiar levaria consigo o
  único alerta da chave quando a 3.ª chegasse.
- O pedido leva `{ulid, date}` de cada ocorrência mostrada: uma data mudada
  dentro do período também conta como «os registos mudaram».
- Depois de confirmar: totais e ocorrências continuam visíveis; se os registos
  de origem mudarem, diz-se o que mudou («1 registo deixou de contar · 1 novo
  desde a confirmação») e que o registo no Inovar não é alterado pela
  aplicação.
- Escrita: `Gate::update` da turma, recusada em sessão de apoio técnico (é uma
  declaração do professor). Auditoria com ids, nunca nomes.

## Ecrãs

- **`/records` (consulta)**: filtros (ano letivo, turma, disciplina quando há
  mais de uma, aluno quando há turma, tipo, período **ou** intervalo de datas),
  «Pesquisar» e «Limpar filtros»; período em curso pré-selecionado quando hoje
  cai num período; senão pede a escolha. Resumo compacto = três cartões
  («6 alunos · 14 ocorrências», mais «2 por tratar») e os alertas. **Sem lista
  à entrada**: «Defina os filtros e carregue em Pesquisar» ≠ «Nenhum registo
  corresponde». Resultados paginados no servidor (25), com abrir/editar.
- Cartão aberto (`view=<categoria>`): só alunos com ocorrências, n.º, nome,
  turma, quantidade e estado, ordenados por quantidade (desempate turma,
  n.º, id), paginados. O detalhe (datas, registos, mensagem, confirmação)
  carrega a pedido (JSON) num diálogo; o caso aberto fica no URL.
- **`/classes/{turma}/records` (registar)**: formulário igual; a lista passa a
  ser «Registos mais recentes» (10) e um atalho para a consulta da turma.
  `?registo=<ulid>&voltar=<url da consulta>` abre esse registo em edição e
  oferece «Voltar à consulta» com filtros, página e caso intactos.

## Faltas de presença (acrescentado a 2026-10-13)

- Quarto acesso direto da consulta, lido da fonte de verdade da assiduidade
  (`lesson_attendances`), sem criar registos pedagógicos duplicados.
- Conta uma falta quando a aula tem a assiduidade consolidada
  (`attendance_recorded_at`) e conta (`outcome` nulo ou `taught`) —
  `Lesson::whereAttendanceCounts`, a mesma regra da Evolução do aluno e do
  resumo da turma — e o aluno está `absent`. Rascunhos, assiduidade por
  lançar, aulas canceladas ou sem assiduidade aplicável e atrasos nunca
  contam; uma correção ou anulação reflete-se de imediato (nada é guardado).
- Uma falta = uma aula. A data é a da aula (`starts_at`, hora local); o
  período é o que contém essa data, pela mesma regra dos registos.
- O detalhe mostra faltas e dias à parte («5 faltas em 3 dias»), cada aula
  com data, hora e número, «Abrir aula» e regresso à consulta; diz quantas
  aulas dadas estão ainda sem assiduidade (que não são faltas).
- Não há distinção justificada/injustificada: a aplicação não a regista, e o
  ecrã di-lo.
- **Sem alerta à 3.ª ocorrência e sem mensagem para o Inovar** (decisão de
  2026-10-13).

## Alunos distintos (acrescentado a 2026-10-13)

«Alunos» conta alunos distintos pela identidade da aplicação
(`enrollments.student_id`, ou `lesson_attendances.student_id`), nunca pelo
nome. Os «casos» (aluno × turma) dizem-se à parte quando diferem, e cada caso
tem a sua linha e o seu alerta. Limitação: só as turmas de apoio
(`enrollExisting`) e o restauro de backups partilham um aluno entre turmas —
adicionar um aluno a uma turma normal cria sempre uma identidade nova, por
isso o mesmo nome em duas turmas pode ser dois alunos.

## Backup (acrescentado a 2026-10-13)

Casos e declarações viajam no backup desde a v15 — regras em
`docs/backup-schema.md` («Acompanhamento dos registos»). O restauro nunca corre
a regra do alerta.

## Registar e panorama (acrescentado a 2026-10-13, 0.165.0)

A 0.164.0 tornou «Registos» uma consulta e deixou o registo diário a três
passos (a grelha de TPC só aparecia dentro do formulário da turma, depois de
mudar o tipo). Correção: três separadores — `tpc` («Registar TPC»), `panorama`
(«Panorama da turma») e `search` («Pesquisar registos») — com o separador por
omissão «Registar TPC», e a pesquisa quando o URL traz o seu próprio estado.

- **Turma**: do URL, ou a última escolhida nesta sessão (`records.class`, só se
  for do ano em uso); sem nenhuma, a página pede-a. A aplicação não tem uma
  turma global (a barra de contexto só a mostra nas páginas de uma turma).
- **Grelha** (`HomeworkGridRoster`): o rol da data — `enrolledOn(data)` e ativa
  ou com data de saída (a regra da assiduidade); ambígua fica de fora e
  contada. Linhas só de leitura: dois registos de TPC no mesmo dia
  (`duplicate`, nunca fundidos) e aluno que saiu sem marcação nesse dia
  (`left`). Marcação nova: aluno ativo e na turma na data; correção de uma
  existente nunca recusada. O modelo não tem um «TPC» como entidade: turma +
  dia é a identidade, uma marcação por aluno e dia.
- **Gravação**: só as linhas alteradas; uma marcação igual não é regravada,
  nem auditada, nem contada nas métricas. Alertas e casos pelo mesmo caminho
  (`RecordFollowupAlerts`, turma bloqueada).
- **Panorama** (`ClassRecordPanorama`): rol da janela (quem esteve na turma em
  algum dia dela, mais quem tem alguma contagem), contagens por consultas
  agrupadas sobre `RecordFollowupCounter` e `ConsolidatedAbsences` (o mesmo
  número dos cartões), estados dos casos, cobertura (dias com TPC marcado,
  aulas lançadas e por lançar). `null` com `no_data` / `not_applicable` —
  nunca um zero inventado. Ordenação, filtro e pesquisa no browser, sobre a
  tabela de uma turma.

## Índices e desempenho

Contagens por `GROUP BY` no servidor; índice novo
`evidence_followup_idx (organization_id, class_id, kind, occurred_at)`. O browser
só recebe a página pedida e os agregados.

## Fora de âmbito (decidido)

Integração/envio para o Inovar; envio externo automático; ocorrência
disciplinar automática; ciclos aos 6/9; ligação registo↔aula; alerta e
mensagem para as faltas de presença.
