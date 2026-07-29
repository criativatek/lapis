# Apreciação Qualitativa por Instrumento Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show a percentage and a qualitative rating (e.g. "Bom") next to each student's total in the instrument grading grid, derived from the class's assessment-profile scale — no new scale/bands concept, pure reuse of what already ships.

**Architecture:** `InstrumentController::show()` loads the class's assessment-profile-version scale bands (the exact same query `ClassResultsCalculator::forScope()` already runs) and sends them as a new `scaleBands` prop. `Grid.vue` computes a per-student percentage client-side (over only the items already graded, mirroring the "empty is not zero" rule the app already enforces elsewhere) and looks up which band it falls in, purely for display — nothing is written to the database.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 + TypeScript, PHPUnit 12.

## Global Constraints

- No migration. This is a read-only, display-only feature.
- Reuse the existing `Scale`/`ScaleLevel` band mechanism from commit `09eb31e` exactly as-is — do not invent new bands, new labels, or a new scale concept. The bands come from `$class->profileVersion->scale`, the same relationship `ClassResultsCalculator::forScope()` already reads.
- Percentage is computed only over items already graded (`result_state === 'assessed'`), never over the instrument's full `total_points` as a fixed denominator — an ungraded item is absent from the fraction entirely, not a zero (CLAUDE.md §13.3, "vazio não é zero").
- Bonus items (`is_bonus === true`) add to the numerator but never to the denominator — mirrors `CalculationEngine::calculateDomain()` (`app/Domain/Assessment/CalculationEngine.php:89-92`).
- A band match is inclusive on both ends (`percent >= band_min && percent <= band_max`) — mirrors `CalculationEngine::combine()` (`app/Domain/Assessment/CalculationEngine.php:186-193`).
- The qualitative label appears even during partial correction and updates live as more items are graded — it is never withheld until the whole instrument is fully corrected.
- When no bands apply (no profile assigned to the class, or the assigned scale has no bands configured) show "—", never a guessed or default label.
- The new grid column's exact header text is **"Apreciação Qualitativa"**.
- This project has no frontend test runner (no Vitest/Jest, confirmed absent from `package.json` and the repo root) and no existing precedent of component tests — Vue logic here is verified through the backend feature test (the data reaching the page) plus a concrete manual browser check written into this plan. Do not introduce a new test framework for this feature.
- PT-pt copy throughout the UI, matching the rest of the app.

---

### Task 1: Backend — send the class's scale bands to the grading grid

**Files:**
- Modify: `app/Http/Controllers/InstrumentController.php:81-142` (the `show()` method)
- Test: `tests/Feature/Assessment/InstrumentControllerTest.php` (new file)

**Interfaces:**
- Produces: an Inertia prop on the `instruments/Grid` page, `scaleBands: list<array{label: string, band_min: string, band_max: string}>`, ordered by the scale level's `sequence`. Empty array (`[]`) when the class has no `assessment_profile_version_id` set, or the assigned version's scale has no levels with both `band_min_normalized` and `band_max_normalized` set. Task 2 consumes this exact shape (field names `label`, `band_min`, `band_max` — decimal values arrive as strings, e.g. `"19.499999"`).

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/InstrumentControllerTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\Instrument;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentControllerTest extends TestCase
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

    /**
     * @return array{class: SchoolClass, instrument: Instrument}
     */
    protected function scenario(?AssessmentProfileVersion $version = null): array
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
            'assessment_profile_version_id' => $version?->id,
        ]);
        // The policy requires the acting user to teach the class.
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        $instrument = Instrument::factory()->recycle($org)->create([
            'class_id' => $class->id,
            'academic_period_id' => $period->id,
        ]);

        return ['class' => $class, 'instrument' => $instrument];
    }

    #[Test]
    public function the_grid_receives_scale_bands_when_the_class_profile_has_them(): void
    {
        $this->inTenant(function (): void {
            $scale = Scale::factory()->create(['organization_id' => null]);
            $scale->levels()->create(['code' => '1', 'label' => 'Fraco', 'sequence' => 1, 'band_min_normalized' => '0', 'band_max_normalized' => '19.499999']);
            $scale->levels()->create(['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'band_min_normalized' => '19.5', 'band_max_normalized' => '49.499999']);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create(['scale_id' => $scale->id]);
            ['instrument' => $instrument] = $this->scenario($version);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->has('scaleBands', 2)
                    ->where('scaleBands.0.label', 'Fraco')
                    ->where('scaleBands.0.band_min', '0.000000')
                    ->where('scaleBands.0.band_max', '19.499999')
                    ->where('scaleBands.1.label', 'Insuficiente'));
        });
    }

    #[Test]
    public function the_grid_receives_no_scale_bands_when_the_class_has_no_profile(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument] = $this->scenario(null);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page->where('scaleBands', []));
        });
    }

    #[Test]
    public function the_grid_receives_no_scale_bands_when_the_profile_scale_has_none_configured(): void
    {
        $this->inTenant(function (): void {
            $scale = Scale::factory()->create(['organization_id' => null]); // no levels at all
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create(['scale_id' => $scale->id]);
            ['instrument' => $instrument] = $this->scenario($version);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page->where('scaleBands', []));
        });
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentControllerTest`
Expected: all 3 FAIL — the response has no `scaleBands` key at all yet.

- [ ] **Step 3: Implement**

In `app/Http/Controllers/InstrumentController.php`, inside `show()` (currently lines 81-142), add this right before the `return Inertia::render(...)` statement (i.e. right after the `$scores = ...` block, around line 102):

```php
$scaleBands = $instrument->schoolClass->profileVersion?->scale
    ?->levels()
    ->whereNotNull('band_min_normalized')
    ->whereNotNull('band_max_normalized')
    ->orderBy('sequence')
    ->get()
    ->map(fn ($level) => [
        'label' => $level->label,
        'band_min' => (string) $level->band_min_normalized,
        'band_max' => (string) $level->band_max_normalized,
    ])
    ->all() ?? [];
```

Then add `'scaleBands' => $scaleBands,` as a new key in the `Inertia::render('instruments/Grid', [...])` array — add it right after the existing `'states' => ...` line (currently line 137-140), so the array's last two keys become `'states' => ..., 'scaleBands' => $scaleBands,`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentControllerTest`
Expected: PASS (3/3).

- [ ] **Step 5: Run the full suite to confirm no regression**

Run: `php artisan test`
Expected: PASS, same or higher total than before this task.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/InstrumentController.php tests/Feature/Assessment/InstrumentControllerTest.php
git commit -m "feat(instruments): send the class's scale bands to the grading grid"
```

---

### Task 2: Frontend — percentage and qualitative-rating column

**Files:**
- Modify: `resources/js/pages/instruments/Grid.vue`

**Interfaces:**
- Consumes: `scaleBands: {label: string; band_min: string; band_max: string}[]` prop, produced by Task 1.

- [ ] **Step 1: Add the `scaleBands` prop**

In `resources/js/pages/instruments/Grid.vue`, the `defineProps<{...}>()` block currently ends (lines 37-52) with:

```ts
    scores: Score[];
    states: StateOption[];
}>();
```

Change it to:

```ts
    scores: Score[];
    states: StateOption[];
    scaleBands: { label: string; band_min: string; band_max: string }[];
}>();
```

- [ ] **Step 2: Add `percentFor()` and `qualitativeLabelFor()`**

Immediately after the existing `totalFor()` function (currently lines 135-156, ending with its closing `}`), add:

```ts
/**
 * The percentage, over only the items already graded — never the
 * instrument's full total_points as a fixed denominator, so an ungraded
 * item never drags the percentage down (CLAUDE.md §13.3, "vazio não é
 * zero"). Bonus items add to the numerator but not the denominator,
 * mirroring CalculationEngine::calculateDomain()'s treatment of bonus
 * items. Returns null under the same condition totalFor() does (nothing
 * graded yet), or if every graded item happened to be bonus (denominator
 * would be zero).
 */
function percentFor(student: Student): number | null {
    let earned = 0;
    let possible = 0;
    let assessed = 0;

    for (const item of props.items) {
        const current = cells[cellKey(student.enrollment_id, item.id)];

        if (current?.state === 'assessed' && current.points !== null) {
            earned += current.points;

            if (!item.is_bonus) {
                possible += item.points_possible;
            }

            assessed += 1;
        }
    }

    if (assessed === 0 || possible === 0) {
        return null;
    }

    return Math.round((earned / possible) * 1000) / 10;
}

/**
 * The qualitative label for the student's current percentage, from the
 * class's assessment-profile scale bands (scaleBands prop). A band match is
 * inclusive on both ends, mirroring CalculationEngine::combine()'s own
 * band-matching loop. Returns null when nothing is graded yet, or when
 * scaleBands is empty (no profile assigned to the class, or its scale has
 * no bands configured) — the caller renders "—" in that case, never a
 * guessed label.
 */
function qualitativeLabelFor(student: Student): string | null {
    const percent = percentFor(student);

    if (percent === null) {
        return null;
    }

    const band = props.scaleBands.find(
        (band) => percent >= Number(band.band_min) && percent <= Number(band.band_max),
    );

    return band?.label ?? null;
}
```

- [ ] **Step 3: Add the percentage to the Total cell, and a new column**

In the `<thead>` (currently around line 255), change:

```html
                        <th class="px-3 py-2 text-right font-medium">Total</th>
```

to:

```html
                        <th class="px-3 py-2 text-right font-medium">Total</th>
                        <th class="px-3 py-2 text-left font-medium">Apreciação Qualitativa</th>
```

In the `<tbody>`, the existing Total `<td>` (currently lines 299-307) is:

```html
                        <td class="px-3 py-1.5 text-right font-semibold tabular-nums">
                            <template v-if="totalFor(student) === null">
                                <span class="text-muted-foreground" title="Sem classificações registadas — não é zero.">—</span>
                            </template>
                            <template v-else>
                                {{ totalFor(student) }}<span v-if="instrument.total_points" class="font-normal text-muted-foreground">/{{ instrument.total_points }}</span>
                                <span v-if="isPartial(student)" class="ml-1 text-xs font-normal text-amber-600" title="Ainda há questões por avaliar.">parcial</span>
                            </template>
                        </td>
```

Change it to (adds the percentage, and a new column right after it):

```html
                        <td class="px-3 py-1.5 text-right font-semibold tabular-nums">
                            <template v-if="totalFor(student) === null">
                                <span class="text-muted-foreground" title="Sem classificações registadas — não é zero.">—</span>
                            </template>
                            <template v-else>
                                {{ totalFor(student) }}<span v-if="instrument.total_points" class="font-normal text-muted-foreground">/{{ instrument.total_points }}</span>
                                <span v-if="percentFor(student) !== null" class="font-normal text-muted-foreground"> · {{ percentFor(student) }}%</span>
                                <span v-if="isPartial(student)" class="ml-1 text-xs font-normal text-amber-600" title="Ainda há questões por avaliar.">parcial</span>
                            </template>
                        </td>
                        <td class="px-3 py-1.5 text-left text-muted-foreground">
                            {{ qualitativeLabelFor(student) ?? '—' }}
                        </td>
```

- [ ] **Step 4: Build and type-check**

Run: `npm run build`
Expected: builds cleanly, no errors.

Run: `npm run types:check`
Expected: no output (clean).

- [ ] **Step 5: Manual verification in the browser**

This project has no frontend test runner, so this step is the real verification of the calculation logic — follow it exactly, using the app's own UI (no scripting needed).

1. Start the local site (`http://lapis.test`, already configured per this project's Herd setup) and log in as a teacher.
2. If you don't already have a class whose assessment profile uses the "Escala 1 a 5" system scale, create one: "Novo perfil de avaliação" → pick "Escala 1 a 5" → activate it → assign it to a class from that class's page.
3. On that class, create a new instrument ("Novo instrumento") with exactly 2 questions: Q1 worth 30 points, Q2 worth 20 points (total 50). Save it.
4. Open the instrument's grading grid. For exactly one student, enter **24** for Q1 and leave Q2 empty (still "por avaliar").
   - Expected in the Total column: `24/50 · 80% · parcial`
   - Expected in the Apreciação Qualitativa column: `Bom` (80% falls in the 70–89% band).
5. Now also enter **10** for Q2 on that same student, and click "Guardar".
   - Expected in the Total column: `34/50 · 68%` (no longer "parcial").
   - Expected in the Apreciação Qualitativa column: `Suficiente` (68% falls in the 50–69% band — the label changed live as soon as the second question was graded, without needing to reload the page).
6. For a student with nothing graded at all, confirm both the Total and Apreciação Qualitativa columns show "—".
7. If you have a class whose profile uses a scale without bands (e.g. "Escala 0 a 20"), or a class with no profile assigned at all, confirm its instruments' grids show a percentage (once something is graded) but "—" in Apreciação Qualitativa.

Describe what you actually observed for each of these 7 checks in your final report — do not simply assert it works.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/instruments/Grid.vue
git commit -m "feat(instruments): show percentage and qualitative rating in the grading grid"
```
