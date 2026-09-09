# Horário inicial com grupos sem vigência artificial Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permitir editar diretamente slots recorrentes com Lessons apenas materializadas e vazias, alinhando o snapshot de grupo sem criar vigência artificial, enquanto preserva qualquer histórico pedagógico real.

**Architecture:** A regra canónica permanece no domínio de `RecurringLessonSlot`: um slot vigente só exige versionamento quando alguma Lesson ligada tem estado `taught`, `LessonSummary` ou `LessonPlan`. O controller usa uma transação para a edição direta e sincroniza apenas `class_group_id` de Lessons vazias; o fluxo `ReviseRecurringLessonSlot` continua a criar versões e a nunca reescrever Lessons históricas. O payload de edição expõe separadamente o estado temporal e a necessidade efetiva de versionamento.

**Tech Stack:** Laravel 13.20, PHP 8.4, Eloquent, PHPUnit 12, Vue 3.5, TypeScript, Inertia, ESLint, vue-tsc, Vitest, Vite.

## Global Constraints

- Não criar migration; não alterar Assessment, Reporting, roster/reimportação, Enrollment, IA, PlanVersions, planos, landing, upload/ativação de horário ou dados da turma.
- English no código/BD; pt-PT na UI; não hardcodar T1/T2.
- Manter tenancy pelo container e as relações/scopes existentes; não usar `withoutGlobalScopes()` nem regras `exists:` tenant-owned.
- Preservar `recurring_lesson_slots.class_group_id` como configuração recorrente e `lessons.class_group_id` como snapshot histórico.
- Uma Lesson vazia é `status=preparation` sem Summary nem Plan; `status=taught`, Summary ou Plan tornam-na historicamente relevante.
- Não reescrever Lessons com histórico; alterações históricas continuam a exigir `effective_from` e nova versão.
- Executar os gates existentes: Pint, Larastan/PHPStan, PHPUnit, ESLint, vue-tsc, Vitest e build.

---

## File Map

- Modify `app/Models/RecurringLessonSlot.php`: tornar explícita a consulta canónica de histórico pedagógico e fazer `requiresVersioning()` depender dela.
- Modify `app/Http/Controllers/LessonScheduleController.php`: transacionar edição direta e alinhar snapshots de Lessons vazias; expor `requires_versioning` nos payloads dos editores.
- Modify `app/Http/Requests/Lessons/RecurringLessonSlotRequest.php`: manter `effective_from` obrigatório apenas para a condição canónica de versionamento e validar o mesmo indicador usado pelo controller.
- Modify `app/Http/Controllers/ClassController.php` and `app/Http/Controllers/TeacherTimetableController.php`: incluir `requires_versioning` nos slots enviados para Vue.
- Modify `resources/js/components/lessons/LessonScheduleEditor.vue`: renderizar/enviar o campo de vigência apenas quando a edição realmente exige versionamento.
- Modify `tests/Feature/Lessons/LessonScheduleTest.php`: cobrir Lessons vazias, histórico relevante, snapshots e grupos.
- Modify `resources/js/components/lessons/LessonScheduleEditor.test.ts` if the existing component-test setup supports this component; otherwise assert the same payload/visibility through the existing timetable/class page tests without adding a new runner.

---

### Task 1: Canonicalizar histórico pedagógico no modelo

**Files:**
- Modify: `app/Models/RecurringLessonSlot.php:71-149`
- Test: `tests/Feature/Lessons/LessonScheduleTest.php` (effective-dating section)

**Interfaces:**
- Produces `RecurringLessonSlot::hasRelevantPedagogicalHistory(): bool`.
- Keeps `RecurringLessonSlot::requiresVersioning(string $timezone): bool` as the controller/request interface.

- [ ] **Step 1: Add failing model-level feature assertions**

First add `ClassGroup` to the test imports and add these test-only fixture
helpers near the existing `slotAttributes()` helper. They make every later
case explicit without hiding the tenant boundary:

```php
private function groupFor(SchoolClass $schoolClass, string $label = 'T1'): ClassGroup
{
    return $this->inTenant($this->organization, fn (): ClassGroup => ClassGroup::factory()
        ->recycle($this->organization)
        ->create(['class_id' => $schoolClass->id, 'label' => $label]));
}

private function lessonForSlot(RecurringLessonSlot $slot, LessonStatus $status = LessonStatus::Preparation): Lesson
{
    return $this->inTenant($this->organization, fn (): Lesson => Lesson::create([
        'class_id' => $slot->class_id,
        'class_group_id' => $slot->class_group_id,
        'recurring_lesson_slot_id' => $slot->id,
        'starts_at' => $this->today().' 09:30:00',
        'ends_at' => $this->today().' 10:20:00',
        'status' => $status,
        'created_by' => $this->teacher->id,
    ]));
}

private function slotWithLesson(
    SchoolClass $schoolClass,
    LessonStatus $status = LessonStatus::Preparation,
    ?int $classGroupId = null,
): RecurringLessonSlot {
    $slot = $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
        $this->slotAttributes($schoolClass, ['class_group_id' => $classGroupId]),
    ));
    $this->lessonForSlot($slot, $status);

    return $slot;
}

private function putSlot(RecurringLessonSlot $slot, SchoolClass $schoolClass, array $overrides = [])
{
    return $this->actingAs($this->teacher)
        ->withSession(['organization_id' => $this->organization->id])
        ->put("/_test/lesson-slots/{$slot->ulid}", $this->slotPayload($schoolClass, $overrides));
}
```

Add tests inside `LessonScheduleTest` that create a slot and a linked Lesson, then assert the behavior through the HTTP update:

```php
public function a_materialized_preparation_lesson_without_records_does_not_require_versioning(): void
{
    $schoolClass = $this->schoolClassFor($this->teacher);
    $slot = $this->slotWithLesson($schoolClass);

    $this->putSlot($slot, $schoolClass, [
            'class_group_id' => $this->groupFor($schoolClass)->id,
        ])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('recurring_lesson_slots', 1);
}

public function a_taught_lesson_requires_versioning(): void
{
    $schoolClass = $this->schoolClassFor($this->teacher);
    $slot = $this->slotWithLesson($schoolClass, LessonStatus::Taught);

    $this->putSlot($slot, $schoolClass, [
            'effective_from' => $this->inDays(1),
        ])
        ->assertRedirect();

    $this->assertDatabaseCount('recurring_lesson_slots', 2);
}
```

Use the file’s existing tenant helpers and factories; do not introduce a generic fixture outside this test class.

- [ ] **Step 2: Run the focused tests and verify the old behavior fails**

Run:

```powershell
php artisan test tests/Feature/Lessons/LessonScheduleTest.php --filter="materialized_preparation|taught_lesson"
```

Expected: the empty preparation case fails because the current `lessons()->exists()` forces `effective_from`.

- [ ] **Step 3: Implement the canonical query**

Add:

```php
public function hasRelevantPedagogicalHistory(): bool
{
    return $this->lessons()
        ->where(function (Builder $query): void {
            $query
                ->where('status', LessonStatus::Taught)
                ->orWhereHas('summary')
                ->orWhereHas('plan');
        })
        ->exists();
}
```

Import `Builder` and `LessonStatus`. Replace the existing `! $this->lessons()->exists()` carve-out in `requiresVersioning()` with `! $this->hasRelevantPedagogicalHistory()`. Keep the existing date semantics: a future slot never versions, and a slot with `starts_on` today and no relevant history edits in place.

- [ ] **Step 4: Run the focused tests**

Run the command from Step 2. Expected: PASS for empty preparation and `taught`; existing effective-dating tests remain green.

- [ ] **Step 5: Commit the domain rule**

```powershell
git add app/Models/RecurringLessonSlot.php tests/Feature/Lessons/LessonScheduleTest.php
git commit -m "fix(schedule): distinguish empty lessons from history"
```

---

### Task 2: Make direct edits transactional and align empty Lesson snapshots

**Files:**
- Modify: `app/Http/Controllers/LessonScheduleController.php:62-91`
- Test: `tests/Feature/Lessons/LessonScheduleTest.php`

**Interfaces:**
- Consumes `RecurringLessonSlot::requiresVersioning()` and the validated slot attributes.
- Produces a direct-update transaction that updates the slot and only empty linked Lessons.

- [ ] **Step 1: Add failing snapshot regression tests**

Add two tests using the existing class-group factory helpers:

```php
public function editing_whole_class_to_group_updates_an_empty_lesson_without_creating_a_version(): void
{
    $schoolClass = $this->schoolClassFor($this->teacher);
    $slot = $this->slotWithLesson($schoolClass, LessonStatus::Preparation, null);
    $group = $this->groupFor($schoolClass);

    $this->putSlot($slot, $schoolClass, ['class_group_id' => $group->id])
        ->assertRedirect()
        ->assertSessionDoesntHaveErrors();

    $this->assertDatabaseCount('recurring_lesson_slots', 1);
    $this->assertDatabaseHas('lessons', [
        'id' => $slot->lessons()->sole()->id,
        'class_group_id' => $group->id,
    ]);
}

public function editing_group_to_group_updates_the_empty_lesson_snapshot(): void
{
    $schoolClass = $this->schoolClassFor($this->teacher);
    $firstGroup = $this->groupFor($schoolClass);
    $secondGroup = $this->groupFor($schoolClass, 'T2');
    $slot = $this->slotWithLesson($schoolClass, LessonStatus::Preparation, $firstGroup->id);

    $this->putSlot($slot, $schoolClass, ['class_group_id' => $secondGroup->id])
        ->assertRedirect();

    $this->assertDatabaseCount('recurring_lesson_slots', 1);
    $this->assertDatabaseHas('lessons', [
        'id' => $slot->lessons()->sole()->id,
        'class_group_id' => $secondGroup->id,
    ]);
}
```

Adapt fixture ordering to the file’s existing `schoolClassFor`, `classGroups`, `slotAttributes`, and tenant helpers; the assertions must verify no second slot and no artificial `starts_on`.

- [ ] **Step 2: Add a preservation test for mixed Lessons**

Create one empty and one relevant Lesson under the same slot, submit a new group, and assert:

```php
$this->assertDatabaseHas('lessons', [
    'id' => $emptyLesson->id,
    'class_group_id' => $newGroup->id,
]);
$this->assertDatabaseHas('lessons', [
    'id' => $taughtLesson->id,
    'class_group_id' => $oldGroup->id,
]);
$this->assertDatabaseCount('recurring_lesson_slots', 2);
```

The request must include `effective_from`, because the relevant Lesson forces versioning; the old slot and both existing Lessons must remain unchanged.

- [ ] **Step 3: Run the focused tests and verify the new assertions fail**

Run:

```powershell
php artisan test tests/Feature/Lessons/LessonScheduleTest.php --filter="empty_lesson|mixed"
```

Expected: direct edits currently leave the existing Lesson snapshot unchanged, and mixed history follows the old all-Lessons rule.

- [ ] **Step 4: Implement the transaction**

In `LessonScheduleController::update()`, retain the existing versioned branch. For the direct branch, wrap the slot update and snapshot alignment in `DB::transaction()`:

```php
if (! $recurringLessonSlot->requiresVersioning($timezone)) {
    return DB::transaction(function () use ($recurringLessonSlot, $request): RedirectResponse {
        $recurringLessonSlot->update($request->safe()->except('class_id', 'effective_from'));

        $recurringLessonSlot->lessons()
            ->where('status', LessonStatus::Preparation)
            ->whereDoesntHave('summary')
            ->whereDoesntHave('plan')
            ->update(['class_group_id' => $recurringLessonSlot->class_group_id]);

        return back();
    });
}
```

Import `LessonStatus`. The direct query must use the tenant-scoped relationship and must not update `starts_at`, status, summaries, plans, or any Lesson with historical content. Keep `class_id` immutable as before.

- [ ] **Step 5: Run all schedule tests**

Run:

```powershell
php artisan test tests/Feature/Lessons/LessonScheduleTest.php
```

Expected: PASS, including previous version boundary, delete, group conflict, and no-group behavior.

- [ ] **Step 6: Commit the transactional update**

```powershell
git add app/Http/Controllers/LessonScheduleController.php tests/Feature/Lessons/LessonScheduleTest.php
git commit -m "fix(schedule): align empty lesson group snapshots"
```

---

### Task 3: Expose the real versioning requirement to the UI

**Files:**
- Modify: `app/Http/Controllers/ClassController.php:170-195`
- Modify: `app/Http/Controllers/TeacherTimetableController.php:89-105`
- Modify: `app/Http/Requests/Lessons/RecurringLessonSlotRequest.php:66-78`
- Modify: `resources/js/components/lessons/LessonScheduleEditor.vue:11-145,345-362`
- Test: relevant existing class/timetable Inertia tests and a focused Vue test if available

**Interfaces:**
- Produces slot payload property `requires_versioning: bool`.
- Vue consumes `requires_versioning` to control the effective-date field and form transform.

- [ ] **Step 1: Add backend payload assertions**

Extend the existing class schedule/timetable response assertions to check:

```php
->where('class.slots.0.already_in_vigor', true)
->where('class.slots.0.requires_versioning', false);
```

Add a second fixture with a `LessonSummary` or `LessonPlan` and assert `requires_versioning` is true. Do not alter the existing `class_group_label` contract.

- [ ] **Step 2: Run the focused backend tests and verify the property is absent**

Run the existing class schedule and timetable selectors, for example:

```powershell
php artisan test tests/Feature/Classes/ClassScheduleSetupTest.php tests/Feature/Lessons/TeacherTimetableTest.php
```

Expected: the new property assertions fail before the payload change.

- [ ] **Step 3: Add `requires_versioning` to both payload builders**

For each slot map, add:

```php
'requires_versioning' => $slot->requiresVersioning($timezone),
```

Keep `already_in_vigor` for delete messaging and temporal display. Do not derive the new property in Vue from dates or from `already_in_vigor`.

- [ ] **Step 4: Update the request/UI contract**

Keep `RecurringLessonSlotRequest::routeSlotRequiresVersioning()` as the server authority, so a stale client cannot bypass validation. In Vue:

```ts
requires_versioning: boolean;
```

Replace the edit default and conditionals:

```ts
const editingSlotRequiresVersioning = computed(
    () =>
        props.slots.find((slot) => slot.ulid === editingUlid.value)
            ?.requires_versioning ?? false,
);

form.effective_from = slot.requires_versioning ? todayIsoDate() : '';
const includeEffectiveFrom =
    editingUlid.value !== null && editingSlotRequiresVersioning.value;
```

Render the effective-date block with `v-if="editingSlotRequiresVersioning"`. Keep the current explanatory pt-PT text for the versioned branch; direct edits render no vigência explanation. The submit transform must omit `effective_from` for direct edits.

- [ ] **Step 5: Run backend and frontend focused tests**

Run:

```powershell
php artisan test tests/Feature/Classes/ClassScheduleSetupTest.php tests/Feature/Lessons/TeacherTimetableTest.php
npm run lint -- --quiet
npx vue-tsc --noEmit
```

Expected: PASS with the field hidden for empty materialized Lessons and visible for relevant history.

- [ ] **Step 6: Commit the UI contract**

```powershell
git add app/Http/Controllers/ClassController.php app/Http/Controllers/TeacherTimetableController.php app/Http/Requests/Lessons/RecurringLessonSlotRequest.php resources/js/components/lessons/LessonScheduleEditor.vue tests
git commit -m "fix(schedule): hide effective date for empty lessons"
```

---

### Task 4: Add complete regression coverage and verify the product scenario

**Files:**
- Modify: `tests/Feature/Lessons/LessonScheduleTest.php`
- Modify: existing frontend tests only where a changed payload contract requires it
- Modify: `CHANGELOG.md` and `config/app.php` only after all gates pass and the next patch version is confirmed free

**Interfaces:**
- Consumes the canonical history rule and UI payload from Tasks 1–3.
- Produces regression evidence for all required cases and the final release metadata.

- [ ] **Step 1: Add remaining required backend cases**

Cover explicitly:

1. whole class → T1 with an empty Lesson for today;
2. T1 → T2 with the same conditions;
3. future slot without materialized Lesson;
4. Lesson with `LessonSummary`;
5. Lesson with `LessonPlan`;
6. Lesson with `status=taught`;
7. past empty Lessons align under the explicit rule;
8. past relevant Lesson keeps its original `class_group_id` after versioning;
9. unsplit class with no groups retains its current behavior;
10. no Assessment/Reporting tables or services are touched.

Use `CarbonImmutable::setTestNow()` already established by the test file; do not make tests depend on the wall clock.

- [ ] **Step 2: Run the full existing test suite**

Run:

```powershell
php artisan test
```

Expected: PASS with no changes to assessment/reporting behavior.

- [ ] **Step 3: Run all project gates**

Run sequentially:

```powershell
composer lint
composer types:check
php artisan test
npm run lint
npx vue-tsc --noEmit
npm run test
npm run build
```

If the repository’s scripts expose equivalent combined commands, also run `composer ci:check`; do not install new tools. Record any environment-only WDAC limitation rather than bypassing it.

- [ ] **Step 4: Perform the manual QA scenario**

With an isolated demo organization and class, create:

- Monday 14:10–15:00, whole class;
- Wednesday 08:30–09:20, whole class;
- Friday 08:30–09:20, T1;
- Friday 09:20–10:10, T2.

Materialize today’s Lessons, edit Friday whole class/T1/T2 assignments, and verify no artificial `effective_from`, `starts_on`, or “Vigência … a fim do ano” appears. Add a Summary to one Lesson, repeat an edit, and verify the effective-date protection returns and the old Lesson snapshot remains unchanged. Check browser console for errors.

- [ ] **Step 5: Verify version reservation before metadata changes**

Run:

```powershell
git fetch origin --prune
git rev-parse origin/main
git branch -a
git status --short
```

Inspect parallel branches and existing release metadata. Only if no other branch has reserved the next patch, update `config/app.php` and `CHANGELOG.md` to the next free version; otherwise leave metadata untouched and report the collision.

- [ ] **Step 6: Commit final tests and metadata**

```powershell
git add tests CHANGELOG.md config/app.php
git commit -m "test(schedule): cover initial grouped timetable setup"
```

- [ ] **Step 7: Final cleanliness check**

Run:

```powershell
git status --short --branch
git log --oneline -5
```

Expected: the isolated branch is clean, `main` is untouched, and no deployment or merge is performed.
