# Edição de Instrumentos de Avaliação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a teacher edit an already-created instrument (questions, weights, metadata) at any time, even with scores already recorded — except removing a scored question or lowering a scored question's points below its highest recorded score, both blocked — plus a reversible cancellation flow.

**Architecture:** `InstrumentBuilder::update()` replaces its dead, unsafe "delete all items and recreate" body with a real diff (kept/edited items updated in place, new items created, removed items checked for scores first). New routes/controller actions expose edit/update/cancel/revert. The frontend extracts the existing `Create.vue` form logic into a shared `InstrumentForm.vue`, mirroring the `ProfileForm.vue` pattern already used for assessment profiles, and `Grid.vue` gains edit/cancel/revert affordances.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 + TypeScript, PHPUnit 12.

## Global Constraints

- An instrument is always editable — items, weights, and metadata — even after scores exist. Recalculation is automatic from whatever the current items say; an already-confirmed/published classification that depended on old numbers is allowed to go stale silently (accepted explicitly, not handled by this plan).
- Removing a question is blocked while it has any row in `student_item_scores`. The teacher must clear those scores in the grid first.
- Lowering a question's `points_possible` below the highest `points_earned` already recorded against it is blocked.
- Cancelling an instrument requires a mandatory `reason`. While `status === 'cancelled'` the instrument is read-only: no editing (items/weights/metadata), no score entry. "Reverter anulação" restores the exact prior status and clears the cancellation fields; this cycle (cancel → revert → edit → cancel again) may repeat without limit.
- Authorization stays exactly as today: `Gate::authorize('update', $instrument->schoolClass)` — any teacher assigned to the class. No new Policy class.
- No new abstractions beyond what each task needs — reuse `InstrumentBuilder::guard()` unchanged, reuse the existing `ProfileForm.vue`/`Create.vue`/`Edit.vue` split pattern exactly.
- ULID (never a sequential id) identifies an existing question across an edit — `InstrumentItem.ulid` already exists and is already the model's route key.
- PT-pt copy throughout the UI.
- `DECIMAL`, never `float`, for anything that becomes a grade — `points_possible`/`points_earned` comparisons in PHP go through `(float)` casts only for comparison purposes on already-decimal-cast Eloquent attributes, matching the existing style in `InstrumentBuilder::guard()`.

---

### Task 1: Schema and model — `status_before_cancellation`, and a `scores()` relation on `InstrumentItem`

**Files:**
- Create: `database/migrations/2026_07_29_000100_add_status_before_cancellation_to_instruments_table.php`
- Modify: `app/Models/Instrument.php:40-44` (the `#[Fillable]` list)
- Modify: `app/Models/InstrumentItem.php` (add a `scores()` relation)
- Test: `tests/Feature/Assessment/InstrumentModelTest.php` (new)

**Interfaces:**
- Produces: `Instrument::$status_before_cancellation` (nullable string, mass-assignable), and `InstrumentItem::scores(): HasMany<StudentItemScore>`. Task 2 (the diff logic) and Task 4 (cancel/revert) both consume these directly.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/InstrumentModelTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\StudentItemScore;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentModelTest extends TestCase
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

    #[Test]
    public function status_before_cancellation_is_mass_assignable_and_nullable_by_default(): void
    {
        $this->inTenant(function (): void {
            $instrument = Instrument::factory()->recycle($this->organization)->create();

            $this->assertNull($instrument->status_before_cancellation);

            $instrument->update(['status_before_cancellation' => 'prepared']);

            $this->assertSame('prepared', $instrument->refresh()->status_before_cancellation);
        });
    }

    #[Test]
    public function an_instrument_item_can_list_its_own_scores(): void
    {
        $this->inTenant(function (): void {
            $item = InstrumentItem::factory()->recycle($this->organization)->create();

            $this->assertCount(0, $item->scores()->get());

            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $item->instrument_id,
                'instrument_item_id' => $item->id,
                'result_state' => 'assessed',
                'points_earned' => 5,
            ]);

            $this->assertCount(1, $item->fresh()->scores()->get());
        });
    }
}
```

`StudentItemScore` has no factory yet — create one alongside this test, since `StudentItemScoreFactory` will also be reused by Task 2's tests:

Create `database/factories/StudentItemScoreFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\Organization;
use App\Models\StudentItemScore;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentItemScore>
 */
class StudentItemScoreFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'instrument_id' => Instrument::factory(),
            'instrument_item_id' => InstrumentItem::factory(),
            'enrollment_id' => Enrollment::factory(),
            'result_state' => 'assessed',
            'points_earned' => 5,
        ];
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentModelTest`
Expected: FAIL — `status_before_cancellation` is an unknown column and `scores()` is an undefined method.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_29_000100_add_status_before_cancellation_to_instruments_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers the status an instrument had right before it was cancelled, so
 * "Reverter anulação" can restore it exactly rather than guessing (§ instrument
 * editing design, cancellation section). NULL whenever the instrument is not
 * currently cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->string('status_before_cancellation', 16)->nullable()->after('cancellation_reason');
        });
    }

    public function down(): void
    {
        Schema::table('instruments', function (Blueprint $table) {
            $table->dropColumn('status_before_cancellation');
        });
    }
};
```

- [ ] **Step 4: Add the column to `Instrument`'s Fillable list**

In `app/Models/Instrument.php`, change the `#[Fillable]` attribute (currently lines 40-44):

```php
#[Fillable([
    'class_id', 'academic_period_id', 'instrument_type_id', 'title', 'applied_on',
    'status', 'counts_toward_classification', 'purpose', 'total_points', 'scale_id',
    'weight', 'allow_bonus', 'internal_notes',
])]
```

to:

```php
#[Fillable([
    'class_id', 'academic_period_id', 'instrument_type_id', 'title', 'applied_on',
    'status', 'counts_toward_classification', 'purpose', 'total_points', 'scale_id',
    'weight', 'allow_bonus', 'internal_notes', 'status_before_cancellation',
    'cancelled_at', 'cancelled_by', 'cancellation_reason',
])]
```

(`cancelled_at`/`cancelled_by`/`cancellation_reason` were schema columns already but were never mass-assignable — Task 4's `cancel()`/`revertCancellation()` need to write them via `$instrument->update([...])`, so they must join the Fillable list now too.)

- [ ] **Step 5: Add the `scores()` relation to `InstrumentItem`**

In `app/Models/InstrumentItem.php`, add this method right after the existing `domainAllocations()` method, and add the `HasMany`/`StudentItemScore` imports already used elsewhere in the file (the `HasMany` import already exists; add `use App\Models\StudentItemScore;` near the top with the other `App\Models\*` imports):

```php
    /**
     * Every score recorded against this question — the deciding factor in
     * whether it may still be removed or have its points_possible lowered
     * during an edit (see InstrumentBuilder::update()).
     *
     * @return HasMany<StudentItemScore, $this>
     */
    public function scores(): HasMany
    {
        return $this->hasMany(StudentItemScore::class);
    }
```

- [ ] **Step 6: Run migrations and the tests to verify they pass**

Run: `php artisan migrate`
Run: `php artisan test --filter=InstrumentModelTest`
Expected: PASS (2/2).

- [ ] **Step 7: Run the full suite and Larastan to confirm no regression**

Run: `php artisan test`
Run: `composer types:check`
Expected: both clean.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_29_000100_add_status_before_cancellation_to_instruments_table.php \
  database/factories/StudentItemScoreFactory.php \
  app/Models/Instrument.php app/Models/InstrumentItem.php \
  tests/Feature/Assessment/InstrumentModelTest.php
git commit -m "feat(instruments): add status_before_cancellation column and InstrumentItem::scores()"
```

---

### Task 2: `InstrumentBuilder::update()` — real diff replacing the dead delete-all-and-recreate

**Files:**
- Modify: `app/Services/Assessment/InstrumentBuilder.php:44-134`
- Modify: `app/Support/Assessment/InstrumentValidationException.php`
- Test: `tests/Feature/Assessment/InstrumentBuilderTest.php` (existing file, add tests)

**Interfaces:**
- Consumes: `InstrumentItem::scores()` from Task 1.
- Produces: `InstrumentBuilder::update(Instrument $instrument, array $attributes, list<array{ulid?: ?string, code: string, label?: ?string, points_possible: float, is_bonus?: bool, domains?: list<array{domain_id: int, allocation_percent: float}>}> $items): Instrument`. An item with a `ulid` matching an existing row is updated in place; an item with no `ulid` (or an unrecognized one) is created; an existing item whose `ulid` is absent from `$items` is removed. Throws `InstrumentValidationException` (unchanged class, two new named constructors) if a to-be-removed item has any score, or if a kept item's `points_possible` would drop below its highest recorded `points_earned`. Task 3's controller `update()` action consumes this signature directly.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/Assessment/InstrumentBuilderTest.php` (the file already has `schoolClass()`/`attributes()` helpers from the existing tests — reuse them as-is):

```php
    #[Test]
    public function updating_an_instrument_can_add_a_new_item_without_touching_existing_ones(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $existing = $instrument->items()->firstOrFail();

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class, ['total_points' => 150]), [
                ['ulid' => $existing->ulid, 'code' => 'Q1', 'points_possible' => 100],
                ['code' => 'Q2', 'points_possible' => 50],
            ]);

            $instrument->refresh();
            $this->assertSame(2, $instrument->items()->count());
            $this->assertSame($existing->id, $instrument->items()->where('code', 'Q1')->firstOrFail()->id);
        });
    }

    #[Test]
    public function updating_an_instrument_edits_an_existing_items_points(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $existing = $instrument->items()->firstOrFail();

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class), [
                ['ulid' => $existing->ulid, 'code' => 'Q1', 'points_possible' => 80],
                ['code' => 'Q2', 'points_possible' => 20],
            ]);

            $this->assertSame('80.0000', $existing->fresh()->points_possible);
        });
    }

    #[Test]
    public function removing_an_unscored_item_succeeds(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();

            app(InstrumentBuilder::class)->update(
                $instrument,
                $this->attributes($class, ['total_points' => 60]),
                [['ulid' => $instrument->items()->where('code', 'Q1')->firstOrFail()->ulid, 'code' => 'Q1', 'points_possible' => 60]],
            );

            $this->assertSame(1, $instrument->items()->count());
            $this->assertNull(InstrumentItem::find($q2->id));
        });
    }

    #[Test]
    public function removing_a_scored_item_is_rejected_and_nothing_is_deleted(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q2->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 30,
            ]);

            $this->expectException(InstrumentValidationException::class);

            try {
                app(InstrumentBuilder::class)->update(
                    $instrument,
                    $this->attributes($class, ['total_points' => 60]),
                    [['ulid' => $instrument->items()->where('code', 'Q1')->firstOrFail()->ulid, 'code' => 'Q1', 'points_possible' => 60]],
                );
            } finally {
                $this->assertSame(2, $instrument->items()->count(), 'Nothing should have been deleted.');
                $this->assertNotNull(InstrumentItem::find($q2->id));
            }
        });
    }

    #[Test]
    public function lowering_points_possible_below_an_existing_score_is_rejected(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $q1 = $instrument->items()->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q1->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 80,
            ]);

            $this->expectException(InstrumentValidationException::class);

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class, ['total_points' => 50]), [
                ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 50], // below the 80 already recorded
            ]);
        });
    }

    #[Test]
    public function raising_points_possible_on_a_scored_item_is_allowed(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $q1 = $instrument->items()->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q1->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 80,
            ]);

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class, ['total_points' => 120]), [
                ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 120],
            ]);

            $this->assertSame('120.0000', $q1->fresh()->points_possible);
        });
    }

    #[Test]
    public function updating_still_enforces_the_domain_allocation_and_points_total_guard(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);
            $q1 = $instrument->items()->firstOrFail();

            $this->expectException(InstrumentValidationException::class);

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class, ['total_points' => 100]), [
                ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60], // 60, not 100 — guard() still applies
            ]);
        });
    }
```

Add the two missing imports at the top of the test file: `use App\Models\Enrollment;`, `use App\Models\InstrumentItem;`, `use App\Models\StudentItemScore;`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentBuilderTest`
Expected: the 7 new tests FAIL (the current `update()` deletes-and-recreates everything, so items lose their `id`/`ulid` identity, and there is no scored-item protection at all).

- [ ] **Step 3: Add the two new exception constructors**

In `app/Support/Assessment/InstrumentValidationException.php`, add these two methods after the existing `pointsDoNotMatchTotal()`:

```php
    public static function cannotRemoveScoredItem(string $itemCode): self
    {
        return new self(__(
            'A questão :code já tem notas lançadas e não pode ser removida. Limpe as notas dessa questão primeiro.',
            ['code' => $itemCode],
        ));
    }

    public static function pointsPossibleBelowExistingScore(string $itemCode, string $minValue): self
    {
        return new self(__(
            'A cotação da questão :code não pode ser inferior a :min — já existe uma nota lançada com esse valor.',
            ['code' => $itemCode, 'min' => $minValue],
        ));
    }
```

- [ ] **Step 4: Rewrite `InstrumentBuilder`**

Replace the whole `app/Services/Assessment/InstrumentBuilder.php` file with:

```php
<?php

namespace App\Services\Assessment;

use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentItem;
use App\Models\SchoolClass;
use App\Support\Assessment\InstrumentValidationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates and edits an instrument together with its items and their domain
 * allocations, in one transaction (§12.3).
 *
 * Two rules live here because they span rows and so cannot be CHECK constraints:
 *  - each item's domain allocations sum to 100% (or the item has none at all);
 *  - the items' points sum to the declared total, unless bonus is allowed.
 */
class InstrumentBuilder
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array{code: string, label?: ?string, points_possible: float, domains?: list<array{domain_id: int, allocation_percent: float}>, is_bonus?: bool}>  $items
     */
    public function create(SchoolClass $class, array $attributes, array $items): Instrument
    {
        $this->guard($attributes, $items);

        return DB::transaction(function () use ($class, $attributes, $items): Instrument {
            $instrument = $class->instruments()->create($attributes);

            $this->syncItems($instrument, $items);

            return $instrument;
        });
    }

    /**
     * Edits an instrument and diffs its items against what already exists —
     * unlike the naive delete-all-and-recreate this replaced, an existing item
     * is updated in place (its id, and any scores against it, survive). An item
     * is only ever removed when the client stops sending its `ulid` back, and
     * only when it has no score recorded — otherwise the whole update is
     * rejected before anything is written. Lowering a scored item's
     * points_possible below its highest recorded points_earned is rejected the
     * same way. Both rejection checks run BEFORE the transaction starts, so a
     * rejected update never partially applies.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<array{ulid?: ?string, code: string, label?: ?string, points_possible: float, is_bonus?: bool, domains?: list<array{domain_id: int, allocation_percent: float}>}>  $items
     */
    public function update(Instrument $instrument, array $attributes, array $items): Instrument
    {
        $this->guard($attributes, $items);

        $existingItems = $instrument->items()->get()->keyBy('ulid');
        $submittedUlids = collect($items)->pluck('ulid')->filter()->all();
        $toRemove = $existingItems->reject(
            fn (InstrumentItem $item) => in_array($item->ulid, $submittedUlids, true),
        );

        foreach ($toRemove as $item) {
            if ($item->scores()->exists()) {
                throw InstrumentValidationException::cannotRemoveScoredItem($item->code);
            }
        }

        foreach ($items as $itemData) {
            if (! isset($itemData['ulid'])) {
                continue;
            }

            $existing = $existingItems->get($itemData['ulid']);

            if ($existing === null) {
                continue;
            }

            $highestScore = $existing->scores()->max('points_earned');

            if ($highestScore !== null && (float) $itemData['points_possible'] < (float) $highestScore) {
                throw InstrumentValidationException::pointsPossibleBelowExistingScore(
                    $existing->code,
                    rtrim(rtrim(number_format((float) $highestScore, 4, '.', ''), '0'), '.'),
                );
            }
        }

        return DB::transaction(function () use ($instrument, $attributes, $items, $toRemove, $existingItems): Instrument {
            $instrument->update($attributes);

            foreach ($toRemove as $item) {
                $item->delete();
            }

            foreach ($items as $index => $itemData) {
                $existing = isset($itemData['ulid']) ? $existingItems->get($itemData['ulid']) : null;

                if ($existing !== null) {
                    $existing->update([...$this->itemAttributes($itemData), 'sequence' => $index + 1]);
                    $existing->domainAllocations()->each(fn ($allocation) => $allocation->delete());
                    $this->syncAllocations($existing, $itemData['domains'] ?? []);
                } else {
                    $created = $instrument->items()->create([...$this->itemAttributes($itemData), 'sequence' => $index + 1]);
                    $this->syncAllocations($created, $itemData['domains'] ?? []);
                }
            }

            return $instrument->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<array<string, mixed>>  $items
     */
    protected function guard(array $attributes, array $items): void
    {
        if ($items === []) {
            throw InstrumentValidationException::noItems();
        }

        foreach ($items as $index => $item) {
            $allocations = $item['domains'] ?? [];

            // No allocation at all is legitimate — the item then counts only
            // toward the instrument total, not toward any domain (§4.3).
            if ($allocations === []) {
                continue;
            }

            $total = array_sum(array_column($allocations, 'allocation_percent'));

            if (abs($total - 100.0) > 0.0001) {
                throw InstrumentValidationException::allocationsMustTotal100(
                    (string) ($item['code'] ?? $index + 1),
                    rtrim(rtrim(number_format($total, 4, '.', ''), '0'), '.'),
                );
            }
        }

        $declaredTotal = $attributes['total_points'] ?? null;
        $allowBonus = (bool) ($attributes['allow_bonus'] ?? false);

        if ($declaredTotal !== null && ! $allowBonus) {
            $itemTotal = 0.0;
            foreach ($items as $item) {
                if (! ($item['is_bonus'] ?? false)) {
                    $itemTotal += (float) $item['points_possible'];
                }
            }

            if (abs($itemTotal - (float) $declaredTotal) > 0.0001) {
                throw InstrumentValidationException::pointsDoNotMatchTotal(
                    rtrim(rtrim(number_format($itemTotal, 4, '.', ''), '0'), '.'),
                    rtrim(rtrim(number_format((float) $declaredTotal, 4, '.', ''), '0'), '.'),
                );
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    protected function syncItems(Instrument $instrument, array $items): void
    {
        foreach ($items as $index => $item) {
            $created = $instrument->items()->create([...$this->itemAttributes($item), 'sequence' => $index + 1]);
            $this->syncAllocations($created, $item['domains'] ?? []);
        }
    }

    /**
     * The plain-column attributes shared by a freshly-created item and an
     * edited existing one — kept in one place so create() and update() never
     * drift on which fields an item carries.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function itemAttributes(array $item): array
    {
        return [
            'code' => $item['code'],
            'label' => $item['label'] ?? null,
            'points_possible' => $item['points_possible'],
            'scoring_mode' => $item['scoring_mode'] ?? 'points',
            'is_bonus' => $item['is_bonus'] ?? false,
            'source_group_label' => $item['source_group_label'] ?? null,
        ];
    }

    /**
     * @param  list<array{domain_id: int, allocation_percent: float}>  $domains
     */
    protected function syncAllocations(InstrumentItem $item, array $domains): void
    {
        foreach ($domains as $allocation) {
            $item->domainAllocations()->create([
                'domain_id' => $allocation['domain_id'],
                'allocation_percent' => $allocation['allocation_percent'],
            ]);
        }
    }

    /**
     * The domains a class's active profile version assesses — the only domains an
     * item may be allocated to.
     *
     * @return Collection<int, Domain>
     */
    public function domainsFor(SchoolClass $class): Collection
    {
        $version = $class->profileVersion;

        if ($version === null) {
            return collect();
        }

        return Domain::whereIn('id', $version->domains()->pluck('domain_id'))->orderBy('sequence')->get();
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentBuilderTest`
Expected: PASS (all — the 8 pre-existing plus the 7 new).

- [ ] **Step 6: Run the full suite and Larastan**

Run: `php artisan test`
Run: `composer types:check`
Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Assessment/InstrumentBuilder.php app/Support/Assessment/InstrumentValidationException.php tests/Feature/Assessment/InstrumentBuilderTest.php
git commit -m "feat(instruments): replace the unsafe delete-all update() with a real item diff"
```

---

### Task 3: Backend — edit/update routes and controller actions

**Files:**
- Modify: `routes/web.php:107-109` (the instruments block)
- Modify: `app/Http/Controllers/InstrumentController.php`
- Modify: `app/Http/Requests/InstrumentRequest.php:39-46`
- Test: `tests/Feature/Assessment/InstrumentEditTest.php` (new)

**Interfaces:**
- Consumes: `InstrumentBuilder::update()` from Task 2.
- Produces: `GET instruments/{instrument}/edit` (name `instruments.edit`) rendering `instruments/Edit` with props `{instrument: {..., items: [{ulid, code, label, points_possible, is_bonus, has_scores, domains}]}, schoolClass, periods, types, domains}`; `PUT instruments/{instrument}` (name `instruments.update`). Task 5 (the `Edit.vue` page) consumes this exact prop shape.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/InstrumentEditTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentEditTest extends TestCase
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
    protected function scenario(): array
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [
            ['code' => 'Q1', 'points_possible' => 60],
            ['code' => 'Q2', 'points_possible' => 40],
        ]);

        return ['class' => $class, 'instrument' => $instrument];
    }

    #[Test]
    public function the_edit_page_flags_which_items_already_have_scores(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'instrument' => $instrument] = $this->scenario();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q2->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 30,
            ]);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}/edit")
                ->assertInertia(fn ($page) => $page
                    ->where('instrument.items.0.has_scores', false)
                    ->where('instrument.items.1.has_scores', true));
        });
    }

    #[Test]
    public function updating_adds_a_question_and_keeps_the_others_identity(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument] = $this->scenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status' => $instrument->status->value,
                    'purpose' => $instrument->purpose,
                    'counts_toward_classification' => true,
                    'total_points' => 130,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        ['ulid' => $q2->ulid, 'code' => 'Q2', 'points_possible' => 40],
                        ['code' => 'Q3', 'points_possible' => 30],
                    ],
                ])
                ->assertRedirect(route('instruments.show', $instrument->ulid));

            $instrument->refresh();
            $this->assertSame(3, $instrument->items()->count());
            $this->assertSame($q1->id, $instrument->items()->where('code', 'Q1')->firstOrFail()->id);
            $this->assertSame($q2->id, $instrument->items()->where('code', 'Q2')->firstOrFail()->id);
        });
    }

    #[Test]
    public function updating_cannot_remove_a_scored_question(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'instrument' => $instrument] = $this->scenario();
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();
            $enrollment = Enrollment::factory()->recycle($this->organization)->create(['class_id' => $class->id]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $q2->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 30,
            ]);

            $this->actingAs($this->user)
                ->put("/instruments/{$instrument->ulid}", [
                    'title' => $instrument->title,
                    'academic_period_id' => $instrument->academic_period_id,
                    'instrument_type_id' => $instrument->instrument_type_id,
                    'applied_on' => $instrument->applied_on->toDateString(),
                    'status' => $instrument->status->value,
                    'purpose' => $instrument->purpose,
                    'counts_toward_classification' => true,
                    'total_points' => 60,
                    'allow_bonus' => false,
                    'items' => [
                        ['ulid' => $q1->ulid, 'code' => 'Q1', 'points_possible' => 60],
                        // Q2 dropped — it has a score.
                    ],
                ])
                ->assertSessionHasErrors('items');

            $this->assertSame(2, $instrument->refresh()->items()->count());
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_edit_or_update(): void
    {
        ['instrument' => $instrument] = $this->inTenant(fn () => $this->scenario());
        $organization = $this->organization;
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->get("/instruments/{$instrument->ulid}/edit")
            ->assertForbidden();

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->put("/instruments/{$instrument->ulid}", ['items' => []])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentEditTest`
Expected: all 4 FAIL — there is no `edit`/`update` route yet (404).

- [ ] **Step 3: Add the `items.*.ulid` validation rule**

In `app/Http/Requests/InstrumentRequest.php`, add `use App\Models\InstrumentItem;` and `use App\Rules\BelongsToCurrentOrganization;` (the latter already imported), then add this line right after `'items' => ['required', 'array', 'min:1'],` (currently line 39):

```php
            'items.*.ulid' => ['nullable', 'string', new BelongsToCurrentOrganization(InstrumentItem::class, 'ulid')],
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, right after the existing `Route::get('instruments/{instrument}', ...)->name('instruments.show');` line (currently line 107), add:

```php
        Route::get('instruments/{instrument}/edit', [InstrumentController::class, 'edit'])->name('instruments.edit');
        Route::put('instruments/{instrument}', [InstrumentController::class, 'update'])->name('instruments.update');
```

- [ ] **Step 5: Add the controller actions**

In `app/Http/Controllers/InstrumentController.php`, add `use App\Models\InstrumentStatus;` to the imports, then add these two methods right after `store()` (currently ending at line 76, right before `show()`):

```php
    public function edit(Instrument $instrument): Response
    {
        Gate::authorize('update', $instrument->schoolClass);
        $this->ensureNotCancelled($instrument);

        $instrument->load(['items.domainAllocations', 'schoolClass']);

        return Inertia::render('instruments/Edit', [
            'instrument' => [
                'ulid' => $instrument->ulid,
                'title' => $instrument->title,
                'academic_period_id' => $instrument->academic_period_id,
                'instrument_type_id' => $instrument->instrument_type_id,
                'applied_on' => $instrument->applied_on->toDateString(),
                'status' => $instrument->status->value,
                'purpose' => $instrument->purpose,
                'counts_toward_classification' => $instrument->counts_toward_classification,
                'total_points' => $instrument->total_points === null ? null : (float) $instrument->total_points,
                'allow_bonus' => $instrument->allow_bonus,
                'items' => $instrument->items->map(fn (InstrumentItem $item) => [
                    'ulid' => $item->ulid,
                    'code' => $item->code,
                    'label' => $item->label ?? '',
                    'points_possible' => (float) $item->points_possible,
                    'is_bonus' => $item->is_bonus,
                    'has_scores' => $item->scores()->exists(),
                    'domains' => $item->domainAllocations->map(fn ($allocation) => [
                        'domain_id' => $allocation->domain_id,
                        'allocation_percent' => (float) $allocation->allocation_percent,
                    ]),
                ]),
            ],
            'schoolClass' => ['ulid' => $instrument->schoolClass->ulid, 'label' => $instrument->schoolClass->label],
            ...$this->formOptions($instrument->schoolClass),
        ]);
    }

    public function update(InstrumentRequest $request, Instrument $instrument): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);
        $this->ensureNotCancelled($instrument);

        try {
            $this->builder->update(
                $instrument,
                $request->safe()->except('items'),
                $request->validated('items'),
            );
        } catch (InstrumentValidationException $exception) {
            return back()->withErrors(['items' => $exception->getMessage()])->withInput();
        }

        return to_route('instruments.show', $instrument->ulid);
    }
```

`use App\Support\Assessment\InstrumentValidationException;` is already imported in this file (`store()` already catches it) — no new import needed for that one.

Add this protected helper at the bottom of the class, right before `protected function user()`:

```php
    /**
     * A cancelled instrument is read-only — no editing, no score entry — until
     * "Reverter anulação" (Task 4) brings it back. Shared by edit(), update(),
     * and saveScores().
     */
    protected function ensureNotCancelled(Instrument $instrument): void
    {
        abort_if(
            $instrument->status === InstrumentStatus::Cancelled,
            403,
            'Este instrumento está anulado — reverta a anulação antes de o editar ou lançar notas.',
        );
    }
```

(This method is not yet called from `saveScores()` — Task 4 adds that call, since cancelling doesn't exist as a concept until Task 4 ships. Task 3 only needs it for `edit()`/`update()`, which already check a status that already exists on the enum today.)

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentEditTest`
Expected: PASS (4/4).

- [ ] **Step 7: Run the full suite, Larastan, and Pint**

Run: `php artisan test`
Run: `composer types:check`
Run: `composer lint`
Expected: all clean.

- [ ] **Step 8: Commit**

```bash
git add routes/web.php app/Http/Controllers/InstrumentController.php app/Http/Requests/InstrumentRequest.php tests/Feature/Assessment/InstrumentEditTest.php
git commit -m "feat(instruments): add the edit/update routes and controller actions"
```

---

### Task 4: Backend — cancel and revert-cancellation

**Files:**
- Modify: `routes/web.php`
- Modify: `app/Http/Controllers/InstrumentController.php` (`show()`, `saveScores()`, and two new actions)
- Test: `tests/Feature/Assessment/InstrumentCancellationTest.php` (new)

**Interfaces:**
- Consumes: `Instrument::$status_before_cancellation` from Task 1, `ensureNotCancelled()` from Task 3.
- Produces: `POST instruments/{instrument}/cancel` (name `instruments.cancel`, body `{reason: string}`), `POST instruments/{instrument}/revert-cancellation` (name `instruments.revert-cancellation`). `show()`'s `instrument` prop gains `status: string` and `cancellation_reason: string|null`. Task 6 (`Grid.vue`) consumes these two new fields and the two new routes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/InstrumentCancellationTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Instrument;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentCancellationTest extends TestCase
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

    protected function scenario(): Instrument
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        return app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [['code' => 'Q1', 'points_possible' => 100]]);
    }

    #[Test]
    public function cancelling_requires_a_reason_and_remembers_the_prior_status(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Teste anulado por engano.'])
                ->assertRedirect();

            $instrument->refresh();
            $this->assertSame('cancelled', $instrument->status->value);
            $this->assertSame('prepared', $instrument->status_before_cancellation);
            $this->assertSame('Teste anulado por engano.', $instrument->cancellation_reason);
            $this->assertNotNull($instrument->cancelled_at);
            $this->assertSame($this->user->id, $instrument->cancelled_by);
        });
    }

    #[Test]
    public function cancelling_without_a_reason_is_rejected(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => ''])
                ->assertSessionHasErrors('reason');

            $this->assertSame('prepared', $instrument->refresh()->status->value);
        });
    }

    #[Test]
    public function a_cancelled_instrument_cannot_be_edited_or_scored(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/edit")->assertForbidden();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/scores", ['cells' => []])
                ->assertForbidden();
        });
    }

    #[Test]
    public function reverting_restores_the_exact_prior_status_and_clears_the_cancellation_fields(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/revert-cancellation")
                ->assertRedirect();

            $instrument->refresh();
            $this->assertSame('prepared', $instrument->status->value);
            $this->assertNull($instrument->status_before_cancellation);
            $this->assertNull($instrument->cancelled_at);
            $this->assertNull($instrument->cancelled_by);
            $this->assertNull($instrument->cancellation_reason);
        });
    }

    #[Test]
    public function the_cancel_revert_cycle_can_repeat(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Primeira.']);
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/revert-cancellation");
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Segunda.']);

            $instrument->refresh();
            $this->assertSame('cancelled', $instrument->status->value);
            $this->assertSame('Segunda.', $instrument->cancellation_reason);
        });
    }

    #[Test]
    public function reverting_an_instrument_that_is_not_cancelled_is_rejected(): void
    {
        $this->inTenant(function (): void {
            $instrument = $this->scenario();

            $this->actingAs($this->user)
                ->post("/instruments/{$instrument->ulid}/revert-cancellation")
                ->assertSessionHasErrors('status');
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_cancel_or_revert(): void
    {
        $instrument = $this->inTenant(fn () => $this->scenario());
        $organization = $this->organization;
        $colleague = User::factory()->create();
        $organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession(['organization_id' => $organization->id])
            ->actingAs($colleague)
            ->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'x'])
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=InstrumentCancellationTest`
Expected: FAIL — the routes don't exist yet (404 instead of the expected responses).

- [ ] **Step 3: Add the routes**

In `routes/web.php`, right after the `instruments.update` line added in Task 3, add:

```php
        Route::post('instruments/{instrument}/cancel', [InstrumentController::class, 'cancel'])->name('instruments.cancel');
        Route::post('instruments/{instrument}/revert-cancellation', [InstrumentController::class, 'revertCancellation'])->name('instruments.revert-cancellation');
```

- [ ] **Step 4: Add the controller actions**

In `app/Http/Controllers/InstrumentController.php`, add these two methods right after `update()` (added in Task 3), before `show()`:

```php
    public function cancel(Request $request, Instrument $instrument): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if ($instrument->status === InstrumentStatus::Cancelled) {
            return back()->withErrors(['reason' => 'Este instrumento já está anulado.']);
        }

        $instrument->update([
            'status_before_cancellation' => $instrument->status->value,
            'status' => InstrumentStatus::Cancelled,
            'cancelled_at' => now(),
            'cancelled_by' => $this->user()->id,
            'cancellation_reason' => $data['reason'],
        ]);

        return back()->with('status', 'Instrumento anulado.');
    }

    public function revertCancellation(Instrument $instrument): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        if ($instrument->status !== InstrumentStatus::Cancelled) {
            return back()->withErrors(['status' => 'Este instrumento não está anulado.']);
        }

        $instrument->update([
            'status' => $instrument->status_before_cancellation,
            'status_before_cancellation' => null,
            'cancelled_at' => null,
            'cancelled_by' => null,
            'cancellation_reason' => null,
        ]);

        return back()->with('status', 'Anulação revertida.');
    }
```

- [ ] **Step 5: Guard `saveScores()` against a cancelled instrument**

In `app/Http/Controllers/InstrumentController.php::saveScores()`, add a call to the guard right after the existing `Gate::authorize('update', $instrument->schoolClass);` line:

```php
        Gate::authorize('update', $instrument->schoolClass);
        $this->ensureNotCancelled($instrument);
```

- [ ] **Step 6: Send `status` and `cancellation_reason` from `show()`**

In `app/Http/Controllers/InstrumentController.php::show()`, the `instrument` array currently sent to `Inertia::render` (lines 105-114) has `'status_label' => $instrument->status->label(),` — add two lines right after it:

```php
                'status_label' => $instrument->status->label(),
                'status' => $instrument->status->value,
                'cancellation_reason' => $instrument->cancellation_reason,
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentCancellationTest`
Expected: PASS (7/7).

- [ ] **Step 8: Run the full suite, Larastan, and Pint**

Run: `php artisan test`
Run: `composer types:check`
Run: `composer lint`
Expected: all clean.

- [ ] **Step 9: Commit**

```bash
git add routes/web.php app/Http/Controllers/InstrumentController.php tests/Feature/Assessment/InstrumentCancellationTest.php
git commit -m "feat(instruments): add the reversible cancellation flow"
```

---

### Task 5: Frontend — extract `InstrumentForm.vue`, add `Edit.vue`

**Files:**
- Create: `resources/js/pages/instruments/InstrumentForm.vue`
- Create: `resources/js/pages/instruments/Edit.vue`
- Modify: `resources/js/pages/instruments/Create.vue` (becomes a thin wrapper)

**Interfaces:**
- Consumes: the `edit` route's prop shape from Task 3 (`instrument.items[].{ulid, has_scores}`).

- [ ] **Step 1: Create `InstrumentForm.vue`**

Create `resources/js/pages/instruments/InstrumentForm.vue`:

```vue
<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid?: string;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores?: boolean;
    domains: { domain_id: number; allocation_percent: number }[];
};

type InstrumentData = {
    title: string;
    academic_period_id: number | null;
    instrument_type_id: number | null;
    applied_on: string;
    status: string;
    purpose: string;
    counts_toward_classification: boolean;
    total_points: number | string;
    allow_bonus: boolean;
    items: ItemRow[];
};

const props = defineProps<{
    periods: Option[];
    types: Option[];
    domains: Option[];
    initial?: InstrumentData;
    submitUrl: string;
    method: 'post' | 'put';
}>();

const form = useForm<InstrumentData>(
    props.initial ?? {
        title: '',
        academic_period_id: null,
        instrument_type_id: null,
        applied_on: '',
        status: 'prepared',
        purpose: 'summative',
        counts_toward_classification: true,
        total_points: 100,
        allow_bonus: false,
        items: [{ code: 'Q1', label: '', points_possible: 100, is_bonus: false, domains: [] }],
    },
);

function addItem(): void {
    form.items.push({
        code: `Q${form.items.length + 1}`,
        label: '',
        points_possible: 0,
        is_bonus: false,
        domains: [],
    });
}

function removeItem(index: number): void {
    form.items.splice(index, 1);
}

function addAllocation(item: ItemRow): void {
    item.domains.push({ domain_id: props.domains[0]?.id ?? 0, allocation_percent: 100 });
}

function removeAllocation(item: ItemRow, index: number): void {
    item.domains.splice(index, 1);
}

function allocationTotal(item: ItemRow): number {
    return item.domains.reduce((sum, allocation) => sum + (Number(allocation.allocation_percent) || 0), 0);
}

const itemsTotal = computed(() =>
    form.items.reduce((sum, item) => (item.is_bonus ? sum : sum + (Number(item.points_possible) || 0)), 0),
);

const totalMatches = computed(
    () => form.allow_bonus || Math.abs(itemsTotal.value - Number(form.total_points)) < 0.0001,
);

function submit(): void {
    form.submit(props.method, props.submitUrl, { preserveScroll: true });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="title">Designação</Label>
                <Input id="title" v-model="form.title" placeholder="Teste de Compreensão Leitora" />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="instrument_type_id">Tipo</Label>
                <select id="instrument_type_id" v-model.number="form.instrument_type_id" class="border-input h-9 rounded-md border bg-transparent px-3 text-sm">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="type in types" :key="type.id" :value="type.id">{{ type.label }}</option>
                </select>
                <InputError :message="form.errors.instrument_type_id" />
            </div>
            <div class="grid gap-2">
                <Label for="academic_period_id">Período</Label>
                <select id="academic_period_id" v-model.number="form.academic_period_id" class="border-input h-9 rounded-md border bg-transparent px-3 text-sm">
                    <option :value="null" disabled>Escolher…</option>
                    <option v-for="period in periods" :key="period.id" :value="period.id">{{ period.label }}</option>
                </select>
                <InputError :message="form.errors.academic_period_id" />
            </div>
            <div class="grid gap-2">
                <Label for="applied_on">Data</Label>
                <Input id="applied_on" v-model="form.applied_on" type="date" />
                <InputError :message="form.errors.applied_on" />
            </div>
            <div class="grid gap-2">
                <Label for="total_points">Cotação total</Label>
                <Input id="total_points" v-model.number="form.total_points" type="number" min="0" step="1" />
                <InputError :message="form.errors.total_points" />
            </div>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input v-model="form.counts_toward_classification" type="checkbox" class="size-4" />
                Conta para a classificação
                <span class="text-xs text-muted-foreground">(independente de ser diagnóstico ou sumativo)</span>
            </label>
            <label class="flex items-center gap-2 text-sm sm:col-span-2">
                <input v-model="form.allow_bonus" type="checkbox" class="size-4" />
                Permitir cotações de bónus acima do total
            </label>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-sm font-semibold">Questões</h2>
                    <p class="text-sm text-muted-foreground">
                        A distribuição por domínios de cada questão tem de somar 100% — ou ficar vazia.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" @click="addItem">
                    <Plus class="size-4" /> Adicionar questão
                </Button>
            </div>
            <InputError :message="form.errors.items" />

            <div v-for="(item, index) in form.items" :key="item.ulid ?? index" class="space-y-3 rounded-lg border border-border p-3">
                <div class="grid items-end gap-3 sm:grid-cols-[6rem_1fr_7rem_auto]">
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Código</Label>
                        <Input v-model="item.code" placeholder="Q1" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Enunciado (opcional)</Label>
                        <Input v-model="item.label" placeholder="Compreensão do texto" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label class="text-xs">Cotação</Label>
                        <Input v-model.number="item.points_possible" type="number" min="0" step="0.25" />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.items.length <= 1 || item.has_scores"
                        :title="item.has_scores ? 'Já tem notas lançadas — limpe as notas primeiro para poder remover.' : undefined"
                        @click="removeItem(index)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <div v-if="domains.length" class="space-y-2 border-t border-border pt-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-muted-foreground">
                            Domínios <template v-if="item.domains.length">— total {{ allocationTotal(item) }}%</template>
                        </span>
                        <Button type="button" variant="ghost" size="sm" @click="addAllocation(item)">
                            <Plus class="size-3.5" /> Domínio
                        </Button>
                    </div>
                    <div v-for="(allocation, allocationIndex) in item.domains" :key="allocationIndex" class="flex items-center gap-2">
                        <select v-model.number="allocation.domain_id" class="border-input h-8 flex-1 rounded-md border bg-transparent px-2 text-sm">
                            <option v-for="domain in domains" :key="domain.id" :value="domain.id">{{ domain.label }}</option>
                        </select>
                        <Input v-model.number="allocation.allocation_percent" type="number" min="0" max="100" class="h-8 w-20" />
                        <span class="text-sm text-muted-foreground">%</span>
                        <Button type="button" variant="ghost" size="icon" @click="removeAllocation(item, allocationIndex)">
                            <Trash2 class="size-3.5" />
                        </Button>
                    </div>
                    <p v-if="item.domains.length && Math.abs(allocationTotal(item) - 100) > 0.0001" class="text-xs text-amber-700">
                        Tem de somar 100%.
                    </p>
                </div>
                <p v-else class="text-xs text-muted-foreground">
                    A turma não tem perfil ativo, por isso não há domínios para distribuir.
                </p>
            </div>

            <div
                class="flex items-center justify-between rounded-lg border px-4 py-2.5 text-sm"
                :class="totalMatches ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-amber-300 bg-amber-50 text-amber-900'"
            >
                <span class="font-medium">Soma das cotações</span>
                <span class="font-semibold tabular-nums">
                    {{ itemsTotal }}{{ form.total_points ? ` / ${form.total_points}` : '' }} {{ totalMatches ? '✓' : '' }}
                </span>
            </div>
        </section>

        <Button type="submit" :disabled="form.processing">{{ method === 'post' ? 'Criar instrumento' : 'Guardar alterações' }}</Button>
    </form>
</template>
```

- [ ] **Step 2: Rewrite `Create.vue` as a thin wrapper**

Replace the whole `resources/js/pages/instruments/Create.vue` file with:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

const props = defineProps<{
    schoolClass: { ulid: string; label: string };
    periods: Option[];
    types: Option[];
    domains: Option[];
}>();
</script>

<template>
    <Head title="Novo instrumento" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading :title="`Novo instrumento — ${schoolClass.label}`" description="Questões, cotações e a que domínios pertencem." />
        <InstrumentForm
            :periods="periods"
            :types="types"
            :domains="domains"
            :submit-url="`/classes/${schoolClass.ulid}/instruments`"
            method="post"
        />
    </div>
</template>
```

- [ ] **Step 3: Create `Edit.vue`**

Create `resources/js/pages/instruments/Edit.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import InstrumentForm from './InstrumentForm.vue';

type Option = { id: number; label: string; default_purpose?: string };

type ItemRow = {
    ulid: string;
    code: string;
    label: string;
    points_possible: number;
    is_bonus: boolean;
    has_scores: boolean;
    domains: { domain_id: number; allocation_percent: number }[];
};

const props = defineProps<{
    instrument: {
        ulid: string;
        title: string;
        academic_period_id: number;
        instrument_type_id: number;
        applied_on: string;
        status: string;
        purpose: string;
        counts_toward_classification: boolean;
        total_points: number | null;
        allow_bonus: boolean;
        items: ItemRow[];
    };
    schoolClass: { ulid: string; label: string };
    periods: Option[];
    types: Option[];
    domains: Option[];
}>();

const initial = {
    title: props.instrument.title,
    academic_period_id: props.instrument.academic_period_id,
    instrument_type_id: props.instrument.instrument_type_id,
    applied_on: props.instrument.applied_on,
    status: props.instrument.status,
    purpose: props.instrument.purpose,
    counts_toward_classification: props.instrument.counts_toward_classification,
    total_points: props.instrument.total_points ?? '',
    allow_bonus: props.instrument.allow_bonus,
    items: props.instrument.items,
};
</script>

<template>
    <Head :title="`Editar ${instrument.title}`" />

    <div class="mx-auto w-full max-w-3xl space-y-6 p-4">
        <Heading :title="`Editar ${instrument.title}`" :description="`${schoolClass.label} — questões, cotações e domínios.`" />
        <InstrumentForm
            :periods="periods"
            :types="types"
            :domains="domains"
            :initial="initial"
            :submit-url="`/instruments/${instrument.ulid}`"
            method="put"
        />
    </div>
</template>
```

- [ ] **Step 4: Build and type-check**

Run: `npm run build`
Expected: builds cleanly.

Run: `npm run types:check`
Expected: clean.

- [ ] **Step 5: Manual verification**

1. Start `http://lapis.test`, log in, open a class, create an instrument with 2 questions (as in Create.vue's existing flow — no change there).
2. From the instrument's grading grid — there's no "Editar" link yet (Task 6 adds it) — navigate directly to `/instruments/{ulid}/edit` in the browser address bar.
3. Confirm the form is pre-filled with the instrument's current title, period, type, date, total, and both questions.
4. Change one question's cotação, add a third question, and submit. Confirm it redirects to the grid and the grid now shows 3 questions with the edited value.
5. Go back to `/instruments/{ulid}/edit`, grade one question for one student first (via the grid), then return to edit: confirm that scored question's remove (trash) button is now disabled with a tooltip, while the unscored ones remain removable.

Describe what you actually observed for each of these 5 checks.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/instruments/InstrumentForm.vue resources/js/pages/instruments/Create.vue resources/js/pages/instruments/Edit.vue
git commit -m "feat(instruments): extract InstrumentForm.vue and add the edit page"
```

---

### Task 6: Frontend — `Grid.vue` edit link, cancel dialog, and revert banner

**Files:**
- Modify: `resources/js/pages/instruments/Grid.vue`

**Interfaces:**
- Consumes: `instrument.status`/`instrument.cancellation_reason` from Task 4, `/instruments/{ulid}/edit`, `/instruments/{ulid}/cancel`, `/instruments/{ulid}/revert-cancellation` routes from Tasks 3-4.

- [ ] **Step 1: Add the new props and cancel-dialog state**

In `resources/js/pages/instruments/Grid.vue`, the current import is `import { Head, Link, router } from '@inertiajs/vue3';` (line 2) — `Link` is already there; add `useForm`:

```ts
import { Head, Link, router, useForm } from '@inertiajs/vue3';
```

Add these imports near the existing `Badge`/`Button` imports:

```ts
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
```

Change the `instrument` prop type (currently lines 38-47) from:

```ts
    instrument: {
        ulid: string;
        title: string;
        applied_on: string;
        status_label: string;
        total_points: number | null;
        class_label: string;
        class_ulid: string;
        period: string;
    };
```

to:

```ts
    instrument: {
        ulid: string;
        title: string;
        applied_on: string;
        status: string;
        status_label: string;
        cancellation_reason: string | null;
        total_points: number | null;
        class_label: string;
        class_ulid: string;
        period: string;
    };
```

Add this near the other `const`/`function` declarations (right after `const nonAssessedStates = computed(...)`, currently the last line before `</script>`):

```ts
const isCancelled = computed(() => props.instrument.status === 'cancelled');

const cancelDialogOpen = ref(false);
const cancelForm = useForm<{ reason: string }>({ reason: '' });

function openCancelDialog(): void {
    cancelForm.reset();
    cancelForm.clearErrors();
    cancelDialogOpen.value = true;
}

function submitCancel(): void {
    cancelForm.post(`/instruments/${props.instrument.ulid}/cancel`, {
        preserveScroll: true,
        onSuccess: () => {
            cancelDialogOpen.value = false;
        },
    });
}

function revertCancellation(): void {
    router.post(`/instruments/${props.instrument.ulid}/revert-cancellation`, {}, { preserveScroll: true });
}
```

- [ ] **Step 2: Add the header actions and the cancelled banner**

The current header (lines 219-235) is:

```html
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${instrument.applied_on}`" />
                <Link :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
            <div class="flex items-center gap-3">
                <Badge variant="secondary">{{ instrument.status_label }}</Badge>
                <span v-if="dirtyCount" class="text-sm text-amber-700">
                    {{ dirtyCount }} alteraç{{ dirtyCount === 1 ? 'ão' : 'ões' }} por guardar
                </span>
                <Button :disabled="dirtyCount === 0 || saving" @click="save">
                    <Save class="size-4" /> Guardar
                </Button>
            </div>
        </div>
```

Replace it with:

```html
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <Heading :title="instrument.title" :description="`${instrument.class_label} · ${instrument.period} · ${instrument.applied_on}`" />
                <Link :href="`/classes/${instrument.class_ulid}`" class="text-sm text-muted-foreground hover:underline">
                    ← Voltar à turma
                </Link>
            </div>
            <div class="flex items-center gap-3">
                <Badge variant="secondary">{{ instrument.status_label }}</Badge>
                <template v-if="!isCancelled">
                    <Link :href="`/instruments/${instrument.ulid}/edit`" class="text-sm text-muted-foreground hover:underline">
                        Editar instrumento
                    </Link>
                    <Button type="button" variant="outline" size="sm" @click="openCancelDialog">
                        Anular instrumento
                    </Button>
                    <span v-if="dirtyCount" class="text-sm text-amber-700">
                        {{ dirtyCount }} alteraç{{ dirtyCount === 1 ? 'ão' : 'ões' }} por guardar
                    </span>
                    <Button :disabled="dirtyCount === 0 || saving" @click="save">
                        <Save class="size-4" /> Guardar
                    </Button>
                </template>
            </div>
        </div>

        <div v-if="isCancelled" class="flex items-center justify-between rounded-md border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            <span>Instrumento anulado — motivo: {{ instrument.cancellation_reason }}</span>
            <Button type="button" variant="outline" size="sm" @click="revertCancellation">
                Reverter anulação
            </Button>
        </div>
```

- [ ] **Step 3: Disable score entry while cancelled**

The existing points `<input>` (currently around line 278-283) has:

```html
                                    :disabled="cell(student.enrollment_id, item.id).state !== 'assessed' && cell(student.enrollment_id, item.id).state !== 'pending'"
```

Change it to:

```html
                                    :disabled="isCancelled || (cell(student.enrollment_id, item.id).state !== 'assessed' && cell(student.enrollment_id, item.id).state !== 'pending')"
```

The existing state `<select>` right after it (currently around line 284-289) has no `:disabled` binding at all — add one:

```html
                                <select
                                    :value="cell(student.enrollment_id, item.id).state"
                                    :disabled="isCancelled"
                                    class="h-8 w-14 cursor-pointer rounded border border-input bg-transparent text-xs"
```

- [ ] **Step 4: Add the cancel dialog**

Add this right before the closing `</div>` of the outer `<div class="space-y-4 p-4">` (i.e. as the last child of the page, after the `</div>` that closes the grid table's wrapper, currently right after line 309's closing structure — place it as the final element in the template, a sibling of the grid `<div>`):

```html
        <Dialog v-model:open="cancelDialogOpen">
            <DialogContent>
                <form @submit.prevent="submitCancel">
                    <DialogHeader>
                        <DialogTitle>Anular instrumento</DialogTitle>
                        <DialogDescription>
                            O instrumento deixa de contar para o cálculo e fica só-leitura até reverteres a anulação.
                        </DialogDescription>
                    </DialogHeader>
                    <div class="grid gap-4 py-4">
                        <div class="grid gap-2">
                            <label for="cancel-reason" class="text-sm font-medium">Motivo</label>
                            <textarea
                                id="cancel-reason"
                                v-model="cancelForm.reason"
                                rows="3"
                                class="border-input rounded-md border bg-transparent px-3 py-2 text-sm"
                            ></textarea>
                            <InputError :message="cancelForm.errors.reason" />
                        </div>
                    </div>
                    <DialogFooter>
                        <Button type="submit" :disabled="cancelForm.processing">Anular</Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
```

This uses `InputError`, which `Grid.vue` does not currently import — add it:

```ts
import InputError from '@/components/InputError.vue';
```

- [ ] **Step 5: Build and type-check**

Run: `npm run build`
Expected: builds cleanly.

Run: `npm run types:check`
Expected: clean.

- [ ] **Step 6: Manual verification**

1. Open an instrument's grading grid. Confirm "Editar instrumento" and "Anular instrumento" are both visible, and the status badge shows the normal label (not "Anulado").
2. Click "Anular instrumento", submit with an empty reason: confirm a validation error appears and nothing changes.
3. Submit with a real reason: confirm the page shows an amber banner with that reason and a "Reverter anulação" button, that "Editar instrumento"/"Anular instrumento"/"Guardar" are gone, and that every score input/select in the grid is now disabled (greyed out, not editable).
4. Click "Reverter anulação": confirm the banner disappears, the status badge returns to its original label, and "Editar instrumento"/"Anular instrumento"/"Guardar" reappear with score entry enabled again.
5. Repeat steps 2-4 once more (cancel again) to confirm the cycle genuinely repeats without error.

Describe what you actually observed for each of these 5 checks.

- [ ] **Step 7: Commit**

```bash
git add resources/js/pages/instruments/Grid.vue
git commit -m "feat(instruments): add edit/cancel/revert actions to the grading grid"
```
