# Esquema do backup — v1/legacy vs v2

Fatia 6.2. Este documento é a referência de esquema do ficheiro que
`App\Actions\DataExports\GenerateDataExport` produz e que
`App\Services\Import\Backup\ValidateBackupPayload` valida: que coleções
existem, o que cada uma transporta, e o que cada versão do esquema sabe
restaurar. O mecanismo — passos, autorização, transação, limpeza — está em
[docs/data-import.md](data-import.md); este documento é só o "o quê", não o
"como".

## Princípio, repetido de propósito

**O backup transporta FACTOS; o Lapispro calcula RESULTADOS.** Nenhuma coleção
descrita abaixo contém uma média, uma evolução, uma estatística de turma ou
qualquer outro valor derivado — esses são sempre recalculados, depois do
restauro, pelos serviços canónicos (`BuildResultsProgression`,
`ClassResultsCalculator`). Um backup que alguma vez guardasse um valor
calculado como se fosse um facto duplicaria a lógica de avaliação num
segundo sítio, e esse segundo sítio divergiria do primeiro mais cedo ou mais
tarde. Isto não é uma limitação da Fatia 6.1 — é a razão de ser desta
arquitetura.

## Versões de esquema

| `schema_version` | Estado | Capacidade |
|---|---|---|
| 12 (atual) | `Supported` | Como a v11, acrescentando a estampa do enquadramento legal: `interventions[].legal_framework_code` e `interventions[].support_measures[].legal_framework_code` — a versão da lei sob a qual o enquadramento foi decidido. Ausentes num backup v≤11 ⇒ `null`, que significa o mesmo que significava antes de a estampa existir: o regime aplicável resolve-se pelo `started_on` da intervenção. Copiada tal como está, nunca recalculada no restauro |
| 11 | `LegacyCompatible` | Como a v10, acrescentando o resultado real da aula (0.146.0): `lessons[].outcome` (`taught`, `teacher_absent`, `class_external_activity` ou `null` = ainda não fechada), `outcome_reason` (só categoria — `training`, `official_duty`, `other` — e só numa ausência do professor), `outcome_note` (≤160 caracteres, só numa atividade da turma), `outcome_recorded_at` e `outcome_recorded_by_email`. Ausentes num backup v≤10 ⇒ uma aula `status = taught` fecha como `taught` e as restantes ficam em aberto (o mesmo backfill da migração). Motivo em texto livre, motivo fora de uma ausência, nota fora de uma atividade ou assiduidade consolidada numa ocorrência sem assiduidade aplicável ⇒ a linha é `invalid` |
| 10 | `LegacyCompatible` | Como a v9, acrescentando `recurring_lesson_slots[].split_lesson_key` e `lessons[].lesson_unit_key` — chaves opacas (ULID, não são dados pessoais) que ligam tempos T1/T2 que são a mesma lição e aulas que são a mesma lição. Ausentes num backup mais antigo ⇒ `null`; presentes mas malformadas ⇒ a linha é `invalid` (nunca desligada em silêncio). Copiadas tal como estão |
| 9 | `LegacyCompatible` | Como a v8, acrescentando aulas e assiduidade — `class_groups`, `class_group_memberships`, `recurring_lesson_slots`, `cancelled_lesson_occurrences`, `lessons`, `lesson_summaries`, `lesson_plans`, `lesson_attendances`. Ausentes num backup mais antigo ⇒ zero aulas restauradas, nunca um erro |
| 8 | `LegacyCompatible` | Como a v7, acrescentando `classes[].is_support_class` — ausente num backup mais antigo, lê-se como `false` (turma normal) |
| 7 | `LegacyCompatible` | Como a v6, acrescentando `interventions[].created_batch_ulid` e `interventions[].support_measures` |
| 6 | `LegacyCompatible` | Como a v5, acrescentando `assessment_profiles[].grade_levels` — um perfil pode agora cobrir mais do que um ano de escolaridade |
| 5 | `LegacyCompatible` | Como a v4, acrescentando os dados completos de anos letivos e disciplinas para os poder criar em segurança. Um perfil só transporta o antigo `grade_level` singular |
| 4 | `LegacyCompatible` | Estrutura, avaliação e acompanhamento pedagógico completos, mas anos letivos e disciplinas continuam match-only por não terem coleção própria |
| 3 | `LegacyCompatible` | Só turmas/alunos/inscrições com `enrolled_on`; elementos de avaliação e classificações não existiam ainda no formato — linhas que os precisassem seriam `unsupported` |
| 2 | `LegacyCompatible` | Como a 3, mas sem `enrollments[].enrolled_on` — uma inscrição sem essa data não pode ser **criada** em segurança |
| < 2 | `Invalid` | Ficheiro recusado por inteiro |
| > 12 | `UnsupportedNewer` | Ficheiro recusado por inteiro — backup de uma versão do Lapispro mais recente do que este código entende |

`App\Support\Import\Backup\BackupSchemaCompatibility` é a única fonte desta
tabela em código (`CURRENT = 12`, `MINIMUM_SUPPORTED = 2`). Não existe
ramificação em nenhum ponto do pipeline com base em `schema_version` — cada
coleção nova simplesmente está ausente (`?? []`) num backup mais antigo, e o
pipeline trata "ausente" e "vazio" da mesma forma. Um backup v2 ou v3 continua
totalmente legível hoje; só não tem as coleções que este documento descreve
como novas na v4.

## As três formas de "restaurável"

Todas as coleções abaixo seguem um destes três padrões — descritos uma vez
aqui em vez de repetidos em cada linha da tabela:

- **Identificada por `ulid`** (uma turma, uma escala, um elemento de
  avaliação, um relatório, …): corresponde por `ulid` no destino → `existing`
  (ou `conflict` se os dados divergirem); se o `ulid` já pertence a **outra**
  organização → `invalid` (unicidade de `ulid` é global, não por
  organização — ver [docs/data-import.md](data-import.md#identidade-e-o-ulid-global));
  caso contrário, verificação de chave de negócio e, por fim, `new`.
- **Linha filha sem `ulid` próprio** (`profile_version_domains`,
  `item_domain_allocations`, `self_assessment_questions` de um modelo já
  existente, `self_assessment_responses`, …): sempre `new` assim que os pais
  resolverem, correspondida para idempotência pela mesma chave natural que a
  base de dados já impõe como única.
- **Referência de sistema/reference** (uma escala de sistema, um tipo de
  elemento de sistema): correspondida **só** por nome/código, **nunca
  criada** — cada instalação semeia a sua própria cópia com o seu próprio
  `ulid` gerado independentemente (`SystemScalesSeeder`,
  `InstrumentTypesSeeder`), pelo que corresponder por `ulid` nunca
  funcionaria entre bases de dados diferentes, mesmo para a "mesma" linha
  conceptual.

## Estrutura

| Coleção | Forma | Notas |
|---|---|---|
| `academic_years` | `ulid`; chave de negócio: rótulo | **Novo na v5.** Transporta `label`, `starts_on`, `ends_on`, `status`, `country_code` e `region_code`; nunca transporta o estado de encerramento (`closed_at`/`closed_by`) |
| `subjects` | `ulid`; chave de negócio: código | **Novo na v5.** Transporta `name` e `code`; as referências nas restantes coleções continuam a usar o nome |
| `classes` | `ulid` | **`is_support_class` novo na v8** (ausente ⇒ `false`; um valor diferente do da turma já existente no destino é `conflict`, nunca sobrescrito). Referencia `assessment_profile_version_ulid` opcionalmente — se a versão não for restaurável, a turma continua a restaurar-se sem perfil (um estado legítimo; o professor atribui um perfil depois) |
| `students`, `enrollments` | `ulid` | Inalterado desde a Fatia 6 |
| `academic_periods` | `ulid` | **Novo na v4.** Ao contrário de anos letivos/disciplinas, um período é criado, não só correspondido — tem `label`, `sequence`, `kind`, `starts_on`/`ends_on` próprios |
| `scales` (+ `levels` aninhados) | Sistema: nome · Custom: `ulid` | Uma escala de sistema (`kind = level`, `organization_id` nulo) nunca é criada, só correspondida; uma escala customizada e os seus níveis são restaurados por inteiro |
| `instrument_types` | Sistema: código · Custom: `ulid` | Mesmo padrão das escalas |
| `domains` | `ulid` | `parent_domain_ulid` ligado em duas passagens — só se o pai também resolver nesta mesma execução |
| `assessment_profiles` | `ulid` | |
| `assessment_profile_versions` | `ulid` | Referencia a escala pelo objeto `{ulid, name, is_system}`, nunca por id — um id de destino não existe ainda em tempo de plano se a escala for `new` no mesmo backup |
| `profile_version_domains`, `profile_version_periods` | Linha filha | Os pesos de domínio e de período de uma versão |

## Avaliação

| Coleção | Forma | Notas |
|---|---|---|
| `instruments` | `ulid` | Inclui `applied_on` — obrigatório, é a data comparada com `enrollments.enrolled_on` na regra de inscrição tardia (§11.4) |
| `instrument_groups` | `ulid` | |
| `instrument_items` (+ `item_domain_allocations`) | `ulid` / linha filha | Cada item pode ter a sua própria escala (`scale_id` nulo herda a da versão do perfil) |
| `student_item_scores` | Linha filha, chave `(item, enrollment)` | **Vazio não é zero** (§12.4): uma pontuação sem `result_state = assessed` nunca transporta `points_earned` |
| `classifications` | `ulid` | Transporta a **decisão já tomada** (`final_value`, `final_scale_level`, `status`, `confirmed_by`/`confirmed_at`) como facto — nunca a recalcula. `calculation_snapshot_id` fica sempre nulo no restauro: o histórico de auditoria de uma execução de cálculo específica não é reconstruído, só a decisão final |
| `self_assessment_templates`, `self_assessment_questions`, `self_assessments`, `self_assessment_responses` | `ulid` / linha filha | Uma pergunta sem `ulid` próprio é indexada por `(modelo, role)` quando tem `role`, senão por `(modelo, sequence)` — cobre tanto perguntas novas como perguntas de um modelo já existente |

## Acompanhamento

| Coleção | Forma | Notas |
|---|---|---|
| `interim_assessments` | `ulid` | Escrito uma só vez, nunca atualizado (o modelo já impõe isto) — o restauro só faz `INSERT` |
| `evidence_records` | `ulid` | "Registos pedagógicos" — um apontamento de diário, nunca entra no cálculo. Distinto de `student_item_scores`, que É avaliação |
| `interventions` (+ participantes), `intervention_reviews` | `ulid` | "Estratégias e Medidas". Participantes ligados pelas inscrições já resolvidas |

## Documentos

| Coleção | Forma | Notas |
|---|---|---|
| `reports` | `ulid` | **Só relatórios `status = Finalized` são alguma vez exportados** — um rascunho recalcula-se a partir dos dados atuais, nunca é um facto congelado. `document`/`document_hash` copiados byte a byte, nunca regenerados — não há nenhuma chamada de IA em todo o pipeline de exportação/importação |

`ReportSection` (conteúdo de rascunho) e `ReportTemplate`/`ReportLibraryEntry`
ficam deliberadamente fora desta fatia — o `template_snapshot` de um
relatório finalizado já congela tudo o que esse relatório precisa para se
mostrar de novo; ver "Dívida futura" abaixo.

## Aulas e assiduidade

**Novo na v9.** O conjunto mínimo coerente para restaurar o horário e a
assiduidade de uma turma sem inventar nada — nenhuma coleção calcula ou
materializa: `App\Actions\Lessons\MaterializeLessonsForRange` continua a ser
o único sítio onde uma aula futura nasce, mesmo depois de um restauro.

| Coleção | Forma | Notas |
|---|---|---|
| `class_groups` | `ulid`; chave de negócio: `(turma, label)` | Os grupos fixos («T1», «T2») em que uma turma se desdobra. `lessons.class_group_id` e a elegibilidade da assiduidade dependem dele |
| `class_group_memberships` | `ulid`; chave de negócio: `(grupo, inscrição, effective_from)` | Quem pertence a um grupo, e desde quando. Validado: o grupo e a inscrição têm de pertencer à MESMA turma |
| `recurring_lesson_slots` | `ulid`; chave de negócio: `(turma, grupo, dia da semana, horas)` | O horário recorrente. `class_group_id` nulo = turma inteira; quando presente, tem de pertencer à MESMA turma. Necessário porque `MaterializeLessonsForRange` deduplica por `(turma, tempo, início)` — sem o tempo do horário restaurado, aulas repostas seriam duplicadas ao materializar a semana. **`split_lesson_key` novo na v10** (ausente ⇒ `null`; copiado tal como estava) |
| `cancelled_lesson_occurrences` | **Sem `ulid` próprio** — chave natural `(turma, tempo, instante)` | Uma ocorrência que o professor eliminou de propósito. Sem esta coleção, a materialização voltaria a criar a aula. O tempo do horário e o grupo (quando presente) têm de pertencer à MESMA turma |
| `lessons` | `ulid`; chave de negócio: `(turma, tempo, início)` com tempo, ou `(turma, grupo, início)` sem tempo | `attendance_recorded_at` NULL é o estado de qualquer aula lecionada antes desta funcionalidade existir — nunca inferido como "todos presentes". `lesson_number` é copiado tal como estava, nunca renumerado. **`lesson_unit_key` novo na v10** (ausente ⇒ `null`; copiado tal como estava). O grupo e o tempo do horário (quando presentes) têm de pertencer à MESMA turma |
| `lesson_summaries`, `lesson_plans` | `ulid`; um por aula (`hasOne`, FK restrict) | Parte da mesma entidade que a aula — uma aula lecionada restaurada sem o seu sumário seria incoerente |
| `lesson_attendances` | `ulid`; chave natural `(aula, inscrição)` | `student_id` é sempre derivado da inscrição já resolvida, **nunca** lido do ficheiro. A inscrição tem de pertencer à MESMA turma da aula. Uma linha `present` só é válida numa aula com `attendance_recorded_at` preenchido; antes da consolidação só `absent` (rascunho) é válido |

### Integridade entre turmas

Todo par (grupo/tempo/aula/inscrição ↔ a turma que os deveria conter)
listado acima é verificado antes de qualquer classificação `new`: um
`class_group_memberships` cuja inscrição pertence a outra turma, um
`recurring_lesson_slots`/`lessons`/`cancelled_lesson_occurrences` cujo
grupo ou tempo do horário pertence a outra turma, uma `lesson_attendances`
cuja inscrição pertence a outra turma que a da própria aula — todos ficam
`invalid`, nunca escritos. A comparação nunca é feita por igualdade de
`ulid` em bruto: usa uma IDENTIDADE (`BuildLessonsPlan::classIdentityOfRow()`)
que resolve cada lado ao seu destino REAL — o id de uma linha já `existing`
(lido da base de dados, nunca confiado ao que o backup apenas afirma para
ela) ou o `ulid` de origem partilhado por uma linha ainda `new`. `WriteLessons`
repete a mesma verificação com os ids já escritos, em defesa — nunca a
única guarda.

Uma linha `present` cuja aula (no próprio backup, ou já na base de dados
para uma aula `existing`) ainda não tem `attendance_recorded_at` também é
`invalid` — nunca escrita como facto de uma consolidação que não aconteceu.

### Uma referência opcional malformada nunca vira `null`

`class_group_ulid` (em `recurring_lesson_slots`, `cancelled_lesson_occurrences`,
`lessons`) e `recurring_lesson_slot_ulid` (em `lessons`) são opcionais —
ausente ou `null` no JSON significa legitimamente "sem grupo"/"sem tempo do
horário" ("a turma inteira"). Mas um valor PRESENTE que não é um `ulid`
válido é uma coisa diferente: um erro de escrita ou corrupção do ficheiro, e
tratá-lo como `null` reescreveria silenciosamente "a aula de T1" como "a
aula da turma inteira". `ValidateBackupPayload::optionalUlidOrInvalidate()`
distingue os dois casos — chave ausente/`null` passa; um valor presente mas
malformado invalida a LINHA INTEIRA (nunca escreve o campo como vazio).

### A regra que não é como as outras

Uma aula já existente no destino **sem** assiduidade registada (um rascunho
por consolidar) nunca absorve silenciosamente um backup que já traz essa
mesma aula com assiduidade consolidada — seria reescrever um rascunho vivo
pela fotografia de outra pessoa sem ninguém decidir isso. É sempre
`conflict`, nunca fundido (§13.3 do CLAUDE.md — o professor decide), e as
linhas de assiduidade dessa aula ficam `conflict`/`invalid` ao lado dela,
nunca escritas parcialmente.

Do mesmo modo, uma linha de assiduidade nunca é acrescentada a uma aula já
`existing` no destino que não tenha essa exata linha — um instantâneo já
consolidado não ganha linhas que ele próprio nunca escreveu.

### O mapa de inscrições que `writeEnrollments()` não dá

`ExecuteDataImport::writeEnrollments()` só popula o seu `byUlid` com
inscrições `new` (dívida pré-existente e deliberadamente não alterada por
esta fatia — mudar esse mapa mudaria o comportamento de pontuações/
evidências já escritas por outras camadas). As aulas e as pertenças a grupo
precisam de resolver TAMBÉM as inscrições `existing` (o caso comum de um
restauro para uma turma que já existe no destino), por isso
`ExecuteDataImport` constrói, só para esta fatia, um mapa próprio —
`new ∪ existing`, lido de `$rows['enrollments']` depois de `writeEnrollments`
correr.

## Autoria

Todo campo de autoria (`assessed_by`, `confirmed_by`, `overridden_by`,
`created_by`, `reviewed_by`, `finalized_by`, …) é exportado como um **email**,
nunca um id. Na importação, a única correspondência segura é "o autor desta
linha é literalmente quem está a confirmar esta importação" — o email do
`$actor` comparado sem distinguir maiúsculas/minúsculas
(`App\Services\Import\Backup\Concerns\ResolvesBackupReferences::resolveAuthor()`).
**Nunca por nome** — dois utilizadores com o mesmo nome e emails diferentes
são, para este efeito, duas pessoas diferentes.

### A autoria é metadado histórico, não condição de importação (0.101.4)

Até à 0.101.4 um email que não resolvia tornava a linha inteira `invalid`
quando a coluna de autor era `NOT NULL`. Isso tratava a autoria como uma
credencial que o registo tinha de apresentar para poder existir — e punia
exatamente os casos legítimos:

- o professor mudou de email ou de conta;
- a turma mudou de professor;
- a instituição transferiu a responsabilidade;
- os dados são restaurados noutra conta autorizada.

Hoje a regra é a seguinte, e vale para todos os campos de autoria:

| Situação | O que acontece |
|---|---|
| O email é o de quem confirma a importação | A autoria é preservada e ligada a essa conta |
| O email é outro (ou não existe) | **A linha é importada na mesma**, com o campo de autor vazio e um aviso não bloqueante no pré-visualizador |
| A linha é uma classificação `confirmed` cujo confirmador não resolve | É importada como **`proposed`** — os valores ficam intactos, a confirmação volta a ser uma decisão do professor (§3.3) |

As cinco colunas que eram `NOT NULL` — `interim_assessments.created_by`,
`evidence_records.created_by`, `interventions.created_by`,
`intervention_reviews.reviewed_by`, `reports.created_by` — passaram a aceitar
`null` (migração
`2026_09_22_000100_let_an_imported_record_keep_an_unresolved_author`). A
mesma correção estende-se, na v9, a `lessons.created_by`,
`lesson_plans.created_by` e `cancelled_lesson_occurrences.cancelled_by`
(migração
`2026_11_10_000500_let_imported_lessons_keep_an_unresolved_author`) —
`lesson_summaries.reviewed_by`, `lessons.attendance_recorded_by` e
`lesson_attendances.updated_by` já eram nullable e não mudam. Nenhum caminho
de criação da aplicação escreve `null` nessas colunas: um `null` ali
significa **exatamente uma coisa** — a autoria original não pôde ser
associada e o sistema recusou-se a adivinhar.

O que continua proibido, e é o outro lado da mesma correção:

- **nunca** atribuir a autoria à conta que importa quando isso não é facto;
- **nunca** inventar utilizadores nem criar contas automaticamente;
- **nunca** procurar o email noutras contas da organização de destino. Não é
  só o risco de acertar na pessoa errada: o email do autor é um valor dentro
  de um ficheiro carregado, e esta aplicação autentica por email — bastaria
  editar o JSON para escrever registos pedagógicos assinados por um colega
  que nunca os escreveu. Atribuir a si próprio não é falsificação (a conta já
  podia escrever os seus registos); atribuir a um colega é.

### A `CHECK` que a importação chegou a violar

`classifications_confirmed_has_author_check` (`status <> 'confirmed' OR
confirmed_by IS NOT NULL`) recusa uma classificação confirmada sem
confirmador. Uma classificação `confirmed` cujo email de confirmação não
resolvia chegava ao escritor como `confirmed` + `null` e derrubava a
**transação inteira** — todo o resto do ficheiro perdido por causa de um
email. Não era visível em testes: `addCheck()` só corre em MySQL e a suite
corre em SQLite. O estado efetivo passou a ser decidido no plano, antes de
qualquer comparação, para que uma reimportação continue idempotente (§11,
§44) em vez de classificar como conflito a linha que a própria importação
escreveu como `proposed`.

### Referência histórica

Nada copia o email do autor original para dentro da organização de destino.
A referência histórica segura já existe e já tem política de retenção:
`data_imports.canonical_snapshot` guarda o que o backup dizia — incluindo
todos os `*_by_email` — e `PruneDataImports` decide durante quanto tempo.
Duplicar o identificador de um colega para linhas de uma organização que não
tem relação com ele sobreviveria a essa política e alargaria quem o pode ler.

### Restauro próprio vs clonagem para outro professor

`GenerateDataExport::technicalBackup()` grava `exported_by` (nome/email de
quem exportou), mas esse campo é só proveniência do *ficheiro* — nunca é
usado para atribuir a autoria de uma linha a ninguém, incluindo ao próprio
exportador quando a conta que confirma é outra. Dentro da mesma organização
institucional várias contas escrevem registos, e "quem exportou" nunca é
garantia de "quem escreveu esta linha".

A diferença entre restaurar a própria conta e clonar para outro professor
deixou de ser "tudo ou nada" e passou a ser apenas **quanta proveniência
sobrevive**:

- **Restauro próprio** (mesmo login/email): todo o grafo pedagógico é
  restaurado *com* a autoria.
- **Clonagem para outra conta**: todo o grafo pedagógico é igualmente
  restaurado, *sem* autoria — e sem que nada fique atribuído a quem importou.

Isto não altera nada quanto a acessos. Registos pedagógicos, estratégias e
medidas são autorizados através da **turma**, não da autoria; e a única
política que olha para a autoria (`ReportPolicy::authored()`) compara
`(int) null` com um id real, ou seja, falha fechada. Ver
`tests/Feature/DataImports/ImportAuthorshipPortabilityTest.php`.

## O que nunca é importado

Além do que já está documentado em [docs/data-import.md](data-import.md#o-que-nunca-é-importado):
membros, convites, contas de utilizador, segredos, configuração de
plataforma — esta fatia também nunca escreve:

- Resultados calculados de qualquer tipo (médias, evoluções, estatísticas) —
  ver o princípio no topo deste documento
- `Classification.calculation_snapshot_id` — sempre `null` numa linha
  restaurada
- Chamadas a IA — não existem em nenhum ponto deste pipeline
- `ReportSection`, `ReportTemplate`, `ReportLibraryEntry`

## Dívida futura (fora do âmbito desta fatia, de propósito)

- `results_analysis_notes` (0.155.0) — as observações do professor sobre os
  Resultados de um instrumento **não são exportadas nem restauradas**: não
  aparecem na exportação de dados (XLSX/JSON) nem no backup, e um restauro
  perde-as. São texto livre do professor e podem conter informação
  identificável; a cobertura exige uma versão nova do esquema de backup
  (exportação, validação, plano e escrita) e fica para uma intervenção
  própria, obrigatória antes de a funcionalidade ser publicada
- Restauro de `ReportTemplate`/`ReportLibraryEntry` — hoje um relatório
  finalizado é autossuficiente (`template_snapshot`), pelo que isto só
  importaria para permitir gerar **novos** relatórios a partir de um modelo
  restaurado, não para reler os já finalizados
- Ligação ao `calculation_snapshot_id` histórico de uma classificação — a
  decisão em si restaura-se; o encadeamento de auditoria da execução de
  cálculo que a propôs, não
- `enrollment_instrument_applicability` (exceções à regra derivada de
  inscrição tardia) — tabela normalmente vazia, existe só para
  **contradizer** a regra por omissão; não é exportada nesta fatia
