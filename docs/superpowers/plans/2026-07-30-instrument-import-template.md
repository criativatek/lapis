# Importar Estrutura de Outro Instrumento Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher, while creating a new instrument, import an existing instrument's question structure (from their own classes, same subject only) as a starting point — never student scores.

**Architecture:** `InstrumentController::create()` gains one new read-only prop listing the teacher's own same-subject instruments with their items already embedded. The create form gets a selector that, on choosing one, copies its title/total_points/allow_bonus/items into the form's local reactive state — a client-only operation, no new route or write.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 + TypeScript, PHPUnit 12.

## Global Constraints

- **Sequencing — read this before starting.** This plan touches the instrument creation form. As of this plan's writing, that form is `resources/js/pages/instruments/Create.vue`, a single self-contained file — but a separate, already-in-progress plan (`docs/superpowers/plans/2026-07-29-instrument-editing.md`) extracts its logic into a shared `resources/js/pages/instruments/InstrumentForm.vue` (used by both a thinner `Create.vue` and a new `Edit.vue`). Before starting Task 2, run `git log --oneline` and check whether `InstrumentForm.vue` exists (`ls resources/js/pages/instruments/`). If it now exists, the exact line numbers and even the file this task modifies will differ from what's written below — apply the same change (add the import selector, add the copy-on-select logic) to wherever the form's reactive `items`/`title`/`total_points`/`allow_bonus` state actually lives now (most likely `InstrumentForm.vue`), not blindly to `Create.vue` if it has become a thin wrapper. Read the actual current file before editing either way.
- Importable instruments are scoped to: instruments in classes the current teacher teaches, AND whose class has the same `subject_id` as the class the new instrument is being created for. Never a colleague's instrument, never a different subject.
- Copied: `title` (as an editable starting value, never locked), `total_points`, `allow_bonus`, and every item's `code`/`label`/`points_possible`/`is_bonus`/domain allocations (`domain_id`/`allocation_percent`, copied as-is — same-subject scoping already guarantees the domains are valid for the destination class).
- Never copied: student scores (there are none to copy from — only instrument/item structure, never `student_item_scores`), `academic_period_id`, `instrument_type_id`, `applied_on`, `counts_toward_classification`, `purpose`, `weight` — all of these keep asking fresh, exactly as the form already does today.
- No new route, no new database write — this is a read-only prop added to an existing page, and a client-side-only copy operation.
- PT-pt copy throughout the UI.

---

### Task 1: Backend — send the teacher's own same-subject instruments as import candidates

**Files:**
- Modify: `app/Http/Controllers/InstrumentController.php:51-59` (the `create()` method)
- Test: `tests/Feature/Assessment/InstrumentImportTemplateTest.php` (new)

**Interfaces:**
- Produces: an Inertia prop `importableInstruments: list<array{ulid: string, title: string, class_label: string, applied_on: string, total_points: float|null, allow_bonus: bool, items: list<array{code: string, label: string|null, points_possible: float, is_bonus: bool, domains: list<array{domain_id: int, allocation_percent: float}>}>}>` on the `instruments/Create` page. Task 2 consumes this exact shape.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/InstrumentImportTemplateTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentImportTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    protected function makeClass(Subject $subject): SchoolClass
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        return $class;
    }

    #[Test]
    public function the_create_page_lists_the_teachers_own_instruments_of_the_same_subject(): void
    {
        $this->inTenant(function (): void {
            $subject = Subject::factory()->recycle($this->organization)->create();
            $domain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $subject->id]);
            $sourceClass = $this->makeClass($subject);
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($sourceClass->academicYear)->create();

            $source = app(InstrumentBuilder::class)->create($sourceClass, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => \App\Models\InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste original',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [
                ['code' => 'Q1', 'points_possible' => 60, 'domains' => [['domain_id' => $domain->id, 'allocation_percent' => 100]]],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);

            $targetClass = $this->makeClass($subject);

            $this->actingAs($this->user)
                ->get("/classes/{$targetClass->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page
                    ->has('importableInstruments', 1)
                    ->where('importableInstruments.0.ulid', $source->ulid)
                    ->where('importableInstruments.0.title', 'Teste original')
                    ->where('importableInstruments.0.total_points', 100.0)
                    ->has('importableInstruments.0.items', 2)
                    ->where('importableInstruments.0.items.0.code', 'Q1')
                    ->where('importableInstruments.0.items.0.domains.0.domain_id', $domain->id)
                    ->where('importableInstruments.0.items.0.domains.0.allocation_percent', 100.0));
        });
    }

    #[Test]
    public function an_instrument_from_a_different_subject_is_not_listed(): void
    {
        $this->inTenant(function (): void {
            $subjectA = Subject::factory()->recycle($this->organization)->create();
            $subjectB = Subject::factory()->recycle($this->organization)->create();
            $sourceClass = $this->makeClass($subjectA);
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($sourceClass->academicYear)->create();

            app(InstrumentBuilder::class)->create($sourceClass, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => \App\Models\InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste de outra disciplina',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);

            $targetClass = $this->makeClass($subjectB);

            $this->actingAs($this->user)
                ->get("/classes/{$targetClass->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page->where('importableInstruments', []));
        });
    }

    #[Test]
    public function another_teachers_instrument_is_not_listed_even_in_the_same_subject(): void
    {
        $this->inTenant(function (): void {
            $subject = Subject::factory()->recycle($this->organization)->create();
            $colleague = User::factory()->create();
            $this->organization->members()->attach($colleague, ['joined_at' => now()]);

            $colleagueClass = app(CurrentOrganization::class)->runFor($this->organization, function () use ($subject, $colleague) {
                $year = AcademicYear::factory()->recycle($this->organization)->create();
                $class = SchoolClass::factory()->recycle($this->organization)->create([
                    'academic_year_id' => $year->id,
                    'subject_id' => $subject->id,
                ]);
                $class->teachers()->attach($colleague, ['role' => 'owner']);

                return $class;
            });
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($colleagueClass->academicYear)->create();

            app(InstrumentBuilder::class)->create($colleagueClass, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => \App\Models\InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste do colega',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);

            $myClass = $this->makeClass($subject);

            $this->actingAs($this->user)
                ->get("/classes/{$myClass->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page->where('importableInstruments', []));
        });
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentImportTemplateTest`
Expected: all 3 FAIL — `importableInstruments` is not yet part of the `create()` response.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/InstrumentController.php`, read the current `create()` method first (it's short — `Gate::authorize('update', $class)` then an `Inertia::render('instruments/Create', [...])` call using the existing `formOptions($class)` helper). Add `use App\Models\InstrumentItem;` if not already imported (it already is, used elsewhere in this file). Change `create()` from:

```php
    public function create(SchoolClass $class): Response
    {
        Gate::authorize('update', $class);

        return Inertia::render('instruments/Create', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            ...$this->formOptions($class),
        ]);
    }
```

to:

```php
    public function create(SchoolClass $class): Response
    {
        Gate::authorize('update', $class);

        return Inertia::render('instruments/Create', [
            'schoolClass' => ['ulid' => $class->ulid, 'label' => $class->label],
            ...$this->formOptions($class),
            'importableInstruments' => $this->importableInstrumentsFor($class),
        ]);
    }
```

Add this new protected method right after `formOptions()`:

```php
    /**
     * The teacher's own instruments from classes of the SAME subject as
     * $class — never a colleague's, never a different subject (which would
     * risk copying domain allocations that don't exist for this subject).
     *
     * @return array<int, array<string, mixed>>
     */
    protected function importableInstrumentsFor(SchoolClass $class): array
    {
        return Instrument::query()
            ->whereHas('schoolClass.teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->whereHas('schoolClass', fn ($query) => $query->where('subject_id', $class->subject_id))
            ->with(['schoolClass', 'items.domainAllocations'])
            ->orderByDesc('applied_on')
            ->get()
            ->map(fn (Instrument $source) => [
                'ulid' => $source->ulid,
                'title' => $source->title,
                'class_label' => $source->schoolClass->label,
                'applied_on' => $source->applied_on->toDateString(),
                'total_points' => $source->total_points === null ? null : (float) $source->total_points,
                'allow_bonus' => $source->allow_bonus,
                'items' => $source->items->map(fn (InstrumentItem $item) => [
                    'code' => $item->code,
                    'label' => $item->label,
                    'points_possible' => (float) $item->points_possible,
                    'is_bonus' => $item->is_bonus,
                    'domains' => $item->domainAllocations->map(fn ($allocation) => [
                        'domain_id' => $allocation->domain_id,
                        'allocation_percent' => (float) $allocation->allocation_percent,
                    ]),
                ]),
            ])
            ->all();
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentImportTemplateTest`
Expected: PASS (3/3).

- [ ] **Step 5: Run the full suite, Larastan, and Pint**

Run: `php artisan test`
Run: `composer types:check`
Run: `composer lint`
Expected: all clean.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/InstrumentController.php tests/Feature/Assessment/InstrumentImportTemplateTest.php
git commit -m "feat(instruments): list the teacher's own same-subject instruments as import candidates"
```

---

### Task 2: Frontend — the import selector and copy-on-select logic

**Files:**
- Modify: `resources/js/pages/instruments/Create.vue` — **or wherever the create form's reactive state actually lives by the time you start this task; re-read the Global Constraints sequencing note above before touching anything.**

**Interfaces:**
- Consumes: `importableInstruments` prop from Task 1, exact shape `{ulid, title, class_label, applied_on, total_points, allow_bonus, items: [{code, label, points_possible, is_bonus, domains: [{domain_id, allocation_percent}]}]}[]`.

- [ ] **Step 1: Add the prop type**

Wherever the create form's `defineProps<{...}>()` block is (currently `resources/js/pages/instruments/Create.vue:23-28`), add a new type and prop:

```ts
type ImportableInstrument = {
    ulid: string;
    title: string;
    class_label: string;
    applied_on: string;
    total_points: number | null;
    allow_bonus: boolean;
    items: ItemRow[];
};
```

(`ItemRow` already exists in this file — currently lines 13-21 — reuse it as-is; the shape Task 1 sends matches it field-for-field except it never includes a `ulid`/`has_scores`, which is fine since `ItemRow`'s own fields beyond `code`/`label`/`points_possible`/`is_bonus`/`domains` are either absent here or don't apply to a fresh copy.)

Add `importableInstruments: ImportableInstrument[];` to the props type.

- [ ] **Step 2: Add the selector and the copy function**

Add this near the other form-manipulation functions (e.g. right after `addItem`/`removeItem`, currently around line 66):

```ts
const selectedImportUlid = ref<string | null>(null);

function applyImportedTemplate(): void {
    const source = props.importableInstruments.find((instrument) => instrument.ulid === selectedImportUlid.value);

    if (!source) {
        return;
    }

    form.title = source.title;
    form.total_points = source.total_points ?? 0;
    form.allow_bonus = source.allow_bonus;
    form.items = source.items.map((item) => ({
        code: item.code,
        label: item.label ?? '',
        points_possible: item.points_possible,
        is_bonus: item.is_bonus,
        domains: item.domains.map((domain) => ({ ...domain })),
    }));
}
```

Add `ref` to the existing `vue` import if not already there (`Create.vue` currently imports `computed` from `'vue'` — add `ref` alongside it).

Add this markup right after the `<Heading>` element and before the `<form>` (or the equivalent spot in whichever file you're actually editing):

```html
<div v-if="importableInstruments.length" class="flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-border p-3">
    <div class="grid gap-1.5">
        <Label class="text-xs">Importar de outro instrumento</Label>
        <select v-model="selectedImportUlid" class="h-9 rounded-md border border-input bg-transparent px-3 text-sm">
            <option :value="null">Nenhum</option>
            <option v-for="instrument in importableInstruments" :key="instrument.ulid" :value="instrument.ulid">
                {{ instrument.title }} · {{ instrument.class_label }} · {{ instrument.applied_on }}
            </option>
        </select>
    </div>
    <Button type="button" variant="outline" size="sm" :disabled="!selectedImportUlid" @click="applyImportedTemplate">
        Importar questões
    </Button>
    <p class="w-full text-xs text-muted-foreground">
        Copia o título, a cotação total e as questões — nunca notas de alunos. Continua tudo editável depois de importar.
    </p>
</div>
```

- [ ] **Step 3: Build and type-check**

Run: `npm run build`
Expected: builds cleanly.

Run: `npm run types:check`
Expected: clean.

- [ ] **Step 4: Manual verification**

1. On the local site, create an instrument with 2-3 questions (some with a domain allocation) in a class of subject A.
2. Go to "Novo instrumento" for a DIFFERENT class of the SAME subject A: confirm the "Importar de outro instrumento" control appears, listing the instrument you just created (with its class and date, to disambiguate).
3. Select it and click "Importar questões": confirm the title, cotação total, and every question (including domain allocations, if any) are now filled in exactly as the source instrument had them.
4. Change the imported title before submitting: confirm it's a completely normal, editable field — nothing about having imported locks it.
5. Submit the form: confirm the new instrument is created with the imported structure, and that this new instrument has zero scores (there was nothing to copy — only structure).
6. Go to "Novo instrumento" for a class of a DIFFERENT subject: confirm the import control does not appear at all (empty `importableInstruments`).

Describe what you actually observed for each of these 6 checks.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/instruments/Create.vue
git commit -m "feat(instruments): import an existing instrument's question structure when creating a new one"
```

(Adjust the `git add` path if Task 2 ended up editing a different file per the sequencing note.)
