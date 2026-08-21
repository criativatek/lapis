# Esquema do backup — v1/legacy vs v2

Fatia 6.1. Este documento é a referência de esquema do ficheiro que
`App\Actions\DataExports\GenerateDataExport` produz e que
`App\Services\Import\Backup\ValidateBackupPayload` valida: que coleções
existem, o que cada uma transporta, e o que cada versão do esquema sabe
restaurar. O mecanismo — passos, autorização, transação, limpeza — está em
[docs/data-import.md](data-import.md); este documento é só o "o quê", não o
"como".

## Princípio, repetido de propósito

**O backup transporta FACTOS; o LÁPIS calcula RESULTADOS.** Nenhuma coleção
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
| 4 (atual) | `Supported` | Estrutura, avaliação e acompanhamento pedagógico completos — todas as coleções desta página |
| 3 | `LegacyCompatible` | Só turmas/alunos/inscrições com `enrolled_on`; elementos de avaliação e classificações não existiam ainda no formato — linhas que os precisassem seriam `unsupported` |
| 2 | `LegacyCompatible` | Como a 3, mas sem `enrollments[].enrolled_on` — uma inscrição sem essa data não pode ser **criada** em segurança |
| < 2 | `Invalid` | Ficheiro recusado por inteiro |
| > 4 | `UnsupportedNewer` | Ficheiro recusado por inteiro — backup de uma versão do LÁPIS mais recente do que este código entende |

`App\Support\Import\Backup\BackupSchemaCompatibility` é a única fonte desta
tabela em código (`CURRENT = 4`, `MINIMUM_SUPPORTED = 2`). Não existe
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
| `academic_years` | Correspondência por rótulo | **Nunca criado** — decisão da Fatia 6, não reaberta nesta fatia. Em falta, tudo o que o referencia fica `invalid` |
| `subjects` | Correspondência por nome | Idem |
| `classes` | `ulid` | Referencia `assessment_profile_version_ulid` opcionalmente — se a versão não for restaurável, a turma continua a restaurar-se sem perfil (um estado legítimo; o professor atribui um perfil depois) |
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

## Autoria

Todo campo de autoria (`assessed_by`, `confirmed_by`, `overridden_by`,
`created_by`, `reviewed_by`, `finalized_by`, …) é exportado como um **email**,
nunca um id. Na importação, a única correspondência segura é "o autor desta
linha é literalmente quem está a confirmar esta importação" — o email do
`$actor` comparado sem distinguir maiúsculas/minúsculas
(`App\Services\Import\Backup\Concerns\ResolvesBackupReferences::resolveAuthor()`).
Qualquer outro email:

- Num campo de autoria que aceita `null` na base de dados → fica vazio; o
  facto sobrevive, a proveniência não.
- Num campo `NOT NULL` (por exemplo `interim_assessments.created_by`,
  `reports.finalized_by`) → a linha inteira fica `invalid`, em vez de
  inventar um autor.

Nunca há correspondência contra outros utilizadores da organização de
destino por email — isso seria atribuir uma linha à conta de um
desconhecido.

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
