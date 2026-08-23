# Aulas e Sumários Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir o módulo semanal de aulas, sumários, planeamento, sequências, cumprimento, continuidade, TPC e faltas sobre os domínios canónicos do LÁPIS.

**Architecture:** Um agregado normalizado separa ocorrência, plano, sumário, notas, recursos e cumprimento. Sequências são templates que produzem cópias operacionais independentes; TPC, faltas e atrasos continuam em `EvidenceRecord`. Cada slice é vertical, tenant-scoped, recusado durante impersonation e fecha com testes, browser, versão/changelog e `composer ci:check` executado pelo utilizador.

**Tech Stack:** Laravel 13.20, PHP 8.4, MySQL 9.7, PHPUnit 12, Vue 3.5, TypeScript, Inertia 3, Tailwind 4.1.

## Global Constraints

- Ler `CLAUDE.md`, `AGENTS.md`, `docs/workflow.md`, `docs/status.md` e a spec `docs/superpowers/specs/2026-08-23-lessons-summaries-planning-sequences-design.md` antes de implementar.
- Tenancy vive em `CurrentOrganization`; todos os novos modelos tenant-owned usam `BelongsToOrganization`.
- Nunca usar `exists:` em referências tenant-owned; usar `BelongsToCurrentOrganization` e guards de compatibilidade de turma.
- Form Requests validam, Policies autorizam e Actions/Services implementam casos de uso.
- Código/BD em inglês, UI pt-PT; ULID em URLs; FKs históricas nunca usam cascade destrutivo.
- Todas as mutações deste módulo usam `RefusesDuringImpersonation`; leitura autorizada continua disponível.
- Agenda continua placeholder: não criar `calendar_events`, feriados, interrupções, visitas ou bloqueios.
- `lesson_sequences.academic_period_id` é nullable e `nullOnDelete()`; não reescrever `AcademicYearService` nesta tranche.
- Se `nullOnDelete()` não proteger suficientemente o histórico durante implementação, parar e pedir decisão antes de expandir o âmbito.
- Não criar `LessonHomework`, tabela de presenças, linhas “Presente”, calendário mensal, autosave, IA ou gestor documental.
- Recursos da primeira versão são URL/referência textual, sem upload.
- Notas privadas não entram em sumários, relatórios ou exports pedagógicos e nunca são copiadas por defeito.
- A confirmação de qualquer preview revalida dados sob transação/locks; preview antigo não autoriza escrita.
- Nesta sandbox não correr PHP, Composer ou Artisan. O utilizador executa esses gates separadamente e comunica os resultados.
- Cada slice segue migration → model/enum → policy → Action/Service → controller/request/routes → Vue → testes → gates/browser → versão/changelog → commit.

---

## Mapa de ficheiros e responsabilidades

### Domínio base

- `app/Models/RecurringLessonSlot.php` — horário recorrente de uma turma.
- `app/Models/Lesson.php` — ocorrência concreta e estado operacional.
- `app/Models/LessonStatus.php` — `preparation`, `prepared`, `taught`.
- `app/Models/LessonPlan.php` — preparação e proveniência operacional.
- `app/Models/LessonSummary.php` — texto oficial e metadata de revisão.
- `app/Models/LessonPrivateNote.php` — nota por autor, fora do sumário oficial.
- `app/Models/LessonResource.php` — URL/referência ligada à aula.
- `app/Models/LessonFulfillment.php` — cumprimento e conteúdo restante.
- `app/Models/LessonFulfillmentStatus.php` — `fulfilled`, `partially_fulfilled`, `not_taught`.
- `app/Models/LessonSequence.php`, `LessonSequenceItem.php`, `LessonSequenceItemResource.php` — templates ordenados.

### Policies e requests

- `app/Policies/LessonPolicy.php`, `RecurringLessonSlotPolicy.php`, `LessonSequencePolicy.php` — autorização por turma/dono.
- `app/Http/Requests/Lessons/*` — validação por caso de uso, incluindo referências tenant-owned e compatibilidade.

### Actions/read models

- `app/Actions/Lessons/MaterializeLessonsForRange.php` — cria ocorrências idempotentes a partir de slots, nunca num GET.
- `app/Actions/Lessons/SaveLessonDetails.php` — guarda plano, sumário, nota e recursos de forma transacional.
- `app/Services/Lessons/WeeklyLessonsQuery.php` — read model agregado limitado a uma semana.
- `app/Actions/Lessons/PreviewSequenceApplication.php` e `ApplyLessonSequence.php` — preview e cópia independente.
- `app/Actions/Lessons/PreviewLessonContinuity.php` — impacto canónico partilhado pelos três modos.
- `MergeRemainingContentIntoNextLesson.php`, `InsertRemainingContentAndShiftPlannedLessons.php`, `CreateLessonFromRemainingContent.php` — confirmações transacionais.
- `app/Actions/Lessons/SaveLessonAttendance.php` — upsert/restore canónico em `EvidenceRecord`.

### HTTP e Vue

- `app/Http/Controllers/LessonController.php`, `LessonSequenceController.php`, `LessonContinuityController.php`, `LessonAttendanceController.php`.
- `resources/js/pages/lessons/Index.vue`, `Show.vue`, `sequences/Index.vue`, `Edit.vue`, `Apply.vue`.
- Componentes pequenos em `resources/js/components/lessons/` para semana, editor opcional, preview, continuidade e faltas.

---

# Slice 1 — Ocorrência concreta e sumário simples

## Entrega

Substitui o placeholder por uma primeira fatia real: configurar horário, criar/materializar ocorrências, abrir uma aula, guardar um sumário simples, voltar e editar. Não inclui ainda planeamento, notas, recursos, TPC ou sequências.

## Dependências

Só depende de `SchoolClass`, `AcademicYear`, `AcademicPeriod`, `SchoolClassPolicy`, tenancy e audit trail existentes. Produz `RecurringLessonSlot`, `Lesson`, `LessonSummary` e `LessonPolicy`, usados por todos os slices seguintes.

### Task 1.1: Persistir horário e ocorrência

**Files:**
- Create: `database/migrations/2026_08_28_000100_create_recurring_lesson_slots_table.php`
- Create: `database/migrations/2026_08_28_000200_create_lessons_table.php`
- Create: `database/migrations/2026_08_28_000300_create_lesson_summaries_table.php`
- Create: `app/Models/RecurringLessonSlot.php`
- Create: `app/Models/Lesson.php`
- Create: `app/Models/LessonStatus.php`
- Create: `app/Models/LessonSummary.php`
- Modify: `app/Models/SchoolClass.php`
- Test: `tests/Feature/Lessons/LessonModelTest.php`

**Interfaces:**
- Produces: `SchoolClass::recurringLessonSlots(): HasMany`, `SchoolClass::lessons(): HasMany`.
- Produces: `Lesson::summary(): HasOne`, `LessonStatus::{Preparation,Prepared,Taught}`.
- Constraint: ordinary occurrences are unique by `class_id`, `recurring_lesson_slot_id`, `starts_at`; extraordinary lessons allow a null slot.

- [ ] Write model/migration tests proving ULIDs, casts, relationships, tenant stamping/scope, reversible FK behavior and uniqueness.
- [ ] Ask the user to run `php artisan test tests/Feature/Lessons/LessonModelTest.php`; confirm the expected initial failure is missing tables/classes.
- [ ] Add the three reversible migrations with `organization_id`, restrictive FKs, CHECK constraints guarded for MySQL/MariaDB and weekly indexes.
- [ ] Implement the four models with `#[Fillable]`, `HasUlids`, `BelongsToOrganization`, route keys and typed relationships.
- [ ] Ask the user to rerun the focused test and record the passing result.

### Task 1.2: Autorizar e materializar ocorrências

**Files:**
- Create: `app/Policies/RecurringLessonSlotPolicy.php`
- Create: `app/Policies/LessonPolicy.php`
- Create: `app/Actions/Lessons/MaterializeLessonsForRange.php`
- Create: `app/Http/Requests/Lessons/RecurringLessonSlotRequest.php`
- Create: `app/Http/Controllers/LessonScheduleController.php`
- Test: `tests/Feature/Lessons/LessonScheduleTest.php`

**Interfaces:**
- Consumes: `SchoolClass::recurringLessonSlots()`, `SchoolClass::lessons()`.
- Produces: `MaterializeLessonsForRange::execute(SchoolClass $class, CarbonImmutable $from, CarbonImmutable $to, User $actor): Collection`.
- Rule: the Action refuses dates outside the class academic year and is idempotent under concurrent calls.

- [ ] Write failing tests for authorized/unauthorized teacher, other tenant, invalid times, range outside the year, idempotency and no writes on GET.
- [ ] Ask the user to run the focused test and confirm failures are due to missing implementation.
- [ ] Implement policies by delegating class access to the existing teacher relationship.
- [ ] Implement the Form Request with `ends_at > starts_at`, weekday range and tenant/class guards.
- [ ] Implement materialization in a transaction, locking the class before resolving missing occurrences and using explicit Lisbon dates/times.
- [ ] Add controller endpoints for slot mutation and an explicit POST materialization endpoint; apply `module:lessons` and `RefusesDuringImpersonation` to mutations.
- [ ] Rerun the focused test through the user.

### Task 1.3: Guardar e rever o sumário oficial

**Files:**
- Create: `app/Http/Requests/Lessons/LessonSummaryRequest.php`
- Create: `app/Actions/Lessons/SaveLessonSummary.php`
- Create: `app/Http/Controllers/LessonController.php`
- Create: `resources/js/pages/lessons/Show.vue`
- Modify: `routes/web.php`
- Modify: `config/navigation.php`
- Test: `tests/Feature/Lessons/LessonSummaryTest.php`

**Interfaces:**
- Produces: `SaveLessonSummary::execute(Lesson $lesson, string $content, User $actor): LessonSummary`.
- Rule: first save does not imply taught; editing after taught sets `reviewed_at/by` and records an audit event without copying content into audit properties.

- [ ] Write failing tests for create/edit/reopen, empty validation, teacher authorization, cross-tenant binding, impersonation refusal and post-taught review metadata/audit.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement the request, transactional Action and controller show/update methods.
- [ ] Replace only the `lessons` placeholder with real gated routes; keep `calendar` untouched.
- [ ] Build the minimal mobile-first page with the Sumário field always visible, loading/error state and large Guardar action.
- [ ] Ask the user to run focused PHP tests plus `npm run lint`, `npm run types:check` and `npm run build`.
- [ ] Verify in browser: create occurrence → open → save → leave → reopen → edit; confirm no console errors.
- [ ] Update `docs/status.md`, `CHANGELOG.md` and `config/app.php` with the approved minor/patch for this slice, then commit only after user-run `composer ci:check` is green.

---

# Slice 2 — Planeamento antecipado e centro semanal

## Entrega

Acrescenta planeamento futuro, notas privadas, recursos URL/referência, estado Por preparar/Preparado/Lecionado e a vista semanal operacional. O professor pode preparar uma aula ou semana, sair e continuar mais tarde.

## Dependências

Depende das ocorrências, sumários e policies do Slice 1. Produz `LessonPlan`, notas, recursos e o read model semanal necessários a sequências, cumprimento e indicadores posteriores.

### Task 2.1: Persistir plano, notas privadas e recursos

**Files:**
- Create: `database/migrations/2026_08_28_000400_create_lesson_plans_table.php`
- Create: `database/migrations/2026_08_28_000500_create_lesson_private_notes_table.php`
- Create: `database/migrations/2026_08_28_000600_create_lesson_resources_table.php`
- Create: `app/Models/LessonPlan.php`
- Create: `app/Models/LessonPrivateNote.php`
- Create: `app/Models/LessonResource.php`
- Modify: `app/Models/Lesson.php`
- Test: `tests/Feature/Lessons/LessonPlanningModelTest.php`

**Interfaces:**
- Produces: `Lesson::plan(): HasOne`, `privateNotes(): HasMany`, `resources(): HasMany`.
- Rule: one private note per lesson/author; resources are URL or non-empty textual reference, never uploaded files.

- [ ] Write failing tests for relationships, unique plan, note authorship, URL/reference validation shape, tenancy and restrictive deletion.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement the three reversible migrations and models.
- [ ] Add typed relationships to `Lesson` and safe indexes for weekly eager loading.
- [ ] Ask the user to rerun the focused test.

### Task 2.2: Guardar detalhes opcionais e estado

**Files:**
- Create: `app/Data/Lessons/LessonDetailsData.php`
- Create: `app/Http/Requests/Lessons/LessonDetailsRequest.php`
- Create: `app/Actions/Lessons/SaveLessonDetails.php`
- Modify: `app/Http/Controllers/LessonController.php`
- Modify: `resources/js/pages/lessons/Show.vue`
- Create: `resources/js/components/lessons/OptionalLessonSection.vue`
- Create: `resources/js/components/lessons/LessonResourcesEditor.vue`
- Test: `tests/Feature/Lessons/LessonPlanningTest.php`

**Interfaces:**
- Produces: `SaveLessonDetails::execute(Lesson $lesson, LessonDetailsData $data, User $actor): Lesson`.
- `LessonDetailsData` contains `plannedSummary`, optional `summary`, author-owned private note, resource list and target `LessonStatus`.
- Rule: `prepared` requires a non-empty plan or summary; `taught` never copies planned text into the official summary.

- [ ] Write failing tests for future planning, optional note/resource, author-only note access, status transitions, no planned→actual assumption, audit and impersonation refusal.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement request DTO mapping and a transaction that saves each separate model without mass-overwriting absent optional sections.
- [ ] Emit intent-level audit events for prepare, teach and post-taught edit; exclude note/summary text from audit properties.
- [ ] Add collapsed Notas/Recursos sections, touch-friendly controls and explicit state action to `Show.vue`.
- [ ] Ask the user to run the focused tests and frontend gates.

### Task 2.3: Construir o read model e a vista semanal

**Files:**
- Create: `app/Services/Lessons/WeeklyLessonsQuery.php`
- Create: `app/Http/Requests/Lessons/WeeklyLessonsRequest.php`
- Modify: `app/Http/Controllers/LessonController.php`
- Create: `resources/js/pages/lessons/Index.vue`
- Create: `resources/js/components/lessons/LessonWeekNavigator.vue`
- Create: `resources/js/components/lessons/LessonWeekRow.vue`
- Modify: `resources/js/components/ContextBar.vue`
- Test: `tests/Feature/Lessons/WeeklyLessonsTest.php`

**Interfaces:**
- Produces: `WeeklyLessonsQuery::for(User $actor, AcademicYear $year, CarbonImmutable $weekStart, ?Subject $subject): array`.
- Output per row: ULID, start/end, class label, subject, lesson status, summary excerpt and boolean/count placeholders for optional indicators.

- [ ] Write failing tests proving Monday–Sunday bounds, teacher-only classes, tenant isolation, academic-year/subject filtering, previous/current/next week and bounded query count.
- [ ] Ask the user to run the focused test and capture the query-count failure baseline.
- [ ] Implement one bounded query with eager loading and aggregate counts; never load roster or annual history.
- [ ] Implement index route/controller props and activate only the existing academic-year/subject context needed by this module; do not invent a parallel store.
- [ ] Build responsive today/previous/current/next navigation and compact rows without monthly calendar or hover-only actions.
- [ ] Ask the user to run focused tests, frontend gates and browser checks at desktop/tablet/mobile widths.
- [ ] Close the slice with docs/version/changelog and user-run `composer ci:check`, then commit.

---

# Slice 3 — Cumprimento e TPC canónico

## Entrega

Permite marcar Cumprido/Parcialmente cumprido/Não lecionado, guardar conteúdo restante e associar TPC à aula usando `EvidenceRecord`. A vista semanal ganha indicadores de cumprimento/TPC.

## Dependências

Depende de plano, sumário e semana dos Slices 1–2. Produz `LessonFulfillment` e a ligação `EvidenceRecord.lesson_id`, ambos necessários a continuidade e aplicação de sequências.

### Task 3.1: Persistir cumprimento separado

**Files:**
- Create: `database/migrations/2026_08_28_000700_create_lesson_fulfillments_table.php`
- Create: `app/Models/LessonFulfillment.php`
- Create: `app/Models/LessonFulfillmentStatus.php`
- Modify: `app/Models/Lesson.php`
- Create: `app/Http/Requests/Lessons/LessonFulfillmentRequest.php`
- Create: `app/Actions/Lessons/RecordLessonFulfillment.php`
- Test: `tests/Feature/Lessons/LessonFulfillmentTest.php`

**Interfaces:**
- Produces: `Lesson::fulfillment(): HasOne`.
- Produces: `RecordLessonFulfillment::execute(Lesson $lesson, LessonFulfillmentStatus $status, ?string $remainingContent, User $actor): LessonFulfillment`.
- Rule: partial requires remaining content; fulfilled clears remaining content; not taught may retain all planned content.

- [ ] Write failing tests for all three states, validation, separation from `LessonStatus`, corrections, audit, tenant/policy and impersonation.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement migration/model/enum, request and transactional Action.
- [ ] Add the fast fulfillment control to `Show.vue` without adding a questionnaire.
- [ ] Ask the user to rerun focused and frontend gates.

### Task 3.2: Ligar TPC atribuído ao EvidenceRecord canónico

**Files:**
- Create: `database/migrations/2026_08_28_001000_link_evidence_records_to_lessons_and_add_attendance_kinds.php`
- Modify: `app/Models/EvidenceRecord.php`
- Modify: `app/Models/EvidenceKind.php`
- Modify: `app/Http/Controllers/EvidenceController.php`
- Create: `app/Http/Requests/Lessons/LessonHomeworkRequest.php`
- Create: `app/Actions/Lessons/SaveLessonHomework.php`
- Modify: `resources/js/pages/lessons/Show.vue`
- Modify: `resources/js/pages/records/HomeworkGrid.vue`
- Test: `tests/Feature/Lessons/LessonHomeworkTest.php`
- Test: `tests/Feature/Evidence/EvidenceRecordTest.php`

**Interfaces:**
- Produces: `EvidenceRecord::lesson(): BelongsTo` and `Lesson::evidenceRecords(): HasMany`.
- Produces: `SaveLessonHomework::execute(Lesson $lesson, ?string $description, User $actor): ?EvidenceRecord`.
- Assignment identity: lesson + `Homework` + null enrollment; status must be null.
- Individual checks keep current `HomeworkStatus` semantics and may reference the lesson when launched from it.

- [ ] Write failing tests for class assignment with null status, edit/remove, appearance in Registos, existing individual grid compatibility, no default status, no duplicates, tenant/class guards and impersonation.
- [ ] Ask the user to run both focused suites and confirm failure.
- [ ] Add nullable restrictive `lesson_id`, update the MySQL CHECK for `absence`/`late`, and add the nullable-safe indexes/attendance uniqueness strategy documented in the spec.
- [ ] Extend relationships and validation so only class-level assignment may have Homework with null status; preserve the existing requirement for individual checks.
- [ ] Implement canonical save/delete Action under lesson lock and reuse it from the lesson endpoint.
- [ ] Add collapsed TPC editor and update weekly TPC indicator through aggregate query.
- [ ] Rerun both suites and frontend gates through the user; verify the same row appears in Registos and Aulas.
- [ ] Close slice with docs/version/changelog, full CI result and commit.

---

# Slice 4 — Sequências e cópias independentes

## Entrega

Criação, edição, duplicação, arquivo e aplicação de sequências a várias turmas compatíveis. Cada turma recebe planos, recursos e TPC independentes com proveniência apenas informativa.

## Dependências

Depende de horários/ocorrências, planos, recursos e TPC dos Slices 1–3. Produz proveniência consumida por “Usar noutra turma” e continuidade, mas não é necessária para aulas simples.

### Task 4.1: Persistir sequências e proveniência

**Files:**
- Create: `database/migrations/2026_08_28_000800_create_lesson_sequences_tables.php`
- Create: `database/migrations/2026_08_28_000900_add_provenance_to_lesson_plans.php`
- Create: `app/Models/LessonSequence.php`
- Create: `app/Models/LessonSequenceItem.php`
- Create: `app/Models/LessonSequenceItemResource.php`
- Modify: `app/Models/LessonPlan.php`
- Create: `app/Policies/LessonSequencePolicy.php`
- Test: `tests/Feature/Lessons/LessonSequenceModelTest.php`

**Interfaces:**
- Produces ordered `LessonSequence::items()` and item `resources()`.
- Produces nullable `LessonPlan` source relationships; no source relationship participates in content reads.
- Period FK is `nullOnDelete()` and sequence deletion/archive never cascades into destination plans.

- [ ] Write failing tests for tenant scope, owner policy, item ordering, period null-on-delete, archive, restrictive dependencies and inert provenance.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement the sequence migration plus the distinct provenance migration, models and policy.
- [ ] Add explicit tests proving source edits do not change copied plans.
- [ ] Ask the user to rerun the focused test.

### Task 4.2: CRUD leve de sequências

**Files:**
- Create: `app/Http/Requests/Lessons/LessonSequenceRequest.php`
- Create: `app/Actions/Lessons/SaveLessonSequence.php`
- Create: `app/Actions/Lessons/DuplicateLessonSequence.php`
- Create: `app/Http/Controllers/LessonSequenceController.php`
- Create: `resources/js/pages/lessons/sequences/Index.vue`
- Create: `resources/js/pages/lessons/sequences/Edit.vue`
- Modify: `routes/web.php`
- Test: `tests/Feature/Lessons/LessonSequenceTest.php`

**Interfaces:**
- `SaveLessonSequence` receives name, subject, grade level, nullable period and ordered item DTOs.
- Duplicate creates new sequence/items/resources; archive sets `archived_at` and preserves history.

- [ ] Write failing CRUD/duplicate/archive tests, including incompatible period/year, other tenant/owner and impersonation.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement requests and Actions using diff-based item updates, never delete-all if applied provenance/history would be obscured.
- [ ] Implement controller/routes under `module:lessons` and lightweight responsive pages.
- [ ] Ask the user to run PHP/frontend gates and browser CRUD flow.

### Task 4.3: Preview e aplicar a várias turmas

**Files:**
- Create: `app/Data/Lessons/LessonCopyOptions.php`
- Create: `app/Actions/Lessons/PreviewSequenceApplication.php`
- Create: `app/Actions/Lessons/ApplyLessonSequence.php`
- Create: `app/Http/Requests/Lessons/ApplyLessonSequenceRequest.php`
- Create: `app/Http/Controllers/LessonSequenceApplicationController.php`
- Create: `resources/js/pages/lessons/sequences/Apply.vue`
- Create: `resources/js/components/lessons/CopyOptions.vue`
- Test: `tests/Feature/Lessons/ApplyLessonSequenceTest.php`

**Interfaces:**
- Preview returns selected classes, mapped lesson ULIDs, source item ids, selected copy flags and blockers.
- Confirm receives stable source/destination ULIDs plus copy flags, then recomputes compatibility/mapping under locks.
- Copy flags: `summary`, `resources`, `homework`, `private_notes`; private notes default false.

- [ ] Write failing tests for 2–3 compatible classes with different schedules, insufficient occurrences blocking all writes, incompatible subject/grade, unauthorized class, cross-tenant source and stale preview.
- [ ] Add independence tests: editing sequence/7.º A never changes 7.º B/C; resources and TPC are distinct rows.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement pure preview, then transactional confirm locking sequence, classes and destination lessons in stable id order.
- [ ] Copy operational rows and store only source ids; never retain shared mutable content.
- [ ] Build preview UI with class-by-class mapping and private notes unchecked.
- [ ] Implement “Usar noutra turma” and “Basear no sumário anterior” through the same copy-options DTO and independent-copy service.
- [ ] Ask the user to run focused/frontend/browser checks.
- [ ] Close slice with docs/version/changelog, full CI and commit.

---

# Slice 5 — Continuidade e conteúdo pendente

## Entrega

Implementa as três decisões explícitas para conteúdo parcial/não lecionado, sempre com preview, confirmação, locks e proteção de histórico.

## Dependências

Depende de plano, cumprimento, semana e proveniência dos Slices 2–4. Não altera schema. Deve ser implementado depois de cópias independentes para deslocar apenas planos operacionais, nunca templates.

### Task 5.1: Resolver previews canónicos

**Files:**
- Create: `app/Data/Lessons/LessonContinuityMode.php`
- Create: `app/Data/Lessons/LessonContinuityPreview.php`
- Create: `app/Actions/Lessons/PreviewLessonContinuity.php`
- Create: `app/Http/Requests/Lessons/LessonContinuityRequest.php`
- Test: `tests/Feature/Lessons/LessonContinuityPreviewTest.php`

**Interfaces:**
- Modes: `merge_next`, `shift_sequence`, `remaining_only`.
- `PreviewLessonContinuity::execute(Lesson $source, LessonContinuityMode $mode, User $actor): LessonContinuityPreview`.
- Preview names target lesson, editable combined text, shifted lesson ULIDs/count and blockers.

- [ ] Write failing tests for each preview, no next lesson, no pending content, taught/reviewed target, foreign class/tenant, unauthorized actor and exact affected count.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement immutable mode/preview data objects and a read-only resolver limited to the source class.
- [ ] Ensure the resolver never writes, never crosses the academic year and never uses sequence templates as destinations.
- [ ] Ask the user to rerun the focused test.

### Task 5.2: Confirmar as três Actions transacionais

**Files:**
- Create: `app/Actions/Lessons/MergeRemainingContentIntoNextLesson.php`
- Create: `app/Actions/Lessons/InsertRemainingContentAndShiftPlannedLessons.php`
- Create: `app/Actions/Lessons/CreateLessonFromRemainingContent.php`
- Create: `app/Http/Controllers/LessonContinuityController.php`
- Create: `resources/js/components/lessons/LessonContinuityDialog.vue`
- Modify: `resources/js/pages/lessons/Show.vue`
- Test: `tests/Feature/Lessons/LessonContinuityTest.php`

**Interfaces:**
- Each Action receives source lesson, confirmed preview payload and actor; it recomputes impact under locks and returns affected lessons.
- Merge writes only the confirmed editable combined text.
- Shift inserts one operational lesson/plan and moves only non-taught, non-reviewed future occurrences.
- Remaining-only creates one next operational lesson/plan and leaves subsequent lessons unchanged.

- [ ] Write failing success tests for merge, shift and remaining-only, including editable preview text and audit metadata.
- [ ] Write rollback/concurrency tests proving stale preview, taught/reviewed conflict, out-of-context target and forced mid-operation failure leave every plan/date unchanged.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Implement Actions with stable lock order and shared revalidation from `PreviewLessonContinuity`.
- [ ] Implement controller routes with policy/module/impersonation guards.
- [ ] Build the three-choice dialog, affected-count warning and explicit confirmation without automatic default.
- [ ] Update weekly pending indicator after successful confirmation.
- [ ] Ask the user to run focused/frontend/browser checks for all three modes.
- [ ] Close slice with docs/version/changelog, full CI and commit.

---

# Slice 6 — Faltas e atrasos como entrada alternativa de Registos

## Entrega

Acrescenta chamada compacta dentro da aula, persiste apenas Falta/Atraso em `EvidenceRecord`, mostra os mesmos dados em Registos e agrega contadores na semana.

## Dependências

Depende de `EvidenceRecord.lesson_id` e dos novos `EvidenceKind` já introduzidos no Slice 3, além de ocorrências e autorização do Slice 1. Fica depois de continuidade para reduzir o número de mutações concorrentes introduzidas antes de estabilizar o agregado.

### Task 6.1: Guardar eventos canónicos sem duplicados

**Files:**
- Create: `app/Http/Requests/Lessons/LessonAttendanceRequest.php`
- Create: `app/Actions/Lessons/SaveLessonAttendance.php`
- Create: `app/Http/Controllers/LessonAttendanceController.php`
- Modify: `app/Http/Controllers/EvidenceController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Lessons/LessonAttendanceTest.php`
- Test: `tests/Feature/Evidence/EvidenceRecordTest.php`

**Interfaces:**
- Request rows contain `enrollment_id`, `absence: bool`, `late: bool`; ausência e atraso são mutuamente exclusivos na mesma aula. Corrigir ausência para atraso atualiza os dois eventos canónicos na mesma transação.
- `SaveLessonAttendance::execute(Lesson $lesson, array $rows, User $actor): Collection` stores/restores/deletes canonical EvidenceRecords atomically.
- Identity: `lesson_id + enrollment_id + kind`; no presence row exists.

- [ ] Write failing tests for one/many absences, removal, edit without duplicate, late, no artificial presence, correct lesson/date/class/actor, inactive/foreign enrollment and cross-tenant rejection.
- [ ] Add tests proving records created here appear in Registos and edits/deletes there affect the lesson indicator.
- [ ] Add concurrency/soft-delete restoration tests for the unique identity.
- [ ] Ask the user to run both focused suites and confirm failure.
- [ ] Implement request and transactional Action under lesson lock, reusing/extracting the existing enrollment/class guard.
- [ ] Implement controller route with class-derived policy, module and impersonation refusal.
- [ ] Extend Registos labels/filters for Absence/Late without creating a second query path.
- [ ] Ask the user to rerun focused suites.

### Task 6.2: Entrada rápida e indicadores semanais

**Files:**
- Create: `resources/js/components/lessons/LessonAttendanceEditor.vue`
- Modify: `resources/js/pages/lessons/Show.vue`
- Modify: `resources/js/components/lessons/LessonWeekRow.vue`
- Modify: `app/Services/Lessons/WeeklyLessonsQuery.php`
- Test: `tests/Feature/Lessons/WeeklyLessonsTest.php`

**Interfaces:**
- Editor loads the active roster only when opened and submits relevant events.
- Weekly row receives `absence_count` and `late_count`; it never receives the roster.

- [ ] Write failing weekly-query tests for aggregate counts and bounded query count.
- [ ] Ask the user to run the focused test and confirm failure.
- [ ] Add aggregate subqueries/grouped counts to `WeeklyLessonsQuery` without per-row queries.
- [ ] Build “Todos presentes / Sem faltas registadas — Marcar faltas”, lazy roster, touch checkboxes and final count; never render a Presente/Falta matrix.
- [ ] Verify removing an event updates both lesson and Registos views after reload.
- [ ] Ask the user to run PHP/frontend gates and browser checks on desktop/tablet/mobile.

### Task 6.3: Proteção de dados, exportações e fecho do módulo

**Files:**
- Modify: `app/Actions/DataExports/GenerateDataExport.php` to include only the requesting author's own private notes in their personal data export.
- Modify: reporting/export services only to add explicit exclusions or tests; never expose private notes as official summary.
- Create: `tests/Feature/Lessons/LessonPrivacyTest.php`
- Create: `tests/Feature/Lessons/LessonImpersonationTest.php`
- Modify: `docs/status.md`
- Modify: `CHANGELOG.md`
- Modify: `config/app.php`

**Interfaces:**
- Official report/export readers consume `LessonSummary` only.
- Personal data export includes only notes authored by the requester; no institutional or pedagogical report/export receives them.

- [ ] Write tests that scan every relevant report/PDF/DOCX/pedagogical export source and prove private notes cannot appear as summary.
- [ ] Write one matrix test covering impersonation refusal for slot, lesson, plan, summary, note, resource, fulfillment, sequence, continuity, TPC and attendance mutations.
- [ ] Write final cross-tenant/authorization matrix for class, enrollment, lesson, sequence, subject and period.
- [ ] Ask the user to run the full Lessons/Evidence/Reports/DataExports suites and all frontend gates.
- [ ] Perform browser regression: week → lesson → plan → summary → fulfillment → sequence apply → continuity → attendance → Registos.
- [ ] Ask the user to run `composer ci:check`; do not claim completion without its exact green output.
- [ ] Update status, changelog and version with the approved structural minor, then run release checks through the user.
- [ ] Request adversarial code review, fix all relevant findings, rerun affected gates and commit the final slice.

---

## Dependências entre slices

```text
Slice 1: Horário + Aula + Sumário
    ↓
Slice 2: Planeamento + Notas/Recursos + Semana
    ↓
Slice 3: Cumprimento + EvidenceRecord.lesson_id + TPC
    ↓
Slice 4: Sequências + Proveniência + Cópias independentes
    ↓
Slice 5: Continuidade transacional
    ↓
Slice 6: Faltas/Atrasos + Privacidade + Fecho
```

O Slice 6 depende tecnicamente do schema de Evidence do Slice 3, mas é mantido no
fim para estabilizar primeiro as operações de plano/sequência/continuidade. O
Slice 5 depende do Slice 4 porque “adiar a sequência” desloca cópias operacionais,
nunca itens do template. Nenhum slice depende da Agenda real.

## Verificação final de escopo

- As dez migrations aprovadas aparecem exatamente uma vez nos Slices 1–4.
- Aula, Sumário, Planeamento, Sequência e Cumprimento têm modelos e testes separados.
- TPC/Falta/Atraso reutilizam `EvidenceRecord`; não há domínio paralelo.
- As três Actions de continuidade têm preview, confirmação e rollback testado.
- Cópias independentes e notas privadas desmarcadas têm testes explícitos.
- A dívida do `AcademicYearService` permanece documentada e fora do código desta tranche.
- Agenda, uploads, IA, presença positiva, autosave e calendário mensal permanecem fora.
