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
| 20 (atual) | `Supported` | Como a v19, acrescentando a **cadeia de retificações** das classificações: `classifications[].rectifies_ulid`, `rectification_reason`, `rectification_note`, `rectified_by_email` e `rectified_at`. Uma decisão publicada que foi corrigida não se edita — outra linha substitui-a e a antiga fica como história (`status = superseded`, com `superseded_by_ulid`). Restaura-se a cadeia **inteira**, da mais antiga à mais recente, ou nenhuma linha dela. Ausentes num backup v≤19 ⇒ nenhuma retificação, e as classificações leem-se exatamente como antes. Um backup v20 é recusado por código anterior (`UnsupportedNewer`). Ver [Retificações de classificações (schema v20)](#retificações-de-classificações-schema-v20) |
| 19 | `LegacyCompatible` | Como a v18, acrescentando `timetable_activity_occurrences` (0.170.0): o **registo por sessão** das outras atividades (o sumário de cada apoio, reunião, clube…), mais `numbering_enabled` e `numbering_start` em `timetable_activities` (ausentes ⇒ `false` e `1`) e o tipo `club`. Só saem as do exportador e só se restauram na conta do próprio autor; uma sessão cuja atividade não se restaura volta como órfã, identificada pela cópia que leva. Ausente num backup v≤18 ⇒ zero linhas, nunca um erro. Um backup v19 é recusado por código anterior (`UnsupportedNewer`). Ver [Outras atividades e as suas sessões (schema v19)](#outras-atividades-e-as-suas-sessões-schema-v19) |
| 18 | `LegacyCompatible` | Como a v17, acrescentando `lessons[].lesson_number_end` e `lessons[].lesson_number_manual` (0.169.0): o professor pode indicar o número de uma aula (e um intervalo, «11–12», quando a aula corresponde a várias lições). `lesson_number_manual = true` marca o número como indicado à mão — nunca é recalculado, e o `lesson_number_end` (inteiro posterior ao início, só com início) é o fim do intervalo, `null` quando a aula é uma só lição. Ausentes num backup v≤17 ⇒ `null` e `false` (nada muda). Copiados tal como estão no restauro, nunca renumerados; um fim que não seja um inteiro posterior ao início, ou uma marca que não seja um booleano, deixa a linha como `invalid`. Os limites são os do formulário: início de 1 a 9999 quando manual e no máximo 10 lições por aula (`lesson_number_end ≤ lesson_number + 9`), senão a linha é `invalid`. Dois intervalos manuais da mesma turma que se sobrepõem (as aulas da mesma lição de grupo partilham o intervalo) deixam as linhas da unidade **posterior** (por `starts_at`) `invalid` — dentro do ficheiro; Contra as aulas que o destino JÁ TEM na mesma turma a regra é outra e **bloqueia o restauro inteiro**: uma aula `new` do ficheiro cujo intervalo FIXO (número indicado à mão, ou aula fechada com número — lecionada ou atividade da turma) coincide ou se sobrepõe ao intervalo fixo de outra lição do destino impede o restauro — a pré-visualização mostra as colisões (só datas, números e rótulo da turma) e não deixa confirmar, e a confirmação recusa antes de gravar o que quer que seja, sem marcar a importação como falhada (continua confirmável depois de o professor corrigir o destino). A mesma `lesson_unit_key` com o mesmo intervalo é a mesma lição e passa; as aulas livres (abertas e não manuais) não contam, o número delas é uma sugestão. Não é uma linha `conflict`: essa seria saltada e deixaria a aula, o sumário, o plano e as faltas de fora — um restauro parcial. Numa ausência do professor ambos são forçados a `null`/`false`, como `lesson_number` |
| 17 | `LegacyCompatible` | Como a v16, acrescentando `timetable_activities` (0.168.0): as **outras atividades** do horário do professor (então ditas «atividades não letivas»: direção de turma, apoio a alunos, reuniões, coordenação). Só saem as de quem exporta, e só se restauram na conta do próprio autor. Ausente num backup v≤16 ⇒ zero linhas, nunca um erro, e nada no destino é tocado. Ver [Outras atividades (schema v17)](#outras-atividades-schema-v17) |
| 16 | `LegacyCompatible` | Como a v15, acrescentando `self_assessment_responses[].numeric_value` (0.167.0): o número que o aluno escreveu numa escala **sem níveis** (0 a 20, percentagem, escala própria da organização), como cadeia decimal (`DECIMAL(6,3)` na base) — `null` quando a resposta é um nível ou um texto. É um valor de uma resposta, nunca uma média nem um cálculo. Ausente num backup v≤15 ⇒ `null` (todas as respostas anteriores são por nível ou por texto); nada mais muda. No restauro, um valor que não seja uma cadeia decimal com até 3 inteiros e 3 casas deixa a linha como inválida, em vez de a gravar como outra coisa; e um número que a pergunta não aceita (escala de níveis, fora dos limites da escala, ou ao lado de um nível) não é escrito — o formulário nunca o teria gravado |
| 15 | `LegacyCompatible` | Como a v14, acrescentando o **acompanhamento dos registos** (0.164.0): `record_followup_cases` (o caso aluno × turma × período × tipo e o alerta que teve) e `record_followup_confirmations` (as declarações «Registado no Inovar», as desfeitas incluídas, com as ocorrências abrangidas como `{ulid, date}`). Referências sempre por ULID, autoria por email. Ausentes num backup v≤14 ⇒ zero linhas, nunca um erro, e nada no destino é tocado. Ver [Acompanhamento dos registos (schema v15)](#acompanhamento-dos-registos-schema-v15) |
| 14 | `LegacyCompatible` | Como a v13, acrescentando `class_notebook_entries` — o **Caderno da turma**: registos privados do professor sobre a turma (título opcional, texto, fixação e as três datas). Só sai o caderno de quem exporta, nas turmas que ensina, sem os registos eliminados; só se restaura na conta do próprio autor. Ausente num backup v≤13 ⇒ zero registos restaurados, nunca um erro, e nada é apagado no destino. Ver [Caderno da turma (schema v14)](#caderno-da-turma-schema-v14) |
| 13 | `LegacyCompatible` | Como a v12, acrescentando `results_analysis_notes` — as observações do professor no separador Resultados de um elemento de avaliação (`context_kind = 'instrument'`, a única forma que existe hoje). Texto livre até 20 000 carateres; uma observação vazia nunca é exportada. Ausente num backup v≤12 ⇒ zero observações restauradas, nunca um erro, e nada é apagado no destino |
| 12 | `LegacyCompatible` | Como a v11, acrescentando a estampa do enquadramento legal: `interventions[].legal_framework_code` e `interventions[].support_measures[].legal_framework_code` — a versão da lei sob a qual o enquadramento foi decidido. Ausentes num backup v≤11 ⇒ `null`, que significa o mesmo que significava antes de a estampa existir: o regime aplicável resolve-se pelo `started_on` da intervenção. Copiada tal como está, nunca recalculada no restauro |
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
| > 20 | `UnsupportedNewer` | Ficheiro recusado por inteiro — backup de uma versão do Lapispro mais recente do que este código entende |

`App\Support\Import\Backup\BackupSchemaCompatibility` é a única fonte desta
tabela em código (`CURRENT = 20`, `MINIMUM_SUPPORTED = 2`). Não existe
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
| `classifications` | `ulid` | Transporta a **decisão já tomada** (`final_value`, `final_scale_level`, `status`, `confirmed_by`/`confirmed_at`) como facto — nunca a recalcula. Desde a v20 leva também as **retificações** (a cadeia de linhas substituídas, ver [Retificações de classificações](#retificações-de-classificações-schema-v20)). `calculation_snapshot_id` fica sempre nulo no restauro: o histórico de auditoria de uma execução de cálculo específica não é reconstruído, só a decisão final |
| `self_assessment_templates`, `self_assessment_questions`, `self_assessments`, `self_assessment_responses` | `ulid` / linha filha | Uma pergunta sem `ulid` próprio é indexada por `(modelo, role)` quando tem `role`, senão por `(modelo, sequence)` — cobre tanto perguntas novas como perguntas de um modelo já existente. Desde a v16, uma resposta de escala traz `scale_level` **ou** `numeric_value` (nunca os dois); uma turma pode ter várias gerações de modelo (um retirado, com as respostas dadas com os critérios anteriores, e o atual), todas exportadas e restauradas tal como estão |

## Acompanhamento

| Coleção | Forma | Notas |
|---|---|---|
| `interim_assessments` | `ulid` | Escrito uma só vez, nunca atualizado (o modelo já impõe isto) — o restauro só faz `INSERT` |
| `evidence_records` | `ulid` | "Registos pedagógicos" — um apontamento de diário, nunca entra no cálculo. Distinto de `student_item_scores`, que É avaliação. Desde a 0.164.0, um registo que o destino tem **eliminado** (o mesmo `ulid`, `deleted_at` preenchido) é `conflict` — nunca reposto, e já não faz falhar a importação inteira (issue #61). A descrição pode vir vazia exatamente onde o formulário o aceita (`homework`, `participation`, `incident` — `EvidenceKind::allowsEmptyDescription()`); antes da 0.164.0 esses registos, todos os TPC da grelha incluídos, eram recusados |
| `record_followup_cases`, `record_followup_confirmations` | `ulid` / chave natural | "Acompanhamento dos registos" (v15). Ver [a secção própria](#acompanhamento-dos-registos-schema-v15) |
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
| `lessons` | `ulid`; chave de negócio: `(turma, tempo, início)` com tempo, ou `(turma, grupo, início)` sem tempo | `attendance_recorded_at` NULL é o estado de qualquer aula lecionada antes desta funcionalidade existir — nunca inferido como "todos presentes". `lesson_number` é copiado tal como estava, nunca renumerado — nem `lesson_number_end` e `lesson_number_manual` (**novos na v18**; ausentes ⇒ `null`/`false`). Contra as aulas que o destino JÁ TEM na mesma turma a regra é outra e **bloqueia o restauro inteiro**: uma aula `new` do ficheiro cujo intervalo FIXO (número indicado à mão, ou aula fechada com número — lecionada ou atividade da turma) coincide ou se sobrepõe ao intervalo fixo de outra lição do destino impede o restauro — a pré-visualização mostra as colisões (só datas, números e rótulo da turma) e não deixa confirmar, e a confirmação recusa antes de gravar o que quer que seja, sem marcar a importação como falhada (continua confirmável depois de o professor corrigir o destino). A mesma `lesson_unit_key` com o mesmo intervalo é a mesma lição e passa; as aulas livres (abertas e não manuais) não contam, o número delas é uma sugestão. Não é uma linha `conflict`: essa seria saltada e deixaria a aula, o sumário, o plano e as faltas de fora — um restauro parcial. **`lesson_unit_key` novo na v10** (ausente ⇒ `null`; copiado tal como estava). O grupo e o tempo do horário (quando presentes) têm de pertencer à MESMA turma |
| `lesson_summaries`, `lesson_plans` | `ulid`; um por aula (`hasOne`, FK restrict) | Parte da mesma entidade que a aula — uma aula lecionada restaurada sem o seu sumário seria incoerente. **A proveniência de sequência (0.160.0)** — `lesson_sequence_id`, `lesson_sequence_item_id`, `sequence_content_hash` — **não viaja**: as sequências não fazem parte do backup, e um sumário restaurado fica sem origem conhecida (tratado como conteúdo do professor numa reaplicação) |
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

Desde a 0.164.0 os **registos pedagógicos** (`evidence_records`) usam esse
mesmo mapa. Até aí, um registo restaurado para uma turma que já existia no
destino perdia o aluno sem aviso e ficava como registo da turma inteira — e o
acompanhamento dos registos (v15) nunca o contaria. As estratégias e medidas
e os relatórios continuam no mapa antigo (ver
[Dívida futura](#dívida-futura-fora-do-âmbito-desta-fatia-de-propósito)).

## Autoria

Todo campo de autoria (`assessed_by`, `confirmed_by`, `overridden_by`,
`created_by`, `reviewed_by`, `finalized_by`, …) é exportado como um **email**,
nunca um id. Na importação, a única correspondência segura é "o autor desta
linha é literalmente quem está a confirmar esta importação" — o email do
`$actor` comparado sem distinguir maiúsculas/minúsculas
(`App\Services\Import\Backup\Concerns\ResolvesBackupReferences::resolveAuthor()`).
**Nunca por nome** — dois utilizadores com o mesmo nome e emails diferentes
são, para este efeito, duas pessoas diferentes.

`results_analysis_notes.created_by`/`updated_by` seguem exatamente esta
mesma regra (`created_by_email`/`updated_by_email`) — as duas colunas já
eram nullable antes desta fatia, o que é o que torna possível deixá-las
vazias em vez de bloquear a linha quando o email não resolve.

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

**A exceção é o Caderno da turma (v14).** Ali a autoria não é metadado: é
quem pode ler. Numa clonagem para outra conta os registos do caderno **não**
são restaurados — nem sem autor, nem atribuídos a quem importa. Ver
[Caderno da turma (schema v14)](#caderno-da-turma-schema-v14).

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

## Observações dos Resultados (schema v13)

As observações do professor no separador Resultados de um elemento de
avaliação (`App\Models\ResultsAnalysisNote`) são exportadas e restauradas
desde a v13.

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade da observação |
| `context_kind` | string | só `'instrument'` hoje; qualquer outro valor invalida a linha |
| `instrument_ulid` | ULID | obrigatório; o elemento de avaliação tem de ser restaurável NESTE MESMO backup — nunca uma referência a um instrumento de outra organização, nem a um instrumento fora do backup |
| `body` | texto | até 20 000 carateres (`ResultsAnalysisNote::BODY_MAX_LENGTH`, a mesma constante que o controlador usa); vazio ⇒ nunca exportada (minimização de dados) |
| `created_at` / `updated_at` | ISO 8601 ou `null` | preservadas tal como estavam; ausentes/`null` são aceites, presentes mas malformadas invalidam a linha |
| `created_by_email` / `updated_by_email` | email ou `null` | mesma regra de autoria de todo o resto do backup (ver secção Autoria) |

**Nunca transportado**: `id`, `organization_id`, `instrument_id` numérico,
`lock_version`. `lock_version` é o contador de bloqueio otimista **desta
instalação** — uma observação criada pela importação nasce sempre com
`lock_version = 1`, nunca o contador da instalação de origem.

**Chave e conflito**: o índice único `(instrument_id, context_kind)` do
destino é respeitado sempre, não só quando o ulid já existe noutro sítio —
uma observação restaurada nunca REASSOCIA a outro elemento de avaliação
(um ulid que já existe no destino associado a outro instrumento é
`conflict`, nunca repontado), e um texto que diverge do que já existe no
destino (por ulid OU por `(instrument_id, context_kind)`) é sempre
`conflict`, nunca sobrescrito.

**Âmbito da exportação**: só as observações dos elementos de avaliação que a
própria exportação já inclui — as turmas de que o utilizador é professor
(`class_teachers`), dentro da organização atual. Uma observação de um colega
noutra turma da mesma organização, ou de outra organização, nunca sai.
Também aparecem no `Exportacao-Lapispro.xlsx`, na folha «Observações dos
Resultados» (ano letivo, turma, elemento, data do elemento, texto, última
alteração) e numa linha do «Resumo». Nenhuma média, estatística ou
indicador acompanha o texto — esses recalculam-se sempre ao abrir o
separador Resultados.

**Validação**, além dos campos acima:

- o texto também não pode passar de 65 535 **bytes** (a coluna é `TEXT`):
  20 000 carateres de 4 bytes (emoji) não cabem, e um `INSERT` falhado
  derrubaria a transação inteira do restauro. Os dois limites vivem num só
  sítio, `ResultsAnalysisNote::bodyLimitViolation()`, que o formulário
  (regra `ResultsAnalysisNoteBody`) e este validador usam;
- cada recusa leva um motivo próprio (identificação ou contexto, texto em
  falta, texto acima dos limites, data inválida, duplicado), mostrado na
  pré-visualização sem o texto da observação;
- as datas têm de ser ISO 8601 e são normalizadas para o fuso da aplicação;
- duas linhas com o mesmo `ulid`, ou para o mesmo `(instrument_ulid,
  context_kind)`, no mesmo ficheiro: fica a primeira, as seguintes são
  recusadas.

**Instrumento em conflito**: se o elemento de avaliação mudou no destino
desde a exportação (p. ex. foi concluído ou renomeado), o instrumento é
`conflict`. Uma observação que já existe no destino, com o mesmo ulid e o
mesmo texto, ligada a esse instrumento, é `existing`. Uma observação que
ainda não existe nunca é criada sobre um instrumento em conflito: é
`invalid`, como os grupos e os itens desse instrumento.

**Limitações que ficam**:

- As recusas de domínios que a pré-visualização trata como linhas filhas
  sem identidade própria (`student_item_scores`, `item_domain_allocations`,
  …) continuam fora de «Pontos a rever», como sempre estiveram
  (`BuildImportPlan::$noIssueDomains`). As observações não estão nesse
  grupo: cada recusa aparece.
- Enquanto a importação não for podada, o texto das observações fica em
  `data_imports.canonical_snapshot`, como o de qualquer outra coleção de
  texto livre. A retenção é a de `PruneDataImports`.
- Só existe o contexto `instrument`. Contextos futuros (intercalar, período,
  semestre) exigem uma nova versão do esquema, porque não têm instrumento
  a que se ligar.

## Caderno da turma (schema v14)

Os registos do **Caderno da turma** (`App\Models\ClassNotebookEntry`) são
exportados e restaurados desde a v14. São texto **privado** de quem o
escreveu — nem os colegas da mesma turma nem o responsável da organização o
leem —, e é isso que torna esta coleção diferente das outras em dois pontos:
quem a exporta e a quem se restaura. (O suporte técnico pode lê-lo no ecrã
durante um apoio pedido, mas exportar e importar dados recusam a sessão de
suporte: o caderno nunca sai nem entra por um backup em nome do professor.)

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade do registo |
| `class_ulid` | ULID | a turma, que tem de vir NESTE MESMO backup; o ano letivo é o da turma (`classes[].academic_year`) — não se duplica |
| `author_email` | email | o autor; por construção, quem exporta |
| `title` | texto ou `null` | até 160 caracteres; vazio ⇒ `null` |
| `body` | texto | até 20 000 caracteres e 65 535 bytes (`ClassNotebookEntry::bodyLimitViolation()`, o mesmo limite do formulário); parágrafos e caracteres tal como estão |
| `is_pinned` | booleano | ausente ⇒ `false` |
| `created_at` / `updated_at` / `edited_at` | ISO 8601 | preservadas; `edited_at` `null` = nunca editado. Presentes mas malformadas invalidam a linha |

**Nunca transportado**: `id`, `organization_id`, `class_id`, `author_id`,
`lock_version`, `deleted_at`. Um registo restaurado nasce com
`lock_version = 0` — o contador de concorrência desta instalação.

**Âmbito da exportação — só o caderno de quem exporta.** `author_id = quem
exporta` **e** turma que ensina (`class_teachers`), na organização atual, e
**sem os registos eliminados** (o *scope* do `SoftDeletes`, como os
`evidence_records`). Um colega da mesma turma, o responsável de uma
organização institucional ou outra organização nunca obtêm o caderno de outro
professor por esta via: cada um exporta apenas o seu. Também aparece no
`Exportacao-Lapispro.xlsx`, na folha «Caderno da turma» (ano letivo, turma,
título, registo, fixado, criado em, editado em) e numa linha do «Resumo».

**Restauro — só na conta do autor.** Ao contrário do resto do backup (ver
[Autoria](#autoria)), um `author_email` que não é o de quem confirma **não**
deixa a autoria vazia: a linha é `invalid` e não é restaurada. Num registo
privado, a autoria é o que decide quem o lê — restaurá-lo sem autor
deixava-o ilegível para sempre, e atribuí-lo a quem importa transferia texto
privado para outra pessoa. A decisão é tomada **antes** de qualquer consulta
ao destino, e a pré-visualização nunca mostra o título, o texto nem o email
do autor de uma linha recusada.

| Situação no destino | Classificação |
|---|---|
| `author_email` ≠ quem confirma | `invalid` — «escrito por outra conta» |
| Mesmo `ulid`, de outro autor | `invalid`, frase neutra; o conteúdo não é comparado (não serve de oráculo) |
| Mesmo `ulid`, **eliminado** depois da exportação | `conflict` — não é reposto nem duplicado |
| Mesmo `ulid`, noutra turma | `conflict` — nunca reassociado |
| Mesmo `ulid`, título/texto/fixação iguais | `existing` |
| Mesmo `ulid`, dados diferentes | `conflict` — o destino nunca é sobrescrito |
| Turma que não é `new` nem `existing` neste restauro | `invalid` |
| Sem `ulid` no destino, mas o mesmo autor tem, na mesma turma, um registo com a mesma data de criação, título e texto | `existing` (ou `conflict` se esse estiver eliminado) — é o que torna idempotente reimportar um backup clonado com `ulid` novo |
| Nenhum dos anteriores | `new` (`ulid` novo se o original existir noutra organização) |

**Registos eliminados.** Seguem a política dos registos pedagógicos: a
eliminação é lógica (`deleted_at`) e não tem «recuperar» na interface. Por
isso um registo eliminado **nunca sai** na exportação, e um restauro **nunca
o faz reaparecer**: se o destino o tem eliminado (pelo `ulid` ou pela chave
de conteúdo), a linha do backup é `conflict` com esse motivo.

**Numa organização institucional**, uma turma criada pelo restauro fica sem
professor atribuído (ver [docs/data-import.md](data-import.md)); os registos
ficam guardados com o autor e voltam a ser visíveis para ele quando for
novamente professor da turma.

**Limitações que ficam**: enquanto a importação não for podada, o texto fica
em `data_imports.canonical_snapshot` (retenção de `PruneDataImports`), como
qualquer outra coleção de texto livre — e só quem pediu a importação a pode
ver.

**Cópias integrais da base.** O dump diário (`scripts/backup-database.sh`)
copia a base inteira sem lista de tabelas, pelo que a tabela, os registos
eliminados e todas as colunas lá estão sem alteração ao script — provado em
MySQL 8.0.43 com o próprio script, restauro numa base limpa e comparação
linha a linha (ver [docs/deployment.md](deployment.md)).

## Acompanhamento dos registos (schema v15)

O acompanhamento dos registos (0.164.0) guarda duas coisas que não se
recalculam a partir dos registos: **o alerta que um caso teve** (quando e com
quantas ocorrências) e **as declarações «Registado no Inovar»** do professor.
A contagem em si nunca viaja — lê-se sempre dos `evidence_records`
restaurados, como em qualquer outro ecrã. As faltas de presença também não
viajam aqui: lêem-se da assiduidade (`lesson_attendances`), que tem a sua
própria coleção desde a v9.

`record_followup_cases`:

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade do caso |
| `class_ulid`, `enrollment_ulid`, `academic_period_ulid` | ULID | têm de vir NESTE backup, ser coerentes entre si (inscrição da turma, período do ano da turma) e ser `new` ou `existing` neste restauro |
| `category` | `homework_not_done`, `lateness`, `missing_material` | outro valor ⇒ `invalid` |
| `alerted_at` / `alerted_count` | ISO 8601 / inteiro ≥ 3, ou ambos `null` | `null` = caso anterior aos alertas. Um sem o outro ⇒ `invalid` |
| `created_at` / `updated_at` | ISO 8601 | preservadas |

`record_followup_confirmations`:

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade da declaração |
| `case_ulid` | ULID | o caso, que tem de vir neste backup |
| `confirmed_by_email` / `undone_by_email` | email ou `null` | autoria (ver abaixo) |
| `confirmed_at` / `undone_at` | ISO 8601 | `undone_at` `null` = em vigor. Quem desfez sem data ⇒ `invalid` |
| `occurrence_count` | inteiro | tem de ser igual ao número de `occurrences` |
| `occurrences` | lista de `{ulid, date}` (1–500) | os registos que a declaração abrangia; `ulid` `null` = registo que um restauro anterior já não recuperou |
| `created_at` / `updated_at` | ISO 8601 | preservadas |

**Nunca transportado**: `id`, `organization_id`, `class_id`,
`enrollment_id`, `academic_period_id`, `record_followup_case_id`,
`confirmed_by`, `undone_by`, `restored_at`. Nada disto é dado de aluno: os
nomes ficam em `students`, e as linhas só apontam para eles por ULID.

**Âmbito da exportação**: os casos das turmas que quem exporta leciona, com
todas as declarações (as desfeitas incluídas — são histórico). No
`Exportacao-Lapispro.xlsx`, a folha «Acompanhamento» mostra o estado legível
de cada caso com três ou mais ocorrências agora ou com linha guardada (ano
letivo, turma, disciplina, n.º, aluno, tipo, período, ocorrências e as suas
datas, estado, alerta, a declaração em vigor com quem e que datas abrangia,
e o histórico das desfeitas), e o «Resumo» ganha a linha «Nº de casos de
acompanhamento».

**Restauro — casos:**

| Situação no destino | Classificação |
|---|---|
| Turma, inscrição ou período que não vêm no backup, não são restauráveis ou não batem certo entre si | `invalid` |
| Mesmo `ulid`, mesma chave, mesmo alerta | `existing` |
| Mesmo `ulid`, mesma chave, alerta diferente | `conflict` — o destino é mantido; as declarações podem juntar-se-lhe |
| Mesmo `ulid`, outra chave | `conflict` — nunca reassociado |
| Sem `ulid` no destino, mas a mesma chave (turma × inscrição × período × tipo) já tem caso | `existing` ou `conflict`, como acima |
| Nenhum dos anteriores | `new` (`ulid` novo se o original existir noutra organização) |

Um caso restaurado guarda o `alerted_at` original. **O restauro nunca corre a
regra do alerta**: não cria alertas, não envia notificações nem emails, e um
caso com três ou mais ocorrências sem linha no backup fica como «anterior aos
alertas», como na primeira instalação da 0.164.0.

**Restauro — declarações:**

| Situação | Classificação |
|---|---|
| Caso `invalid`, ou `conflict` com outra chave | `invalid` |
| Mesmo `ulid` (ou, sem `ulid`, o mesmo caso com a mesma data ao segundo), mesmo estado | `existing` |
| Idem, estado diferente (por exemplo, desfeita no destino depois da exportação) | `conflict` — o destino nunca é sobrescrito |
| Declaração em vigor quando o caso já tem uma em vigor no destino ou noutra linha deste backup | `conflict` — nunca duas em vigor |
| Nenhum dos anteriores | `new` |

Cada ocorrência abrangida é procurada **por ULID no destino**: um registo que
já lá está, vivo, da mesma turma e do mesmo aluno, ou um registo que este
mesmo restauro cria (numa clonagem, com o seu `ulid` novo). Um registo que não
volta — eliminado no destino, recusado neste restauro ou ausente do backup —
**nunca é recriado**: a declaração entra com essa ocorrência como
`{ulid: null, date}`, `occurrence_count` intacto e `restored_at` preenchido.
A pré-visualização mostra-a em «Restauro incompleto», o resumo conta-a em
«Marcações restauradas sem todas as ocorrências», e o caso na consulta diz
«N das M ocorrências declaradas não foram recuperadas» — nunca a apresenta
como totalmente restaurada.

**Autoria.** Segue a regra geral ([Autoria](#autoria)): `confirmed_by` e
`undone_by` só ficam preenchidos quando o email é o de quem confirma a
importação; noutro caso ficam vazios, com o aviso em «Autoria original», e o
ecrã diz «autor não associado no restauro». Uma declaração nunca é atribuída
a quem importa.

**Cópias integrais da base.** O dump diário (`scripts/backup-database.sh`)
copia a base inteira sem lista de tabelas: as duas tabelas lá estão sem
alteração ao script.

## Outras atividades (schema v17)

As outras atividades (0.168.0, então «atividades não letivas») são parte do **horário pessoal** de um
professor — direção de turma, apoio a alunos, clubes, reuniões, coordenação ou cargos.
**Não são aulas**: vivem na tabela própria `timetable_activities`, nunca em
`recurring_lesson_slots`, e restaurá-las nunca cria uma `lessons`, um sumário
nem uma linha de assiduidade.

`timetable_activities` (dez chaves na v17; **doze desde a v19**, com `numbering_enabled` e `numbering_start`):

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade da atividade |
| `author_email` | email | o dono (o professor que exporta). Só se restaura quando é o da conta que confirma a importação; qualquer outro ⇒ `invalid` |
| `kind` | `class_direction`, `student_support`, `meeting`, `coordination`, `club` (**v19**), `other` | outro valor ⇒ a linha é `invalid` |
| `title` | texto até 60 caracteres | o que o professor escreveu («DT 6.º D»). Nunca aparece nas razões do preview |
| `class_ulid` | ULID ou `null` | referência **informativa** à turma. Se a turma não puder ser restaurada neste restauro, a atividade é restaurada sem turma — nunca perdida |
| `day_of_week` | 1 a 7 | fora disto ⇒ `invalid` |
| `starts_at`, `ends_at` | `HH:MM` | `ends_at` tem de ser posterior a `starts_at` |
| `starts_on`, `ends_on` | `YYYY-MM-DD` ou `null` | vigência (`null` = aberta); uma data presente mas malformada invalida a linha em vez de a abrir |
| `numbering_enabled` | booleano | **v19**. Ausente ⇒ `false`; presente e não booleano ⇒ `invalid` |
| `numbering_start` | inteiro 1–9999 | **v19**. Ausente ⇒ `1`; fora do intervalo ⇒ `invalid` |

**Quem exporta o quê.** `author_email` é sempre o do exportador: um colega
da mesma organização nunca leva as atividades de outro, qualquer que seja o
papel. As atividades não são filtradas pelas turmas exportadas — uma atividade
sem turma continua a ser do horário do professor.

**Idempotência.** A mesma `ulid` na organização ⇒ `existing` (ou `conflict` se
diverge). A mesma atividade restaurada antes com outra `ulid` (a de origem
pertencia a outra organização) reconhece-se pelo que é — tipo, designação, dia,
horas e vigência — e é `existing`. Reimportar o mesmo backup não duplica nada.

**Eliminação.** `user_id` e `organization_id` são `CASCADE`: uma configuração
pessoal não bloqueia a eliminação da conta nem da organização.

## Outras atividades e as suas sessões (schema v19)

Cada sessão de uma outra atividade (0.170.0) pode ter o seu registo — o
sumário, o estado e, se a atividade numerar, o número. Vive em
`timetable_activity_sessions` e **não é uma aula**: nunca cria uma `lessons`,
nunca entra em `LessonNumbering`.

**O nome no ficheiro é `timetable_activity_occurrences`, e não `…_sessions`.**
O `SecretScanner` recusa o ficheiro inteiro se alguma chave contiver `session`
(tokens de sessão), e com razão. Por isso a coleção chama-se *occurrences*, e o
número `sequence_number`. Na base de dados e no código a entidade continua a
chamar-se sessão.

`timetable_activity_occurrences` (doze chaves, nem mais nem menos):

| Campo | Forma | Notas |
|---|---|---|
| `ulid` | ULID | identidade da sessão |
| `author_email` | email | o dono. Só se restaura quando é o da conta que confirma a importação; qualquer outro ⇒ `invalid` |
| `activity_ulid` | ULID ou `null` | o bloco. `null` quando já não existe: a sessão fica identificada pela cópia |
| `activity_kind`, `activity_title` | tipo; texto até 60 caracteres | a **cópia** que identifica a sessão se o bloco mudar ou desaparecer. O título nunca aparece nas razões do preview |
| `class_ulid` | ULID ou `null` | referência informativa à turma; sem turma restaurável, `null` |
| `occurs_on` | `YYYY-MM-DD` | uma data que não existe no calendário ⇒ `invalid` |
| `starts_at`, `ends_at` | `HH:MM` | `ends_at` posterior a `starts_at` |
| `summary` | texto até 16000 caracteres, ou `null` | o sumário. **Nunca** aparece nas razões do preview |
| `status` | `planned`, `done`, `not_held` | outro valor ⇒ `invalid` |
| `sequence_number` | inteiro ≥ 1 ou `null` | o número da sessão; só conta numa `done` (noutro estado é descartado, como ao sair de «realizada»). Copiado tal como estava, nunca renumerado |

`id`, `organization_id`, `user_id`, `timetable_activity_id`, `class_id` e
`academic_year_id` nunca saem. **O ano letivo não viaja**: no restauro deriva-se
como ao criar a sessão (o da turma, se houver; senão o da organização que
contém a data).

**Quem exporta o quê.** Só as sessões do exportador **com registos** (texto ou
um estado que não seja «por realizar»): uma ocorrência por registar não é um
facto a levar. Nunca as de um colega, qualquer que seja o papel, e não são
filtradas pelas turmas exportadas.

**Idempotência.** A mesma `ulid` na organização ⇒ `existing` (ou `conflict` se
a data, a hora, o estado ou o texto divergem). A mesma sessão restaurada antes
com outra `ulid` reconhece-se pela chave natural — (atividade, data) quando
tem atividade, (designação, data, início) quando é órfã — e é `existing`.
Reimportar o mesmo backup não duplica nada. A atividade resolve-se pelo mapa das
restauradas deste mesmo ficheiro (ou já existentes); se não for restaurável, a
sessão volta como órfã com a cópia que leva.

**Ordem.** As sessões escrevem-se depois das atividades. Um backup v≤18, com
atividades e sem esta coleção, continua a restaurar sem tocar em nada.

## Retificações de classificações (schema v20)

Uma classificação **publicada** não se edita: quando é corrigida, uma linha nova
substitui-a e a antiga fica como história. O par fica ligado nos dois sentidos —
a antiga tem `status = superseded` e `superseded_by`, a nova tem `rectifies` — e
a **vigente** de uma célula (aluno × período × âmbito) é a que não está
substituída. Todos os leitores olham só para essa.

Cada linha de `classifications` ganha cinco chaves, todas `null` quando a linha
não é uma retificação:

| Campo | Forma | Notas |
|---|---|---|
| `rectifies_ulid` | ULID ou `null` | a linha que esta substitui; tem de constar do mesmo ficheiro |
| `rectification_reason` | `entry_error`, `calculation_error`, `review_or_complaint`, `council_decision`, `other` | outro valor ⇒ a linha é recusada |
| `rectification_note` | texto até 1000 caracteres, ou `null` | só existe com os outros quatro |
| `rectified_by_email` | email | o autor, pela mesma convenção de `confirmed_by_email` |
| `rectified_at` | ISO 8601 | quando |

`superseded_by_ulid` já existia e passa a ser usado: é o outro sentido do par.

**Validação** (`ValidateClassificationChains`). O que a base de dados recusa em
MySQL — e a suite, que corre em SQLite, não — recusa-se no ficheiro, antes de
qualquer plano. Cada linha recusada aparece em «Pontos a rever» com o motivo, e
**leva consigo toda a célula**, porque uma cadeia restaurada com um elo a menos é
uma história com um buraco, e uma célula sem a vigente é um aluno sem nota:

- `rectifies_ulid`, `rectification_reason`, `rectified_by_email` e `rectified_at`
  vêm todos ou nenhum (a nota só com eles);
- `status = superseded` se, e só se, há `superseded_by_ulid`;
- os dois ponteiros apontam para linhas do mesmo ficheiro, da mesma célula, nunca
  para a própria linha; e concordam — se A diz «fui substituída por B», B diz
  «retifico A», e vice-versa. É isto que torna a cadeia **linear** (um
  antecessor, um sucessor);
- sem ciclos; no máximo **uma** linha vigente por célula;
- uma retificação é sempre `published` (ou `superseded`, se foi retificada outra
  vez): é a única coisa que o serviço de retificação escreve.

**Autor.** `rectified_by` não pode ficar vazio (`classifications_rectification_complete_check`),
ao contrário de `confirmed_by`, que fica vazio quando o email não se resolve. É a
única autoria em que a regra «nunca atribuir à conta que importa» não se pode
cumprir sem perder a retificação inteira: quando o email é o da conta que
restaura (o restauro do próprio backup), é ele; **quando não é, a retificação
fica em nome da conta que restaura**, com um aviso na pré-visualização e contada
em «autorias não transportadas». A data, o motivo e a nota mantêm-se.

**Restauro** (`WriteClassifications`). As classificações sem cadeia escrevem-se
como sempre. Cada cadeia escreve-se da mais antiga para a mais recente, pela
mesma sequência de `RectifyClassification`, porque a tabela recusa as outras
(uma `superseded` não entra sem sucessora, a sucessora não entra enquanto a
anterior está viva — `classifications_one_live_unique` —, e as duas não entram
uma antes da outra):

1. a mais antiga entra `published`, sozinha e viva, sem ponteiro;
2. (a) passa a `superseded` com o ponteiro a apontar para **si própria** —
   temporário, satisfaz a CHECK e liberta o índice; (b) entra a seguinte, já com
   `rectifies` e os quatro campos; (c) o ponteiro da anterior passa para a
   seguinte;
3. repete-se ao longo da cadeia. Todas entram `published` menos a última, que
   entra com o estado que o backup lhe dava.

No fim confirma-se que nenhuma linha aponta para si própria e que sobra uma só
vigente; se não, a transação inteira desfaz-se (o restauro corre numa).

**Plano.** Uma cadeia só tem lugar se **todas** as linhas forem `new` e o destino
não tiver já uma vigente nessa célula. Senão as linhas `new` passam a `conflict`
(as que já lá estão ficam `existing`): nunca meia cadeia. Reimportar o mesmo
backup não duplica nada.

**O que não muda.** A retenção não apaga classificações (as chaves estrangeiras
são todas `RESTRICT`), e os ecrãs, relatórios e a Folha de Avaliação já leem só a
vigente. Os ficheiros legíveis (Excel) levam a classificação vigente, uma linha
por célula; o histórico só vive no backup.

## Dívida futura (fora do âmbito desta fatia, de propósito)

- **Registos eliminados nas outras coleções** — a issue #61 confirmou que
  restaurar o próprio backup depois de eliminar um `evidence_records` que ele
  contém fazia falhar a importação inteira. Corrigido na 0.164.0 para os
  registos pedagógicos (`conflict`, nunca reposto). `interventions`,
  `instruments` e `assessment_profiles` continuam suspeitas por reproduzir. O
  Caderno da turma (v14) já procurava incluindo os eliminados.
- **Estratégias e medidas e relatórios restaurados para uma turma que já
  existe** usam ainda o mapa de inscrições só com as `new` (ver
  [O mapa de inscrições](#o-mapa-de-inscrições-que-writeenrollments-não-dá));
  os registos pedagógicos deixaram de o usar na 0.164.0.
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
