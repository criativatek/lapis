# Utilização e acompanhamento — desenho técnico (0.163.0)

Base: `origin/main` e35de285 (0.162.0). Branch `feat/admin-usage-insights`,
worktree `D:/worktrees/lapis-usage-insights`.

As definições, a política (finalidade, dados, conservação, acesso) e as
limitações estão em [docs/usage-insights.md](../../usage-insights.md) — **é a
fonte das regras**. Este documento diz como se implementam.

## 0. Princípios que não se negociam

1. **Catálogo fechado.** Só se grava o que está num enum. Sem JSON, sem texto
   livre, sem propriedades arbitrárias, sem IDs de registos pedagógicos.
2. **Nunca impedir o trabalho do professor.** Tudo o que grava telemetria está
   num `try/catch (Throwable)`; uma falha vai para `Log::warning('usage.record_failed', ['event' => …, 'exception' => $e::class])`
   — **nunca** `$e->getMessage()`.
3. **Só depois do commit.** As ações gravam-se em `DB::afterCommit()`.
4. **Uma ação = um registo por dia.** `dedupe_key` única + `insertOrIgnore`.
5. **O painel não lê dados pedagógicos.** Lê `usage_daily_rollups`,
   `usage_user_states`, `usage_collection_periods`, `usage_aggregation_state`,
   `users`, `organizations`, `organization_memberships`,
   `organization_subscriptions`/`plans` e `academic_calendar_exceptions`
   (calendário, não conteúdo). `class_teachers` só é lido pela agregação.
6. **Nada inventado.** Períodos sem recolha são `null`, nunca `0`.
7. Modelos de utilização **não** usam `BelongsToOrganization`: são de
   plataforma e só o backoffice os lê.

## 1. Esquema

### `2026_11_18_000100_create_usage_tables.php`

```php
Schema::create('usage_events', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->dateTime('occurred_at');
    $table->date('occurred_on');
    $table->string('feature', 24);
    $table->string('event', 48);
    $table->string('kind', 8);                        // view | action | failure
    $table->unsignedInteger('item_count')->nullable(); // só kind=action, >= 1
    $table->string('failure_code', 24)->nullable();    // só kind=failure
    $table->char('dedupe_key', 64)->unique();
    $table->timestamp('created_at')->nullable();
    $table->index(['occurred_on', 'user_id'], 'usage_events_day_user_index');
    $table->index(['user_id', 'occurred_on'], 'usage_events_user_day_index');
    $table->index(['organization_id', 'occurred_on'], 'usage_events_org_day_index');
});

Schema::create('usage_daily_rollups', function (Blueprint $table) {
    $table->id();
    $table->date('day');
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
    $table->string('feature', 24);
    $table->unsignedInteger('views')->default(0);
    $table->unsignedInteger('actions')->default(0);
    $table->unsignedInteger('useful_actions')->default(0);
    $table->unsignedInteger('items')->default(0);
    $table->unsignedInteger('failures')->default(0);
    $table->unique(['day', 'user_id', 'organization_id', 'feature'], 'usage_rollups_identity_unique');
    $table->index(['user_id', 'day'], 'usage_rollups_user_day_index');
    $table->index(['organization_id', 'day'], 'usage_rollups_org_day_index');
});

Schema::create('usage_user_states', function (Blueprint $table) {
    $table->id();
    $table->ulid('ulid')->unique();                    // chave de rota do detalhe
    $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
    $table->dateTime('class_available_since')->nullable(); // MIN(class_teachers.created_at) — configuração
    $table->date('first_class_created_on')->nullable();    // primeira OBSERVAÇÃO
    $table->date('first_students_added_on')->nullable();
    $table->date('first_useful_record_on')->nullable();
    $table->dateTime('computed_at');
    $table->timestamps();
});

Schema::create('usage_collection_periods', function (Blueprint $table) {
    $table->id();
    $table->dateTime('started_at');
    $table->dateTime('ended_at')->nullable();
});

Schema::create('usage_aggregation_state', function (Blueprint $table) {
    $table->unsignedTinyInteger('id')->primary();     // sempre 1
    $table->date('aggregated_through')->nullable();
    $table->dateTime('aggregated_at')->nullable();
});

DB::table('usage_collection_periods')->insert(['started_at' => now()]);
```

CHECKs só em MySQL/MariaDB (guardados por `getDriverName()`, como as outras
migrações): `kind in ('view','action','failure')`;
`(kind = 'action') = (item_count is not null)`;
`(kind = 'failure') = (failure_code is not null)`;
`item_count is null or item_count > 0`. `down()` apaga as cinco tabelas.

### `2026_11_18_000200_add_usage_columns_to_users_table.php`

`users.can_view_usage_insights` boolean default false;
`users.usage_opted_out_at` timestamp nullable. Nenhum dos dois em `#[Fillable]`.

## 2. Catálogo — `App\Support\Usage\*`

- `UsageFeature` (string): `classes` «Turmas e alunos», `lessons` «Aulas e
  sumários», `assessment` «Avaliação», `records` «Registos», `interventions`
  «Estratégias e medidas», `characterisation` «Caracterização pedagógica»,
  `class_notebook` «Caderno da turma», `reports` «Relatórios», `import_export`
  «Importação e exportação», `calendar` «Calendário». `label()`.
- `UsageEventKind`: `view`, `action`, `failure`.
- `UsageFailureCode`: `database`, `storage`, `external_service`, `timeout`,
  `unexpected`; `fromThrowable(Throwable)`:
  `QueryException|PDOException` → database; `League\Flysystem\FilesystemException`
  → storage; `Illuminate\Http\Client\ConnectionException|RequestException`,
  `Symfony\Component\Mailer\Exception\TransportExceptionInterface` →
  external_service; `Symfony\Component\Process\Exception\ProcessTimedOutException`
  → timeout; resto → unexpected.
- `UsageMilestone`: `first_class_created`, `first_students_added`,
  `first_useful_record`; `label()`; `events(): list<UsageEventType>`.
- `UsageEventType` (string) — o catálogo. Métodos: `kind()`, `feature(): ?UsageFeature`
  (nulo só para `feature.viewed` e `operation.failed`, cuja funcionalidade vem do
  contexto), `isUsefulRecord()`, `milestone(): ?UsageMilestone`, `label()`.

| Caso | valor | feature | útil | marco |
|---|---|---|---|---|
| FeatureViewed | `feature.viewed` | (contexto) | | |
| OperationFailed | `operation.failed` | (contexto) | | |
| ClassCreated | `class.created` | classes | | first_class_created |
| StudentsAdded | `students.added` | classes | | first_students_added |
| LessonSummarySaved | `lesson.summary_saved` | lessons | ✔ | first_useful_record |
| LessonAttendanceRecorded | `lesson.attendance_recorded` | lessons | ✔ | first_useful_record |
| LessonTaught | `lesson.taught` | lessons | | |
| LessonOutcomeRecorded | `lesson.outcome_recorded` | lessons | | |
| LessonSequenceApplied | `lesson_sequence.applied` | lessons | | |
| InstrumentCreated | `instrument.created` | assessment | ✔ | first_useful_record |
| ScoresRecorded | `scores.recorded` | assessment | ✔ | first_useful_record |
| ClassificationDecided | `classification.decided` | assessment | ✔ | first_useful_record |
| ClassificationsPublished | `classifications.published` | assessment | | |
| EvaluationSheetSaved | `evaluation_sheet.saved` | assessment | ✔ | first_useful_record |
| InterimAssessmentSaved | `interim_assessment.saved` | assessment | | |
| RecordCreated | `record.created` | records | ✔ | first_useful_record |
| HomeworkBatchSaved | `record.homework_batch_saved` | records | ✔ | first_useful_record |
| InterventionCreated | `intervention.created` | interventions | ✔ | first_useful_record |
| InterventionFollowupAdded | `intervention.followup_added` | interventions | ✔ | first_useful_record |
| CharacterisationUpdated | `characterisation.updated` | characterisation | ✔ | first_useful_record |
| CharacterisationImported | `characterisation.imported` | characterisation | | |
| NotebookEntryCreated | `class_notebook.entry_created` | class_notebook | ✔ | first_useful_record |
| NotebookEntryUpdated | `class_notebook.entry_updated` | class_notebook | | |
| ReportCreated | `report.created` | reports | ✔ | first_useful_record |
| ReportFinalized | `report.finalized` | reports | | |
| ReportExported | `report.exported` | reports | | |
| StudentsImported | `students.imported` | import_export | | first_students_added |
| TimetableImported | `timetable.imported` | import_export | | |
| CalendarImported | `calendar.imported` | import_export | | |
| BackupExported | `backup.exported` | import_export | | |
| BackupRestored | `backup.restored` | import_export | | |
| InovarExported | `inovar.exported` | import_export | | |
| CalendarEventCreated | `calendar_event.created` | calendar | | |

(`first_useful_record` = todos os `isUsefulRecord()`.)

## 3. Recolha

### `UsageRecorder` (singleton)

```php
public function action(UsageEventType $type, Model|string|null $subject = null, int $items = 1): void;
public function view(UsageFeature $feature): void;
public function failure(UsageFeature $feature, UsageFailureCode $code, string $operation): void;
public function fingerprint(?Model $model, array $attributes): ?string; // sha1 de json dos atributos, null se modelo null
```

Guardas — se alguma falhar, sai em silêncio:

1. a recolha está ligada (há um período aberto — secção 11; já não é
   `config('usage.enabled')`);
2. há pedido HTTP com utilizador autenticado (`request()->user()`); sem
   utilizador (consola, scheduler, jobs) não grava;
3. não há `impersonator_id` na sessão (`$request->hasSession()` primeiro);
4. `usage_opted_out_at`, `anonymized_at` e `deactivated_at` nulos;
5. organização resolvida em `CurrentOrganization` (ver a API da classe);
6. `action()`: o tipo é `kind() === Action` e `$items >= 1`.

Linha: `occurred_at = now()` (fuso da app), `occurred_on = occurred_at->toDateString()`,
`created_at = now()`, `feature` do tipo (ou do argumento), `item_count` só nas
ações, `failure_code` só nas falhas.

`dedupe_key = hash_hmac('sha256', implode('|', $parts), hash('sha256', 'lapis.usage.dedupe|'.config('app.key')))`:

- consulta: `[user, org, 'view', feature, dia]`;
- ação: `[user, org, tipo, subjectKey, dia]`; `subjectKey` = string dada, ou
  `class_basename($model).':'.$model->getKey()`, ou `''`;
- falha: `[user, org, 'failure', feature, operation, código, 'Y-m-d H:i']`.

Escrita: `DB::afterCommit(fn () => UsageEventModel::query()->insertOrIgnore($row))`,
com `try/catch` **dentro** do callback e à volta de tudo.

### Middleware `RecordUsage` (alias `usage`, parâmetro = feature)

- Antes de `$next`: `$request->attributes->set('usage.feature', $feature)` — o
  mais interior escreve por último e ganha.
- Depois: se `usage.handled` já está marcado, sai; marca-o. Resolve a feature
  com `UsageFeature::tryFrom()` (inválida → `Log::warning` e sai). Tudo em `try/catch`.
- **Falha**: `status >= 500`, a resposta tem `exception` (respostas Illuminate) e
  a exceção **não** é `HttpExceptionInterface` → `failure(feature, fromThrowable(e), routeName ?? 'unnamed')`.
- **Consulta**: `GET`, `status === 200`, (`X-Inertia` no pedido **ou**
  `Content-Type` da resposta começa por `text/html`), **sem**
  `X-Inertia-Partial-Component` / `X-Inertia-Partial-Data` /
  `X-Inertia-Partial-Except`, e não é pré-carregamento (`Purpose: prefetch`,
  `Sec-Purpose` contém `prefetch`, `X-Moz: prefetch` — confirmar no código do
  `@inertiajs/core` instalado que cabeçalho a v3 usa e cobri-lo).

Aplicação nas rotas (`routes/web.php`) — acrescentar ao `middleware()` dos
grupos existentes, sem reordenar rotas:

| Grupo | feature |
|---|---|
| `module:classes` (l. 421) e `module:students` (l. 542) | `classes` |
| rotas `classes.characterisation*` e `classes.characterisation-imports.*` dentro de `module:classes` | `characterisation` (por rota) |
| rotas `classes.notebook.*` | `class_notebook` (por rota) |
| `classes.roster-imports.*` e `classes.photos.store` | `import_export` (por rota) |
| `module:lessons` (l. 417 e l. 546) | `lessons` |
| `module:instruments`, `module:assessments`, `module:correction_grid_import`, `module:results`, `module:student_progress`, `module:self_assessments` | `assessment` |
| grupos `module:inovar_export` dentro de results | `import_export` |
| `module:records` | `records` |
| `module:interventions` | `interventions` |
| `module:reports` | `reports` |
| rotas `data-exports.*`, `data-imports.*` (`module:data_backup_restore`), `timetable-imports.*`, `academic-calendar-imports.*` | `import_export` |
| `module:calendar` | `calendar` |

Um teste percorre `Route::getRoutes()` e falha se algum parâmetro de `usage:`
não for um `UsageFeature`.

### Pontos de registo de ações

**Regra:** chamar o recorder no CONTROLADOR, depois de a Action/Service ter
devolvido com sucesso. Para comparar antes/depois, ler com uma consulta NOVA
(`Model::query()->whereKey(...)->first()`), **nunca** carregar uma relação no
modelo vindo do route binding (deixaria a relação em cache e mudaria o que o
controlador devolve a seguir).

| Evento | Onde | subject | items | Só se |
|---|---|---|---|---|
| ClassCreated | `ClassController::store` | turma | 1 | — |
| StudentsAdded | `EnrollmentController::store`, `SupportClassStudentController::store` | matrícula | 1 | — |
| StudentsImported | `RosterImportController::confirm` | `'roster:'.$token` | alunos **criados** | criados > 0 |
| LessonSummarySaved | `LessonController::updateSummary` e `::updateSummaryContent` | aula | 1 | impressão digital de (`content`, `private_notes`, `resources`, `homework` do sumário + `status` da aula) mudou |
| LessonTaught | `LessonController::markTaught` | aula | 1 | estado antes ≠ `taught` |
| LessonTaught (lote) | `LessonBatchController::store` | `'lesson-batch:'.sha1(ULIDs ordenados do pedido)` | aulas marcadas | marcadas > 0 |
| LessonOutcomeRecorded | `LessonController::recordOutcome` | aula | 1 | `outcome` mudou |
| LessonAttendanceRecorded | `LessonAttendanceController::record` | aula | 1 | — |
| LessonSequenceApplied | `LessonSequenceController::apply` | sequência | aulas afetadas (do resultado) | > 0 |
| InstrumentCreated | `InstrumentController::store` | instrumento | 1 | — |
| ScoresRecorded | `InstrumentController::saveScores` | instrumento | células **alteradas** | alteradas > 0 |
| ClassificationDecided | `ClassificationController::decide` | `'classification:'.enrollment.':'.period` | 1 | — |
| ClassificationsPublished | `ClassificationController::publish` | `'publish:'.class.':'.period` | publicadas | > 0 |
| EvaluationSheetSaved | `EvaluationSheetHistoryController::store` | `'sheet:'.payload_hash` | 1 | o `payload_hash` difere do registo anterior da mesma turma/período |
| InterimAssessmentSaved | `InterimAssessmentController::store` | `'interim:'.hash` | 1 | idem com a anterior da turma |
| RecordCreated | `EvidenceController::store` | registo | alunos abrangidos | — |
| HomeworkBatchSaved | `EvidenceController::updateHomeworkBatch` | `'homework:'.batch` | alunos tocados | > 0 |
| InterventionCreated | `InterventionController::store` | `batch_ulid` ou a intervenção | medidas criadas | — |
| InterventionFollowupAdded | `InterventionController::addReview` | intervenção | 1 | — |
| CharacterisationUpdated | `ClassCharacterisationController::update` / `::updateStudent` | turma / matrícula | secções alteradas | lista não vazia |
| CharacterisationImported | `CharacterisationImportController::store` | turma | decisões aplicadas | > 0 |
| NotebookEntryCreated | `ClassNotebookController::store` | entrada | 1 | — |
| NotebookEntryUpdated | `ClassNotebookController::update` | entrada | 1 | depois do retorno antecipado «sem alteração» |
| ReportCreated | `ReportController::store` | relatório | 1 | — |
| ReportFinalized | `ReportController::finalize` | relatório | 1 | — |
| ReportExported | `ReportExportController::pdf` / `::docx` | relatório | 1 | resposta gerada |
| BackupExported | `DataExportController::store` | — | 1 | — |
| BackupRestored | `DataImportController::confirm` (sucesso) | data import | total de linhas restauradas (do resultado) | > 0 |
| (falha) | `DataImportController::confirm` (`catch`) | — | — | `failure(import_export, fromThrowable(e), 'data-imports.confirm')` |
| TimetableImported | `TimetableImportController::confirm` | `'timetable:'.$token` | tempos criados | > 0 |
| CalendarImported | `AcademicCalendarImportController::confirm` | `'calendar:'.$token` | elementos criados | > 0 |
| InovarExported | `InovarExportController::generate`, `EvaluationSheetInovarExportController::confirm` | período/turma | 1 | resposta gerada |
| CalendarEventCreated | `CalendarEventController::store` | acontecimento | 1 | — |

`RecordScores` passa a contar `changed` — células cujo `result_state`,
`points_earned`, `scale_level_id` ou `state_reason` mudaram (`isDirty([...])`
depois do `fill`, antes do `save`; uma célula nova conta sempre) — e expõe-no em
`RecordScoresResult`. Nada mais muda em `RecordScores`.

Se um destes pontos não tiver forma de saber o número de elementos sem tocar na
Action, usar o que o controlador já recebe do resultado; não alterar Actions
além de `RecordScores`.

## 4. Agregação — `usage:aggregate` (a cada 15 min, `withoutOverlapping`)

`App\Services\Usage\AggregateUsage::run(CarbonImmutable $now)`:

1. **Cobertura** (`UsageCoverage::sync`): recolha ligada e nenhum período aberto
   → abre um (`started_at = now`); desligada e há um aberto → fecha-o.
2. **Dias a recalcular**: de `min(aggregated_through, ontem)` (ou do primeiro
   `occurred_on` existente, ou hoje) até hoje, nunca antes do limite de
   conservação.
3. Por dia, numa transação: apagar `usage_daily_rollups` desse dia; ler os
   eventos do dia (`user_id, organization_id, feature, event, kind, item_count`);
   agrupar **em PHP** por (user, org, feature): `views` = nº de `view`, `actions`
   = nº de `action`, `useful_actions` = ações com `isUsefulRecord()`, `items` =
   soma de `item_count` das ações, `failures` = nº de `failure`; inserir.
   Idempotente: correr duas vezes dá o mesmo.
4. **Estados** (`usage_user_states`) para todos os utilizadores com
   `anonymized_at` e `usage_opted_out_at` nulos, em blocos:
   - `class_available_since` = `MIN(class_teachers.created_at)` do utilizador;
   - `first_*_on` = `min(valor guardado, MIN(occurred_on) dos eventos do marco)` —
     pegajoso: a conservação apaga eventos, e a data já observada não pode
     andar para a frente;
   - linhas novas recebem `ulid`; `computed_at = now`.
   - apaga estados de utilizadores anonimizados ou que se opuseram.
5. `usage_aggregation_state(id=1)`: `aggregated_through = hoje`, `aggregated_at = now`.

## 5. Conservação e encerramento

- `config/retention.php` → `'usage_metrics_days' => (int) env('LAPIS_USAGE_RETENTION_DAYS', 365)`;
  leitura em `RetentionPolicy`.
- `usage:prune` (diário 04:05, `withoutOverlapping`, `onFailure($reportFailure)`):
  apaga `usage_events` com `occurred_on < hoje − N` e `usage_daily_rollups` com
  `day < hoje − N`, em blocos; e, como rede de segurança, tudo de utilizadores
  anonimizados ou que se opuseram. Imprime contagens.
- `App\Actions\Usage\ForgetUsageData::forUser(User)`: apaga eventos, agregados e
  estado do utilizador. Usado por `AnonymiseClosedAccount` (dentro da transação)
  e por `lapis:usage-opt-out`.
- `lapis:usage-opt-out {email} {--undo}`: grava/limpa `usage_opted_out_at`; ao
  gravar, chama `ForgetUsageData`.

## 6. Acesso

- `User::canViewUsageInsights(): bool` = `is_platform_admin && can_view_usage_insights`.
- `EnsureUsageInsightsAccess` (alias `usage-insights`): `abort(403, 'Acesso reservado a pessoal autorizado.')`.
- Rotas em `routes/admin.php`, dentro do grupo admin, com `->middleware('usage-insights')`:
  - `GET admin/usage` → `admin.usage.overview`
  - `GET admin/usage/users` → `admin.usage.users.index`
  - `GET admin/usage/users/{usageUser}` (ulid de `usage_user_states`) → `admin.usage.users.show`
  - `GET admin/usage/ranking` → `admin.usage.ranking`
  - `POST admin/accounts/{organization}/usage-insights` → `admin.accounts.usage-insights` (conceder/revogar ao dono; o dono tem de ser admin da plataforma; recusa revogar-se a si próprio; `AuditLog::recordPlatform('admin.usage_insights_granted'|'admin.usage_insights_revoked', $actor, $summary, ['target_user_id' => …])`; o pedido envia `granted` (estado desejado) e um pedido que não muda nada não grava nem audita).
- `lapis:grant-usage-insights {email} {--revoke}` (recusa conceder a quem não é admin da plataforma).
- `lapis:make-admin --revoke` e `AdminAccountController::toggleAdmin` (revogar) também põem `can_view_usage_insights = false`.
- `HandleInertiaRequests`: `auth.can_view_usage_insights`.
- `AccountShow.vue`: botão «Conceder/Revogar acesso às métricas de utilização» só quando o operador tem a autorização e o dono é admin da plataforma (props novas do controlador: `usage_insights: { can_manage: bool, owner_is_admin: bool, granted: bool }`).

## 7. Leitura (`App\Services\Usage\Insights\*`)

### Filtros — `UsageInsightsRequest` → `UsageFilters`

`period` ∈ `7|30|90|custom` (omissão 30); `from`/`to` (Y-m-d, só em custom;
`from <= to <= hoje`, máx. 366 dias); `plan` ∈ chaves de `plans` ou nulo;
`organization` (ulid) ou nulo; `segment` ∈ `main|test` (omissão main). Em
`users`: `q` (≤ 100), `signal` (chave de sinal), `sort` ∈
`last_activity|name|active_days|actions`. Em `ranking`: `sort` ∈
`active_days|actions|active_weeks|features` (omissão active_days), `age` ∈
`all|full|lt30|30to90|gt90`.

Presets: `from = hoje − (n − 1)`, `to = hoje`. `from` antes do limite de
conservação é **ajustado** a esse limite, com nota.

### Âmbito

- Organizações no âmbito: filtro de organização e de plano (plano em vigor via
  `CurrentSubscriptions`: a subscrição `isInForce()` mais recente, senão a mais
  recente — mesma regra de `AdminAccountController::currentSubscriptions`, que
  fica intocado).
- Segmento `main`: `organizations.is_test_account = false` **e** utilizador sem
  `is_platform_admin` nem `is_support_technician`. `test`: o complemento.
- Sempre fora: `anonymized_at` não nulo.
- Atividade (rollups) filtra por organização e segmento **por linha**; um
  utilizador conta uma vez (`COUNT(DISTINCT user_id)`).
- Utilizadores do âmbito = membros (`organization_memberships`) de organizações
  do âmbito que cumpram o segmento.

### Cobertura — `UsageCoverage`

`periods()`, `startedAt()`, `startDay()`, `isDayCovered(day)` (algum período
toca o dia), `isDayFullyCovered(day)`, `coveredDaysIn(from, to)`,
`fullyCoveredSince(instant, to)` (nenhum buraco entre o instante e `to`).
Payload `coverage`: `collection_started_at`, `start_day`, `retention_cutoff`,
`aggregated_at`, `observed_from`, `observed_to`, `period_status`
(`complete|partial|none`), `notes: string[]` (pt-PT, prontas a mostrar).

### Semanas e meses — `UsagePeriods`

Semanas de segunda a domingo que tocam `[from, to]`. Estado:
`no_coverage` (nenhum dia da semana dentro do período tem recolha → números `null`) ·
`in_progress` (contém hoje) · `partial_period` (cortada pelo período) ·
`partial_coverage` (algum dia sem recolha completa) · `complete`. Os números
contam só dias do período com recolha. Meses: igual, por mês civil.

### Visão geral — props de `admin/usage/Overview`

```ts
{
  filters: Filters; options: FilterOptions; coverage: Coverage; definitions: { term: string; text: string }[];
  headline: {
    active_7: { value: number | null; observed_days: number; window_days: 7 };
    active_30: { value: number | null; observed_days: number; window_days: 30 };
    active_period: number | null;
    new_accounts: number;
    first_useful_record: { done: number; of: number; before_collection: number };
  };
  weeks: { start: string; end: string; status: WeekStatus; active_users: number | null; active_user_days: number | null; actions: number | null; items: number | null }[];
  months: { month: string; label: string; status: WeekStatus; active_users: number | null }[];
  features: { key: string; label: string; users: number; users_with_actions: number; actions: number; items: number; failures: number }[];
  signals: { key: string; label: string; description: string; count: number; not_evaluated: number }[];
  cohorts: { week_start: string; week_end: string; size: number; weeks: { offset: number; status: 'complete' | 'in_progress' | 'not_started'; returned: number | null }[] }[];
}
```

- `first_useful_record`: das **novas contas do período** criadas depois do início
  da recolha (`of`), quantas têm `first_useful_record_on` (`done`);
  `before_collection` = novas contas do período criadas antes da recolha (fora
  do denominador).
- `cohorts`: contas do âmbito criadas no período **e** depois do início da
  recolha, por semana de adesão; `offset` 0..4.

### Acompanhamento — `admin/usage/Users` e `admin/usage/User`

Lista (paginada, 25): utilizadores do âmbito com estado agregado
(`usage_user_states`); pesquisa em nome/email (`like`). Linha:

```ts
{ ulid; name; email; joined_on; plans: string[]; organizations: number;
  flags: { test: boolean; internal: boolean };
  last_activity_on: string | null;      // MAX(day) dos rollups do âmbito, fora do período
  active_days: number; actions: number; items: number;
  features: { key: string; label: string }[];
  signals: Signal[]; status: string | null;
  observation_days: number; period_days: number }
```

`status` descritivo: «Conta em encerramento», «Conta desativada», «Conta criada
durante o período», «Sem atividade observada no período», ou nulo. Nunca
«inativo», «abandono», «mau».

Detalhe: cabeçalho (nome, email, adesão, organizações com plano e marca de
teste, marcas internas, estado), `first_steps` (marco, `observed_on`, nota
«Conta anterior à recolha — pode ter acontecido antes»), `class_available_since`
(rotulada «Turma disponível desde — configuração, não prova de utilização»),
`signals`, `summary` (`active_days, active_weeks, actions, items, failures,
last_activity_on, observation_days, period_days`), `weeks` (por semana:
`start, end, status, active_days, actions, items, failures, features[]`) e
`features` (por funcionalidade: `active_days, view_days, actions, items,
failures`). **Nada de conteúdos, nada de lista de eventos.**

### Sinais — `FollowUpSignals`

Ver as regras em docs/usage-insights.md. Implementação:

- `config/usage.php` → `signals.no_class.days` (7), `signals.no_first_record.days`
  (7), `signals.went_quiet.{quiet_days: 14, lookback_weeks: 4, min_active_weeks: 2}`,
  `signals.technical_failures.{days: 7, threshold: 3}`, cada um com `enabled`.
- Avaliados sobre **toda** a atividade da pessoa (todas as organizações), mas
  listados só para quem está no âmbito.
- Dias não letivos por utilizador: `academic_calendar_exceptions` (tipos
  `holiday`, `school_break`, `non_teaching_day`) das organizações de que é membro;
  um dia é não letivo se o for em **qualquer** delas. `has_calendar` = alguma
  das suas organizações tem exceções num ano letivo que toca a janela.
- `Signal = { key; label; reason; window_from; window_to }` — `reason` em pt-PT,
  com datas `dd/mm` e a menção «dias de calendário» ou «sem contar N dias não
  letivos».
- Devolve também, por regra, quantos ficaram **por avaliar** por cobertura.
- Contas em encerramento, desativadas ou anonimizadas: nem sinal, nem «por avaliar».

### Ranking — `admin/usage/Ranking`

Linhas só de quem tem `active_days > 0`; os restantes contam em `unranked`.
`position` = posição densa no critério escolhido (empates partilham, `tied: true`);
ordem secundária: nome. Paginado (50).

```ts
{ position; tied; ulid; name; email; plans: string[]; joined_on; account_age_days;
  observation_days; period_days; partial_observation: boolean;
  active_days; actions; items; active_weeks; observed_weeks; features: number }
```

`observation_days` = dias do período com recolha e com a conta já criada.
`observed_weeks` = semanas (seg–dom) com pelo menos um dia observado.
`age`: `full` = `observation_days === period_days`; `lt30`/`30to90`/`gt90` pela
idade da conta hoje.

## 8. Interface

- `AdminLayout.vue`: item «Utilização» (ícone `Activity`), `/admin/usage`, ativo
  em `/admin/usage*`, **só** quando `auth.can_view_usage_insights`.
- Páginas em `resources/js/pages/admin/usage/` (`Overview.vue`, `Users.vue`,
  `User.vue`, `Ranking.vue`); componentes em
  `resources/js/components/admin/usage/` (`UsageTabs`, `UsageFilters`,
  `UsageCoverageNotice`, `UsageDefinitions`, `UsageSignalBadge`).
- Gráfico semanal com `vue-chartjs` (já no projeto — seguir um gráfico
  existente), semanas parciais/em curso com cor atenuada e legenda, e **sempre**
  uma tabela equivalente. Sem recolha → coluna vazia «sem recolha», nunca barra a zero.
- Percentagens só com denominador visível («4 de 9 · 44%»).
- DESIGN.md manda: uma cor forte, WCAG AA, alvos ≥ 44px em 375px, tabelas com
  `overflow-x-auto` dentro do cartão (sem scroll horizontal da página).
- Texto de apoio do ranking, literal: «Compara a regularidade e as ações
  concluídas na aplicação. Não avalia a qualidade do trabalho pedagógico.»

## 9. Testes (PHPUnit, `tests/Feature/Usage/`)

Datas fixas com `travelTo()` **antes** de criar dados (ver a armadilha do
relógio); um helper para fixar a cobertura (`usage_collection_periods`) em cada
teste.

- Recorder: grava; dedupe (mesmo registo/dia → 1; dia seguinte → 2); impersonação
  não grava; sem utilizador não grava; `opted_out` não grava; transação revertida
  não grava; tabela apagada (`Schema::drop`) → a operação do professor continua
  bem-sucedida e há `usage.record_failed` no log.
- Middleware: visita completa grava; parcial, pré-carregamento, JSON e redirect
  não; 500 grava falha com código; 422/403/404/419 não; rota interior ganha à do grupo.
- Ganchos: sumário (alteração/sem alteração/dedupe), classificações (células
  alteradas), importação da lista (lote = 1 ação, N elementos), caderno (sem
  alteração não conta), registo com N alunos, falha do restauro, intervenção em lote.
- Definições: dias e semanas na mudança de hora (2026-10-25 e 2027-03-28,
  Europe/Lisbon); semana seg–dom; 7/30 dias.
- Exclusões: contas de teste, internos, impersonação, consola.
- Deduplicação de utilizadores com duas organizações.
- Filtros (plano, organização, segmento), percentagens com denominador,
  períodos parciais, sem recolha = `null`, regresso semanal com semana em curso e
  por começar.
- Sinais: cada regra com dados suficientes e insuficientes; interrupção letiva.
- Ranking: ordenações, empates, observação parcial, filtro de antiguidade.
- Acesso: professor 403, admin sem autorização 403, autorizado 200, dono de
  organização institucional 403, comandos e botão de concessão.
- Privacidade: colunas de `usage_events` = lista exata; sentinela escrita num
  sumário/caderno/intervenção não aparece em nenhuma tabela de utilização;
  catálogo fechado.
- Conservação: `usage:prune`; `AnonymiseClosedAccount` apaga; `lapis:usage-opt-out`.
- Agregação idempotente; recalcula ontem; estados pegajosos.

## 10. Revisão de 2026-10-12 (antes do merge)

O que mudou em relação às secções acima, e prevalece sobre elas:

- **Recolha desligada por omissão** — a forma de a ligar e desligar foi
  substituída na secção 11 (já não há `usage.enabled` nem `ensureOpen()`). Com a
  recolha inativa, a agregação ainda agrega eventos existentes, mas não lê
  `class_teachers` nem atualiza `aggregated_at`; os sinais que dependem da
  atividade ficam por avaliar.
- **Política dependente da ativação:** `App\Support\Legal\PrivacyVersion` —
  `effectiveAt()` = máximo entre `privacy_effective_from` e a primeira ativação;
  `User::shouldSeePrivacyNotice()` compara instantes.
- **Conservação:** `retention.usage_events_days` (35) e
  `retention.usage_rollups_days` (180) substituem `usage_metrics_days`;
  `RetentionPolicy::usageEventsDays()` = mínimo dos dois. (A espera pela
  agregação antes de apagar eventos foi substituída na secção 11.) A agregação
  nunca recalcula antes do limite dos agregados, ignora quem se opôs ou foi
  anonimizado ao ler os eventos e apaga, em cada passagem, os seus restos.
- **Encerramento:** a recolha pára no pedido (`closure_requested_at`); a
  eliminação passou para o próprio pedido na secção 11.
- **Discriminação de lotes:** `items_created`, `items_changed`, `items_unchanged`
  em `usage_events` e `usage_daily_rollups`; `item_count = created + changed`
  (o que não se sabe fica nulo).
- **Edições:** `report.edited`, `intervention.updated`, `instrument.updated`,
  `calendar_event.updated` (`App\Support\Usage\UsageSnapshots`).
- **Classificação:** `organizations.usage_classification`; o painel não lê
  `is_test_account`. Segmentos `real | test | internal | unclassified`.

## 11. Revisão de 2026-10-12, segunda ronda (antes do merge)

Prevalece sobre as secções 1 a 10:

- **Deduplicação por registo.** `dedupe_key` de uma ação =
  `[user, org, 'action', UsageEventType::record(), subjectKey, dia]` — o tipo de
  registo do catálogo em vez do tipo de evento. `subjectKey` = `Classe#id` para um
  modelo ou a chave textual dada (registo composto ou lote; nunca tem `#`). Os
  tipos que partilham um registo estão fixados em `UsageDedupeTest`; num registo
  partilhado só o tipo de criação pode ser útil ou marco, e fica a primeira linha
  do dia. Ganchos com chave nova: pauta (`turma:período:âmbito`), intercalar
  (`turma:período`), acompanhamento (a revisão criada), INOVAR
  (`classifications:…` / `evaluation-sheet:…`).
- **Ativação explícita.** Fonte única: um período aberto em
  `usage_collection_periods`. `App\Actions\Usage\SetUsageCollection::start|stop`
  (comando `lapis:usage-collection status|start|stop --operator= --note=`) abre e
  fecha no instante, idempotente, com `recordPlatform('admin.usage_collection_started'
  | 'admin.usage_collection_stopped')`. Removidos `usage.enabled`,
  `LAPIS_USAGE_ENABLED`, `UsageCoverage::sync()` e `ensureOpen()`.
- **Escrita condicional.** O callback depois do commit faz
  `insertOrIgnoreUsing(colunas, SELECT ?,… FROM usage_collection_periods WHERE
  is_open AND started_at <= occurred_at AND NOT EXISTS (users bloqueados ou com
  usage_resumed_at > occurred_at) LIMIT 1)`. `UsageRecorder` e
  `PrivacyVersionMemo` são `scoped`.
- **Conservação efetiva.** `usage:prune` de hora a hora (`hourlyAt(7)`): apaga
  eventos com `occurred_at < agora − N dias`, agregados ou não; antes, regista
  em `usage_coverage_gaps(day, reason, recorded_at)` os dias com eventos a
  expirar e `occurred_on >= aggregated_through` (ou todos, sem agregação) e apaga
  os agregados desses dias. `UsageCoverage::periods()` subtrai as lacunas; a
  agregação salta-as e varre agregados que lhes tenham sido escritos.
- **Oposição e encerramento.** `App\Actions\Usage\SetUsageOptOut` (definições:
  `PUT /settings/usage-metrics`, `UsageMetricsController`; e
  `lapis:usage-opt-out`), com a linha da pessoa bloqueada. `users.usage_resumed_at`
  marca a retoma (oposição retirada ou encerramento cancelado);
  `User::usageObservedSince()` = máximo entre a criação e a retoma, e é o início
  de observação que `ObservedDays`, `UsagePeriods::weeks()`, os sinais e as
  coortes usam. `RequestPersonalAccountClosure` chama `ForgetUsageData`; o
  cancelamento grava a retoma. Quem se opôs ou pediu o encerramento sai do
  âmbito (`InsightsScope`).
- **Informação.** `HandleInertiaRequests` partilha `auth.privacy_notice_topic`
  (`general` | `usage_metrics`), e o aviso não bloqueante da ativação traz o aviso
  de oposição; o perfil recebe `usageMetrics` (a secção existe a partir da
  primeira ativação).
