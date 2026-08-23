# Aulas e Sumários — aula, sumário, planeamento, sequência e cumprimento

## Estado

Desenho aprovado em 2026-08-23. Esta especificação fixa o modelo conceptual e
as fronteiras da primeira implementação. Não autoriza deploy, alterações em
produção, IA, calendário externo ou um sistema paralelo de TPC/presenças.

## Objetivo

Dar ao professor um centro operacional semanal para preparar aulas com
antecedência, escrever ou rever um sumário em segundos, reutilizar progressões
entre turmas compatíveis, registar TPC e faltas sem sair da aula e transportar
conteúdo pendente de forma explícita e segura.

O módulo não é um plano de aula formal. O único campo sempre visível é o
sumário. Notas privadas, recursos e TPC são opcionais e recolhidos por defeito.

## Conceitos e invariantes

Os cinco conceitos são distintos, ainda que façam parte do mesmo agregado:

- **Aula (`Lesson`)** — ocorrência concreta de uma turma numa data/hora.
- **Sumário (`LessonSummary`)** — descrição oficial concisa do que foi ou será
  trabalhado na aula.
- **Planeamento (`LessonPlan`)** — preparação antecipada e editável de uma aula.
- **Sequência (`LessonSequence`)** — progressão reutilizável por disciplina e
  ano de escolaridade, composta por itens ordenados.
- **Cumprimento (`LessonFulfillment`)** — relação entre o planeado e o que
  aconteceu, separada do estado operacional da aula.

`SchoolClass` continua a ser a fonte de ano letivo, disciplina e ano de
escolaridade. `Lesson` não duplica essas colunas. A data da aula determina o
período através dos intervalos de `AcademicPeriod`.

## Modelo persistente

### Horário e ocorrência

`recurring_lesson_slots` guarda o horário recorrente da turma: organização,
turma, dia da semana, hora inicial/final e vigência opcional. Não é um evento da
Agenda e não guarda feriados, interrupções ou visitas.

`lessons` guarda organização, turma, slot opcional, início, fim opcional,
estado, autor e ULID. O estado tem exatamente três valores nesta tranche:

- `preparation` — Por preparar;
- `prepared` — Preparado;
- `taught` — Lecionado.

Não existe estado `reviewed`: uma revisão é metadata do sumário e um evento de
auditoria, não outro passo obrigatório do workflow.

Uma ocorrência ordinária é única por turma, slot e início. Aulas extraordinárias
podem não ter slot, mas continuam a ter turma e início explícitos. A leitura da
vista semanal nunca materializa aulas como efeito lateral de um GET; criação a
partir do horário é uma Action explícita e idempotente.

### Planeamento e sumário

`lesson_plans` tem uma linha por aula, `planned_summary`, autor e proveniência
opcional (`source_sequence_id`, `source_sequence_item_id`, `source_lesson_id`).
Pode ser criado muito antes da data real.

`lesson_summaries` tem uma linha por aula e guarda apenas o conteúdo oficial,
`reviewed_at` e `reviewed_by`. O texto planeado nunca é copiado silenciosamente
para o texto lecionado. A interface pode propô-lo como ponto de partida, mas o
professor confirma e pode alterá-lo.

Uma aula sem planeamento prévio é válida: o professor pode abrir a ocorrência,
escrever o sumário e marcá-la como lecionada.

### Notas privadas

`lesson_private_notes` separa estruturalmente nota, aula e autor. A nota é
legível/editável pelo próprio autor dentro do tenant e não entra em sumários
oficiais, relatórios, PDFs, DOCX ou exportações pedagógicas por defeito. Uma
eventual exportação pessoal de dados do próprio utilizador é um fluxo distinto
e terá de ser revista explicitamente quando esta tabela entrar no domínio de
portabilidade.

Notas privadas nunca são copiadas por defeito. “Usar noutra turma”, “Basear no
sumário anterior” e aplicação de sequência apresentam a opção desmarcada.

### Recursos

`lesson_resources` guarda aula, rótulo e URL ou referência textual. Não há
upload de ficheiros nesta tranche: o projeto só tem storage especializado para
fotografias, logótipos, importações e exports, não um gestor documental geral.

### Cumprimento e conteúdo pendente

`lesson_fulfillments` tem uma linha por aula e liga o plano ao desfecho. Guarda:

- `fulfilled`, `partially_fulfilled` ou `not_taught`;
- conteúdo restante opcional;
- autor e instante da decisão.

Cumprimento não altera automaticamente `Lesson.status`, nem vice-versa. Ao
fechar/rever, a UI pode coordenar as duas decisões, mas os invariantes são
validados separadamente.

`partially_fulfilled` e `not_taught` podem produzir conteúdo pendente. Nada é
transportado automaticamente.

### Sequências

`lesson_sequences` guarda organização, disciplina, ano de escolaridade, dono,
nome, período opcional e arquivo opcional. `lesson_sequence_items` guarda posição,
sumário planeado, template opcional de nota privada e descrição opcional de TPC.
`lesson_sequence_item_resources` guarda recursos reutilizáveis do item.

Uma sequência não pertence a uma turma e não é calendário. Aplicá-la exige
turmas do mesmo tenant, disciplina e ano de escolaridade, lecionadas pelo ator.

## Cópias independentes e proveniência

Aplicar uma sequência resolve as próximas aulas concretas de cada turma através
dos respetivos horários, apresenta preview por turma e só escreve após confirmação.
Se uma turma não tiver ocorrências/horário suficientes, a confirmação inteira
fica bloqueada e o preview explica o que falta nessa turma; nunca há escrita
parcial silenciosa nem se assumem datas comuns entre turmas.

Cada destino recebe `LessonPlan`, recursos e TPC operacionais próprios. A
proveniência é informativa. Depois da aplicação:

- editar a sequência não altera destinos existentes;
- editar 7.º A não altera 7.º B/C;
- não existe sincronização viva nem leitura dinâmica da sequência;
- duplicar volta a copiar valores, não relações operacionais partilhadas.

Reutilização parcial oferece Sumário, Recursos, TPC e Notas privadas. Notas
privadas começam desmarcadas. “Basear no sumário anterior” cria cópia editável e
guarda apenas `source_lesson_id`.

## Continuidade

Três Actions transacionais distintas implementam os três comportamentos:

1. `MergeRemainingContentIntoNextLesson` — combina pendente e plano seguinte;
   apresenta o texto combinado editável e nunca sobrescreve antes de confirmar.
2. `InsertRemainingContentAndShiftPlannedLessons` — insere o pendente como próxima
   aula e desloca planeamentos futuros; o preview identifica todas as aulas e a
   quantidade afetada.
3. `CreateLessonFromRemainingContent` — cria a próxima aula apenas com o restante,
   sem deslocar automaticamente outros planos.

Preview e confirmação partilham o mesmo resolvedor de impacto. A confirmação
revalida o estado sob transação e `lockForUpdate`; um preview antigo nunca é
autoridade para escrever.

Nenhuma Action desloca aula lecionada, sumário revisto, registo histórico fechado,
turma fora do tenant ou aula que o professor não pode atualizar. Um conflito
interrompe toda a operação sem perda parcial de conteúdo.

## TPC canónico

Não existe `LessonHomework`.

`EvidenceRecord` continua a ser o domínio canónico:

- atribuição à turma: `kind=homework`, `lesson_id` preenchido,
  `enrollment_id=NULL`, `homework_status=NULL`, descrição com a tarefa;
- verificação individual: linha por aluno com o `HomeworkStatus` já existente;
- Registos e Aulas consultam e editam as mesmas linhas.

A validação passa a exigir `homework_status` apenas numa verificação individual.
Uma atribuição de turma não finge ser “realizada”. Linhas sem informação nunca
se transformam em `not_done`.

## Faltas e atrasos canónicos

`EvidenceKind` recebe `Absence` e `Late`; não se cria tabela de presenças.
Cada ocorrência guarda aula, turma, enrollment, data/hora, autor e tenant na
mesma `evidence_records`.

`UNIQUE(lesson_id, enrollment_id, kind)` evita duplicados. Corrigir restaura ou
atualiza a linha canónica. A aplicação valida que o enrollment pertence à turma
da aula e está válido para uma entrada nova. Nunca cria linhas “Presente” para
os restantes alunos.

A entrada rápida em Aulas e Sumários e a consulta/edição em Registos são dois
pontos de entrada para o mesmo dado, sem sincronização manual.

## Agenda e estrutura temporal

A Agenda do Ano Letivo continua placeholder. Esta tranche reutiliza apenas:

- limites de `AcademicYear`;
- intervalos de `AcademicPeriod`;
- ano letivo da turma;
- contexto temporal existente.

Não cria `calendar_events`, feriados, interrupções, visitas ou bloqueios. Quando
a Agenda real existir, a vista semanal consumirá um read model canónico de
eventos por intervalo; não copiará eventos para `lessons`.

### Dívida conhecida: edição de períodos

`AcademicYearService::syncPeriods()` ainda substitui todos os períodos. A FK
opcional `lesson_sequences.academic_period_id` usa `nullOnDelete()`, nunca
`cascade`, para uma eliminação de período não apagar sequências, aulas ou planos.

Nesta tranche não se reescreve `AcademicYearService`. Esta é dívida conhecida:
quando outros agregados dependerem da identidade estável do período, o serviço
terá de evoluir para diff com proteção de referências. Se `nullOnDelete()` e as
policies existentes se revelarem insuficientes durante implementação, o trabalho
para antes de expandir esse âmbito e o utilizador decide o passo seguinte.

## Autorização, tenancy e impersonation

Todos os novos modelos tenant-owned usam `BelongsToOrganization`, ULID em URLs e
Form Requests com `BelongsToCurrentOrganization` para referências tenant-owned.
Policies derivam a autorização operacional da turma: apenas professores ligados
à turma podem ver/alterar aulas; sequência exige dono ou regra explícita de
partilha futura, que fica fora desta tranche.

Turma, aluno, sequência, disciplina, período e aula cross-tenant são rejeitados.
Turmas compatíveis têm a mesma disciplina e o mesmo `grade_level`, além de estarem
no ano letivo/contexto autorizado.

### Decisão de impersonation

Todas as mutações de Aulas e Sumários são recusadas durante impersonation através
de `RefusesDuringImpersonation`, incluindo horário, aula, plano, sumário, notas,
recursos, sequência, cumprimento, continuidade, TPC e faltas/atrasos criados pelo
módulo. A leitura pode continuar quando a impersonation já autoriza o contexto.

A recusa não se limita a notas privadas: qualquer escrita ficaria atribuída ao
professor impersonado e pareceria uma decisão pedagógica sua. As rotas de Registos
já existentes não são globalmente alteradas nesta tranche; os novos endpoints de
entrada rápida recusam a mutação. Uma harmonização global de impersonation em
Registos requer decisão separada para não expandir silenciosamente o âmbito.

## Auditoria e revisão histórica

Criar, preparar, lecionar, rever após lecionação, registar cumprimento, aplicar
sequência e confirmar continuidade emitem eventos através de `AuditLog`. O evento
guarda intenção, ids/ULIDs e metadata de estado, não cópias de notas privadas ou
texto sensível.

Uma correção após `taught` é permitida, atualiza `reviewed_at/by` e deixa evento de
auditoria. Não há versionamento textual pesado nesta tranche.

## Vista semanal e performance

A vista semanal é a entrada principal e carrega apenas o intervalo selecionado.
Hoje, semana anterior, atual e seguinte são atalhos para a mesma consulta.

O read model traz aulas, turma/disciplina já carregadas, estado, início do sumário
e contadores/flags de TPC, recursos, faltas, atrasos e pendente. Não carrega roster
ou texto integral opcional até abrir a aula. Queries usam eager loading, agregados
e índices por organização/turma/data; não percorrem o ano inteiro.

O ContextBar existente continua a ser a fonte de ano letivo, disciplina, ano,
turma e período. O módulo pode atravessar turmas compatíveis na semana, respeitando
o ano letivo e disciplina selecionados. Não cria contexto paralelo.

## UX obrigatória, sem desenho linha a linha

- Sumário sempre visível; Notas, Recursos e TPC fechados por defeito.
- Guardar, sair, voltar, editar e rever são fluxos normais.
- Estados de loading/erro e alvos tácteis funcionam em desktop, tablet e telemóvel.
- Presenças mostram “Todos presentes / Sem faltas registadas” sem inferir presença;
  abrir apresenta lista compacta para marcar eventos relevantes.
- A semana usa informação compacta, sem calendário mensal, excesso de cartões,
  badges ou ações dependentes de hover.
- Continuidade e aplicação de sequência exigem preview e confirmação explícita.

## Migrations aprovadas

As dez mudanças de schema são:

1. `create_recurring_lesson_slots_table`;
2. `create_lessons_table`;
3. `create_lesson_plans_table`;
4. `create_lesson_summaries_table`;
5. `create_lesson_private_notes_table`;
6. `create_lesson_resources_table`;
7. `create_lesson_fulfillments_table`;
8. `create_lesson_sequences_tables` (sequências, itens e recursos dos itens);
9. `add_provenance_to_lesson_plans` como migration separada, preservando a
   proveniência como decisão explícita e reversível;
10. `link_evidence_records_to_lessons_and_add_attendance_kinds`.

Todas são reversíveis, usam FKs conservadoras e não apagam histórico em cascata.
Índices cobrem semana por organização/turma, slots por turma/dia, sequências por
disciplina/ano e evidências por aula/tipo.

## Fora desta tranche

IA, currículo/competências, analytics, autosave sofisticado, vista mensal,
calendários externos, notificações complexas, gestor documental, uploads de
recursos, presença positiva aluno-a-aluno, Agenda real, biblioteca pública de
sequências e versionamento textual pesado.

## Estratégia de testes

Cada slice inclui testes de caminho feliz, autorização, tenancy e conflitos. A
cobertura final inclui:

- aula simples, planeamento futuro, edição/revisão, recursos, notas e TPC;
- aplicação de sequência a várias turmas e independência das cópias;
- reutilização parcial com notas desmarcadas;
- três continuidades, previews e proteção de histórico;
- faltas/atrasos canónicos sem presença inferida nem duplicados;
- cross-tenant, turma/aluno incompatível, professor não autorizado e impersonation;
- read model semanal sem N+1 e limitado ao intervalo pedido;
- exclusão explícita de notas privadas dos outputs oficiais.

Os gates PHP/Composer/Artisan não são executados na sandbox sem essas ferramentas;
o utilizador executa-os separadamente. Cada slice só fecha após os resultados dos
gates relevantes, verificação no browser, versão/changelog e revisão, conforme o
workflow do projeto.
