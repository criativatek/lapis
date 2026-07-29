# Separar a Escala dos Instrumentos da Escala de Classificação Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the assessment profile a second, independent scale field specifically for converting an instrument's own 0-100% result into a qualitative label — separate from `scale_id`, which stays reserved for the class's period/semester classification.

**Architecture:** A new nullable `instrument_scale_id` column on `assessment_profile_versions`, carried through the same create/edit/new-draft-version paths `scale_id` already goes through, exposed via a second selector in `ProfileForm.vue`. `InstrumentController::show()` (built by the already-shipped qualitative-rating feature) switches its one lookup from `profileVersion->scale` to `profileVersion->instrumentScale` — everything else about that feature (band matching, empty-array fallback, the Grid.vue column) is unchanged.

**Tech Stack:** Laravel 13, Inertia 3, Vue 3 + TypeScript, PHPUnit 12.

## Global Constraints

- `scale_id` (period/semester classification) and `instrument_scale_id` (instrument-level qualitative rating) are fully independent — a profile may use different scales for each, or leave the second one unset entirely.
- No new `Scale` row is seeded. Any existing scale (system or custom) is selectable for the new field, exactly like `scale_id` already works — including the already-seeded "Escala 1 a 5".
- `instrument_scale_id` is nullable at every layer (DB column, validation rule, form default) — unset means no instrument-level rating shows (the "—" fallback the qualitative-rating feature already implements for a bandless/absent scale).
- Existing profile versions must be backfilled so `instrument_scale_id` mirrors their current `scale_id` — via a raw `DB::table(...)` update, never through Eloquent. `AssessmentProfileVersion::booted()` throws `FrozenProfileVersionException` on any Eloquent `updating` event once `frozen_at` is set, and the already-active demo profile ("7.º A") must not trip that guard during migration.
- PT-pt copy throughout the UI.

---

### Task 1: Schema and model — `instrument_scale_id`

**Files:**
- Create: `database/migrations/2026_07_29_000200_add_instrument_scale_id_to_assessment_profile_versions_table.php`
- Modify: `app/Models/AssessmentProfileVersion.php`
- Test: `tests/Feature/Assessment/AssessmentProfileVersionInstrumentScaleTest.php` (new)

**Interfaces:**
- Produces: `AssessmentProfileVersion::$instrument_scale_id` (nullable int, mass-assignable) and `AssessmentProfileVersion::instrumentScale(): BelongsTo<Scale>`. Task 2 (ProfileBuilder/controller) and Task 4 (InstrumentController) both consume these directly.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Assessment/AssessmentProfileVersionInstrumentScaleTest.php`:

```php
<?php

namespace Tests\Feature\Assessment;

use App\Models\AssessmentProfileVersion;
use App\Models\Scale;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessmentProfileVersionInstrumentScaleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function instrument_scale_id_is_independent_from_scale_id(): void
    {
        $user = User::factory()->create();

        app(CurrentOrganization::class)->runFor($user->personalOrganization(), function () use ($user) {
            $classificationScale = Scale::where('name', 'Escala 0 a 20')->firstOrFail();
            $instrumentScale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();

            $version = AssessmentProfileVersion::factory()->recycle($user->personalOrganization())->create([
                'scale_id' => $classificationScale->id,
                'instrument_scale_id' => $instrumentScale->id,
            ]);

            $this->assertSame($classificationScale->id, $version->scale->id);
            $this->assertSame($instrumentScale->id, $version->instrumentScale->id);
            $this->assertNotSame($version->scale->id, $version->instrumentScale->id);
        });
    }

    #[Test]
    public function instrument_scale_id_can_be_left_null(): void
    {
        $user = User::factory()->create();

        app(CurrentOrganization::class)->runFor($user->personalOrganization(), function () use ($user) {
            $version = AssessmentProfileVersion::factory()->recycle($user->personalOrganization())->create([
                'instrument_scale_id' => null,
            ]);

            $this->assertNull($version->instrument_scale_id);
            $this->assertNull($version->instrumentScale);
        });
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=AssessmentProfileVersionInstrumentScaleTest`
Expected: FAIL — `instrument_scale_id` is an unknown column and `instrumentScale()` is an undefined method.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_29_000200_add_instrument_scale_id_to_assessment_profile_versions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `scale_id` decides the scale for the class's own period/semester
 * classification. `instrument_scale_id` is a SEPARATE, independent choice:
 * which scale converts an individual instrument's own 0-100% result into a
 * qualitative label — a class using "Escala 0 a 20" for its official grade
 * can still want "Escala 1 a 5"'s bands on each test's result, or vice
 * versa. Nullable: a profile may leave this unset, in which case no
 * instrument-level rating shows (the "—" fallback the qualitative-rating
 * feature already implements for an absent/bandless scale).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_profile_versions', function (Blueprint $table) {
            $table->foreignId('instrument_scale_id')->nullable()->after('scale_id')->constrained('scales')->restrictOnDelete();
        });

        // Backfill every existing version so it keeps showing instrument
        // ratings exactly as it does today (from scale_id) unless someone
        // deliberately changes it later. A raw DB update, not Eloquent:
        // AssessmentProfileVersion::booted() throws on any Eloquent write to
        // an already-frozen (active) version, and this must not trip that.
        DB::table('assessment_profile_versions')->update([
            'instrument_scale_id' => DB::raw('scale_id'),
        ]);
    }

    public function down(): void
    {
        Schema::table('assessment_profile_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('instrument_scale_id');
        });
    }
};
```

- [ ] **Step 4: Add the relation and Fillable entry**

In `app/Models/AssessmentProfileVersion.php`, change the `#[Fillable]` attribute (currently lines 33-37) from:

```php
#[Fillable([
    'assessment_profile_id', 'version_number', 'status', 'scale_id',
    'domain_weight_mode', 'period_result_mode', 'accumulated_mode', 'absence_mode',
    'rounding_mode', 'rounding_scale', 'rounding_stage', 'minimum_rules', 'change_note',
    'created_from_version_id',
])]
```

to:

```php
#[Fillable([
    'assessment_profile_id', 'version_number', 'status', 'scale_id', 'instrument_scale_id',
    'domain_weight_mode', 'period_result_mode', 'accumulated_mode', 'absence_mode',
    'rounding_mode', 'rounding_scale', 'rounding_stage', 'minimum_rules', 'change_note',
    'created_from_version_id',
])]
```

Add this method right after the existing `scale()` relation:

```php
    /**
     * The scale that converts a single instrument's own 0-100% result into a
     * qualitative label — independent of scale(), which is only for the
     * class's period/semester classification. Nullable: a profile may leave
     * this unset, in which case no instrument shows a qualitative rating.
     *
     * @return BelongsTo<Scale, $this>
     */
    public function instrumentScale(): BelongsTo
    {
        return $this->belongsTo(Scale::class, 'instrument_scale_id');
    }
```

- [ ] **Step 5: Run migrations and the tests to verify they pass**

Run: `php artisan migrate`
Run: `php artisan test --filter=AssessmentProfileVersionInstrumentScaleTest`
Expected: PASS (2/2).

- [ ] **Step 6: Manually confirm the backfill on the demo data**

Run: `php artisan tinker --execute='app(App\Support\Tenancy\CurrentOrganization::class)->runFor(App\Models\User::where("email","ana.martins@lapis.test")->first()->personalOrganization() ?? App\Models\Organization::first(), fn() => print(App\Models\AssessmentProfileVersion::first()->instrument_scale_id ?? "NULL"));'`

If this project's local dev database has a demo `AssessmentProfileVersion` row seeded from BEFORE this migration ran, confirm its `instrument_scale_id` now equals its `scale_id` (not null) — this is the actual proof the backfill worked, since a fresh `RefreshDatabase` test starts with an empty table and can't exercise "pre-existing row gets backfilled." If there is no pre-existing data to check (e.g. a completely fresh local database), skip this step and note it in your report — it is not blocking.

- [ ] **Step 7: Run the full suite and Larastan to confirm no regression**

Run: `php artisan test`
Run: `composer types:check`
Expected: both clean.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_29_000200_add_instrument_scale_id_to_assessment_profile_versions_table.php \
  app/Models/AssessmentProfileVersion.php \
  tests/Feature/Assessment/AssessmentProfileVersionInstrumentScaleTest.php
git commit -m "feat(assessment-profiles): add an instrument_scale_id independent of scale_id"
```

---

### Task 2: Backend — carry `instrument_scale_id` through create/update/new-draft-version

**Files:**
- Modify: `app/Services/Assessment/ProfileBuilder.php`
- Modify: `app/Http/Controllers/AssessmentProfileController.php`
- Modify: `app/Http/Requests/AssessmentProfileRequest.php`
- Test: `tests/Feature/Assessment/AssessmentProfileTest.php` (existing file, extend)

**Interfaces:**
- Consumes: `AssessmentProfileVersion::instrumentScale()`/Fillable from Task 1.
- Produces: `ProfileBuilder::create(array $attributes, int $scaleId, ?int $instrumentScaleId, array $domains): AssessmentProfile` and `ProfileBuilder::update(AssessmentProfile $profile, array $attributes, int $scaleId, ?int $instrumentScaleId, array $domains): AssessmentProfile` (both gain a new 3rd parameter, `$domains` shifts to 4th). `POST /assessment-profiles` and `PUT /assessment-profiles/{profile}` both accept an optional `instrument_scale_id` field. Task 3 (`ProfileForm.vue`) sends this field.

- [ ] **Step 1: Write the failing tests**

In `tests/Feature/Assessment/AssessmentProfileTest.php`, change the `payload()` helper (currently lines 56-75) from:

```php
    protected function payload(array $overrides = []): array
    {
        $context = $this->context();

        return array_merge([
            'name' => 'Português – 7.º Ano – Escala 1 a 5',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
            'grade_level' => '7.º',
            'description' => null,
            'scale_id' => $this->scaleId(),
            'domains' => [
                ['name' => 'Oralidade', 'weight' => 20],
                ['name' => 'Leitura', 'weight' => 25],
                ['name' => 'Escrita', 'weight' => 20],
                ['name' => 'Gramática', 'weight' => 15],
                ['name' => 'Educação Literária', 'weight' => 20],
            ],
        ], $overrides);
    }
```

to (adds `instrument_scale_id`, defaulting to `null` so every existing test keeps passing unchanged):

```php
    protected function payload(array $overrides = []): array
    {
        $context = $this->context();

        return array_merge([
            'name' => 'Português – 7.º Ano – Escala 1 a 5',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
            'grade_level' => '7.º',
            'description' => null,
            'scale_id' => $this->scaleId(),
            'instrument_scale_id' => null,
            'domains' => [
                ['name' => 'Oralidade', 'weight' => 20],
                ['name' => 'Leitura', 'weight' => 25],
                ['name' => 'Escrita', 'weight' => 20],
                ['name' => 'Gramática', 'weight' => 15],
                ['name' => 'Educação Literária', 'weight' => 20],
            ],
        ], $overrides);
    }
```

Add these new tests at the end of the class, right before the closing `}`:

```php
    #[Test]
    public function a_profile_can_be_created_with_an_independent_instrument_scale(): void
    {
        $instrumentScaleId = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id,
        );

        $this->actingAs($this->user)
            ->post('/assessment-profiles', $this->payload(['instrument_scale_id' => $instrumentScaleId]))
            ->assertRedirect('/assessment-profiles');

        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();
        $version = $profile->versions()->firstOrFail();

        $this->assertSame($instrumentScaleId, $version->instrument_scale_id);
        $this->assertNotSame($version->scale_id, $version->instrument_scale_id, 'scale_id (Escala 1 a 5) and instrument_scale_id (Escala 0 a 20) must be genuinely different scales here.');
    }

    #[Test]
    public function a_profile_can_be_created_without_an_instrument_scale(): void
    {
        $this->actingAs($this->user)
            ->post('/assessment-profiles', $this->payload(['instrument_scale_id' => null]))
            ->assertRedirect('/assessment-profiles');

        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();
        $this->assertNull($profile->versions()->firstOrFail()->instrument_scale_id);
    }

    #[Test]
    public function editing_an_active_profile_carries_the_instrument_scale_into_the_new_draft(): void
    {
        $instrumentScaleId = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id,
        );

        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload(['instrument_scale_id' => $instrumentScaleId]));
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();
        $this->actingAs($this->user)->post("/assessment-profiles/{$profile->ulid}/activate");

        $editPayload = $this->payload([
            'academic_year_id' => $profile->academic_year_id,
            'subject_id' => $profile->subject_id,
            'instrument_scale_id' => $instrumentScaleId,
        ]);

        $this->actingAs($this->user)->put("/assessment-profiles/{$profile->ulid}", $editPayload)->assertRedirect();

        $profile->refresh();
        $this->assertSame($instrumentScaleId, $profile->draftVersion()->instrument_scale_id);
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=AssessmentProfileTest`
Expected: the 3 new tests FAIL — `instrument_scale_id` is not yet accepted anywhere in the request/builder chain.

- [ ] **Step 3: Add the validation rule**

In `app/Http/Requests/AssessmentProfileRequest.php`, add this line right after the existing `'scale_id' => [...]` rule (currently line 39):

```php
            // Independent of scale_id (period/semester classification) — this
            // one converts an instrument's own 0-100% result into a
            // qualitative label. Nullable: a profile may leave it unset.
            'instrument_scale_id' => ['nullable', new BelongsToCurrentOrganization(Scale::class)],
```

- [ ] **Step 4: Update `ProfileBuilder`**

In `app/Services/Assessment/ProfileBuilder.php`, change `create()` (currently lines 22-38) from:

```php
    public function create(array $attributes, int $scaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($attributes, $scaleId, $domains): AssessmentProfile {
            $profile = AssessmentProfile::create($attributes);

            // organization_id is stamped by the BelongsToOrganization creating
            // hook from the resolved tenant — not passed here (it is not fillable).
            // The calculation-rule defaults are the ones the product owner decided
            // (see docs/adr/0004-pedagogical-calculation-rules.md); they are
            // versioned with the profile and editable per version later.
            $version = $profile->versions()->create([
                'version_number' => 1,
                'status' => ProfileVersionStatus::Draft,
                'scale_id' => $scaleId,
                'domain_weight_mode' => 'must_total_100',
                'period_result_mode' => 'weighted_domain_average',
                'accumulated_mode' => 'all_valid_year_elements',
                'absence_mode' => 'exclude_all_warn',
                'rounding_mode' => 'half_up',
                'rounding_scale' => 0,
                'rounding_stage' => 'final_only',
            ]);

            $this->syncDomains($version, (int) $attributes['subject_id'], $domains);

            return $profile;
        });
    }
```

to:

```php
    public function create(array $attributes, int $scaleId, ?int $instrumentScaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($attributes, $scaleId, $instrumentScaleId, $domains): AssessmentProfile {
            $profile = AssessmentProfile::create($attributes);

            // organization_id is stamped by the BelongsToOrganization creating
            // hook from the resolved tenant — not passed here (it is not fillable).
            // The calculation-rule defaults are the ones the product owner decided
            // (see docs/adr/0004-pedagogical-calculation-rules.md); they are
            // versioned with the profile and editable per version later.
            $version = $profile->versions()->create([
                'version_number' => 1,
                'status' => ProfileVersionStatus::Draft,
                'scale_id' => $scaleId,
                'instrument_scale_id' => $instrumentScaleId,
                'domain_weight_mode' => 'must_total_100',
                'period_result_mode' => 'weighted_domain_average',
                'accumulated_mode' => 'all_valid_year_elements',
                'absence_mode' => 'exclude_all_warn',
                'rounding_mode' => 'half_up',
                'rounding_scale' => 0,
                'rounding_stage' => 'final_only',
            ]);

            $this->syncDomains($version, (int) $attributes['subject_id'], $domains);

            return $profile;
        });
    }
```

Change `update()` (currently lines 47-59) from:

```php
    public function update(AssessmentProfile $profile, array $attributes, int $scaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($profile, $attributes, $scaleId, $domains): AssessmentProfile {
            $profile->update($attributes);

            $draft = $profile->draftVersion() ?? $this->openNewDraft($profile);

            $draft->update(['scale_id' => $scaleId]);
            $draft->domains()->delete();
            $this->syncDomains($draft, (int) $attributes['subject_id'], $domains);

            return $profile->refresh();
        });
    }
```

to:

```php
    public function update(AssessmentProfile $profile, array $attributes, int $scaleId, ?int $instrumentScaleId, array $domains): AssessmentProfile
    {
        return DB::transaction(function () use ($profile, $attributes, $scaleId, $instrumentScaleId, $domains): AssessmentProfile {
            $profile->update($attributes);

            $draft = $profile->draftVersion() ?? $this->openNewDraft($profile);

            $draft->update(['scale_id' => $scaleId, 'instrument_scale_id' => $instrumentScaleId]);
            $draft->domains()->delete();
            $this->syncDomains($draft, (int) $attributes['subject_id'], $domains);

            return $profile->refresh();
        });
    }
```

Change `openNewDraft()` (currently lines 65-79) from:

```php
    protected function openNewDraft(AssessmentProfile $profile): AssessmentProfileVersion
    {
        // Reached only when there is no draft, which for an existing profile means
        // it has been activated — so it always has a current (active) version to
        // carry settings forward from.
        $latest = $profile->versions()->max('version_number') ?? 0;
        $source = $profile->currentVersion;

        return $profile->versions()->create([
            'version_number' => $latest + 1,
            'status' => ProfileVersionStatus::Draft,
            'scale_id' => $source->scale_id,
            'domain_weight_mode' => $source->domain_weight_mode,
            'period_result_mode' => $source->period_result_mode,
            'accumulated_mode' => $source->accumulated_mode,
            'absence_mode' => $source->absence_mode,
            'rounding_mode' => $source->rounding_mode,
            'rounding_scale' => $source->rounding_scale,
            'rounding_stage' => $source->rounding_stage,
            'created_from_version_id' => $source->id,
        ]);
    }
```

to (adds `instrument_scale_id` to what gets carried forward — immediately overwritten by `update()`'s own explicit value right after, same as `scale_id` already is, but correct as a starting point in case a caller ever creates a draft without immediately updating it):

```php
    protected function openNewDraft(AssessmentProfile $profile): AssessmentProfileVersion
    {
        // Reached only when there is no draft, which for an existing profile means
        // it has been activated — so it always has a current (active) version to
        // carry settings forward from.
        $latest = $profile->versions()->max('version_number') ?? 0;
        $source = $profile->currentVersion;

        return $profile->versions()->create([
            'version_number' => $latest + 1,
            'status' => ProfileVersionStatus::Draft,
            'scale_id' => $source->scale_id,
            'instrument_scale_id' => $source->instrument_scale_id,
            'domain_weight_mode' => $source->domain_weight_mode,
            'period_result_mode' => $source->period_result_mode,
            'accumulated_mode' => $source->accumulated_mode,
            'absence_mode' => $source->absence_mode,
            'rounding_mode' => $source->rounding_mode,
            'rounding_scale' => $source->rounding_scale,
            'rounding_stage' => $source->rounding_stage,
            'created_from_version_id' => $source->id,
        ]);
    }
```

- [ ] **Step 5: Update `AssessmentProfileController`**

In `app/Http/Controllers/AssessmentProfileController.php`, change `store()` (currently lines 44-53) from:

```php
    public function store(AssessmentProfileRequest $request): RedirectResponse
    {
        Gate::authorize('create', AssessmentProfile::class);

        $this->builder->create(
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'grade_level', 'description']),
            (int) $request->validated('scale_id'),
            $request->validated('domains'),
        );

        return to_route('assessment-profiles.index');
    }
```

to:

```php
    public function store(AssessmentProfileRequest $request): RedirectResponse
    {
        Gate::authorize('create', AssessmentProfile::class);

        $instrumentScaleId = $request->validated('instrument_scale_id');

        $this->builder->create(
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'grade_level', 'description']),
            (int) $request->validated('scale_id'),
            $instrumentScaleId === null ? null : (int) $instrumentScaleId,
            $request->validated('domains'),
        );

        return to_route('assessment-profiles.index');
    }
```

Change `update()` (currently lines 88-97) the same way:

```php
    public function update(AssessmentProfileRequest $request, AssessmentProfile $assessmentProfile): RedirectResponse
    {
        Gate::authorize('update', $assessmentProfile);

        $instrumentScaleId = $request->validated('instrument_scale_id');

        $this->builder->update(
            $assessmentProfile,
            $request->safe()->only(['name', 'academic_year_id', 'subject_id', 'grade_level', 'description']),
            (int) $request->validated('scale_id'),
            $instrumentScaleId === null ? null : (int) $instrumentScaleId,
            $request->validated('domains'),
        );

        return to_route('assessment-profiles.index');
    }
```

Change `edit()` (currently lines 61-79) to also send `instrument_scale_id` — the `profile` array currently has `'scale_id' => $version?->scale_id,` (around line 71); add this line right after it:

```php
                'instrument_scale_id' => $version?->instrument_scale_id,
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=AssessmentProfileTest`
Expected: PASS (all — the pre-existing tests plus the 3 new ones).

- [ ] **Step 7: Run the full suite, Larastan, and Pint**

Run: `php artisan test`
Run: `composer types:check`
Run: `composer lint`
Expected: all clean.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Assessment/ProfileBuilder.php app/Http/Controllers/AssessmentProfileController.php app/Http/Requests/AssessmentProfileRequest.php tests/Feature/Assessment/AssessmentProfileTest.php
git commit -m "feat(assessment-profiles): carry instrument_scale_id through create/update/new-draft"
```

---

### Task 3: Frontend — a second, independent scale selector

**Files:**
- Modify: `resources/js/pages/assessment-profiles/ProfileForm.vue`
- Modify: `resources/js/pages/assessment-profiles/Edit.vue`

**Interfaces:**
- Consumes: `instrument_scale_id` accepted by `POST /assessment-profiles` and `PUT /assessment-profiles/{profile}` from Task 2; `instrument_scale_id` sent by `edit()` from Task 2.

- [ ] **Step 1: Add the field to `ProfileForm.vue`'s data type and defaults**

In `resources/js/pages/assessment-profiles/ProfileForm.vue`, change the `ProfileData` type (currently lines 29-37) from:

```ts
type ProfileData = {
    name: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_level: string;
    description: string | null;
    scale_id: number | null;
    domains: DomainRow[];
};
```

to:

```ts
type ProfileData = {
    name: string;
    academic_year_id: number | null;
    subject_id: number | null;
    grade_level: string;
    description: string | null;
    scale_id: number | null;
    instrument_scale_id: number | null;
    domains: DomainRow[];
};
```

Change the default form data (currently lines 48-61) from:

```ts
const form = useForm<ProfileData>(
    props.initial ?? {
        name: '',
        academic_year_id: null,
        subject_id: null,
        grade_level: '',
        description: null,
        scale_id: null,
        domains: [
            { name: '', weight: 0 },
            { name: '', weight: 0 },
        ],
    },
);
```

to:

```ts
const form = useForm<ProfileData>(
    props.initial ?? {
        name: '',
        academic_year_id: null,
        subject_id: null,
        grade_level: '',
        description: null,
        scale_id: null,
        instrument_scale_id: null,
        domains: [
            { name: '', weight: 0 },
            { name: '', weight: 0 },
        ],
    },
);
```

- [ ] **Step 2: Add the second selector to the template**

In `resources/js/pages/assessment-profiles/ProfileForm.vue`, the existing `scale_id` field's closing (currently ending around line 220, right before the `</div>` that closes the `sm:grid-cols-2` section, immediately after the `<InputError :message="form.errors.scale_id" />` line) — add this new sibling `<div>` right after that `scale_id` field's own closing `</div>`:

```html
            <div class="grid gap-2 sm:col-span-2">
                <Label for="instrument_scale_id">Escala das apreciações dos instrumentos</Label>
                <select
                    id="instrument_scale_id"
                    v-model.number="form.instrument_scale_id"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm"
                >
                    <option :value="null">Nenhuma (sem apreciação qualitativa por instrumento)</option>
                    <optgroup label="Escalas do sistema">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === true,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scale.label }}
                        </option>
                    </optgroup>
                    <optgroup label="Escalas personalizadas">
                        <option
                            v-for="scale in scales.filter(
                                (item) => item.system === false,
                            )"
                            :key="scale.id"
                            :value="scale.id"
                        >
                            {{ scale.label }}
                        </option>
                    </optgroup>
                </select>
                <InputError :message="form.errors.instrument_scale_id" />
                <p class="text-xs text-muted-foreground">
                    Independente da escala acima — converte a percentagem de cada instrumento (0 a 100%) numa apreciação qualitativa, sem afetar a classificação final do período.
                </p>
            </div>
```

(This is placed as `sm:col-span-2`, its own full-width row below the two-column grid the `scale_id` field sits in, since it needs room for the longer label and the explanatory paragraph — check the surrounding `<section class="grid gap-4 sm:grid-cols-2">` wrapper before placing it, so it renders as a clear, separate row rather than being squeezed into one of the two columns.)

- [ ] **Step 3: Wire `Edit.vue`'s initial data**

In `resources/js/pages/assessment-profiles/Edit.vue`, change the `profile` prop type (currently lines 16-27) from:

```ts
const props = defineProps<{
    profile: {
        ulid: string;
        name: string;
        academic_year_id: number;
        subject_id: number;
        grade_level: string | null;
        description: string | null;
        scale_id: number | null;
        editing_active: boolean;
        domains: DomainRow[];
    };
    academicYears: Option[];
    subjects: Option[];
    scales: Option[];
}>();
```

to:

```ts
const props = defineProps<{
    profile: {
        ulid: string;
        name: string;
        academic_year_id: number;
        subject_id: number;
        grade_level: string | null;
        description: string | null;
        scale_id: number | null;
        instrument_scale_id: number | null;
        editing_active: boolean;
        domains: DomainRow[];
    };
    academicYears: Option[];
    subjects: Option[];
    scales: Option[];
}>();
```

Change the `initial` object (currently lines 33-43) from:

```ts
const initial = {
    name: props.profile.name,
    academic_year_id: props.profile.academic_year_id,
    subject_id: props.profile.subject_id,
    grade_level: props.profile.grade_level ?? '',
    description: props.profile.description,
    scale_id: props.profile.scale_id,
    domains: props.profile.domains.length
        ? props.profile.domains
        : [{ name: '', weight: 0 }],
};
```

to:

```ts
const initial = {
    name: props.profile.name,
    academic_year_id: props.profile.academic_year_id,
    subject_id: props.profile.subject_id,
    grade_level: props.profile.grade_level ?? '',
    description: props.profile.description,
    scale_id: props.profile.scale_id,
    instrument_scale_id: props.profile.instrument_scale_id,
    domains: props.profile.domains.length
        ? props.profile.domains
        : [{ name: '', weight: 0 }],
};
```

`resources/js/pages/assessment-profiles/Create.vue` needs no change — it has no `initial` prop at all, so `ProfileForm.vue`'s own default (`instrument_scale_id: null`) already applies correctly for a brand-new profile.

- [ ] **Step 4: Build and type-check**

Run: `npm run build`
Expected: builds cleanly.

Run: `npm run types:check`
Expected: clean.

- [ ] **Step 5: Manual verification**

1. Start `http://lapis.test`, log in, go to "Perfis de Avaliação" → "Novo perfil de avaliação".
2. Confirm the form now shows TWO scale selectors: the existing "Escala" and the new "Escala das apreciações dos instrumentos" (with its explanatory text), and that the new one defaults to "Nenhuma (sem apreciação qualitativa por instrumento)" — not a disabled placeholder, genuinely selectable/submittable as empty.
3. Pick "Escala 0 a 20" for the first ("Escala") field and "Escala 1 a 5" for the new second field, fill in the rest, and save. Confirm it saves without error.
4. Reopen this profile's edit page: confirm both selectors show the values you picked, independently (not the same value in both).
5. Activate the profile, then edit it again (opens a new draft): confirm the new draft still shows "Escala 1 a 5" pre-selected in the instrument-scale field, carried forward from the active version.

Describe what you actually observed for each of these 5 checks.

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/assessment-profiles/ProfileForm.vue resources/js/pages/assessment-profiles/Edit.vue
git commit -m "feat(assessment-profiles): add the independent instrument-scale selector to the form"
```

---

### Task 4: Rewire the qualitative-rating feature onto `instrument_scale_id`

**Files:**
- Modify: `app/Http/Controllers/InstrumentController.php:104-115`
- Modify: `tests/Feature/Assessment/InstrumentControllerTest.php` (existing file — update, don't just add)

**Interfaces:**
- Consumes: `AssessmentProfileVersion::instrumentScale()` from Task 1.
- No prop-shape change for the frontend: `scaleBands` keeps the exact same `{label, band_min, band_max}[]` shape `Grid.vue` already consumes — only WHERE the backend reads the bands from changes.

- [ ] **Step 1: Update the existing tests first — they currently rely on the OLD source and will start passing for the wrong reason if left as-is**

`tests/Feature/Assessment/InstrumentControllerTest.php` currently has 3 tests that set `scale_id` on the `AssessmentProfileVersion` and expect `scaleBands` to reflect it. Read the file first to confirm its exact current content, then apply these changes:

In `the_grid_receives_scale_bands_when_the_class_profile_has_them`, change:

```php
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create(['scale_id' => $scale->id]);
```

to:

```php
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create(['instrument_scale_id' => $scale->id]);
```

In `the_grid_receives_no_scale_bands_when_the_profile_scale_has_none_configured`, change the same way — find the line creating the version with `['scale_id' => $scale->id]` (the scale with zero levels) and change it to `['instrument_scale_id' => $scale->id]`.

`the_grid_receives_no_scale_bands_when_the_class_has_no_profile` needs no change (it has no version at all).

Add this new test, proving the independence that is the entire point of this plan — place it right after `the_grid_receives_scale_bands_when_the_class_profile_has_them`:

```php
    #[Test]
    public function scale_bands_come_from_instrument_scale_id_not_scale_id(): void
    {
        $this->inTenant(function (): void {
            $classificationScale = Scale::factory()->create(['organization_id' => null]);
            $classificationScale->levels()->create(['code' => 'X', 'label' => 'Não usado aqui', 'sequence' => 1, 'band_min_normalized' => '0', 'band_max_normalized' => '100']);

            $instrumentScale = Scale::factory()->create(['organization_id' => null]);
            $instrumentScale->levels()->create(['code' => '1', 'label' => 'Fraco', 'sequence' => 1, 'band_min_normalized' => '0', 'band_max_normalized' => '49.999999']);
            $instrumentScale->levels()->create(['code' => '2', 'label' => 'Bom', 'sequence' => 2, 'band_min_normalized' => '50', 'band_max_normalized' => '100']);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'scale_id' => $classificationScale->id,
                'instrument_scale_id' => $instrumentScale->id,
            ]);
            ['instrument' => $instrument] = $this->scenario($version);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->has('scaleBands', 2)
                    ->where('scaleBands.0.label', 'Fraco')
                    ->where('scaleBands.1.label', 'Bom'));
        });
    }
```

- [ ] **Step 2: Run the tests to verify the expected failures**

Run: `php artisan test --filter=InstrumentControllerTest`
Expected: the two updated tests and the new test FAIL (the controller still reads `->scale`, not `->instrumentScale`).

- [ ] **Step 3: Implement**

In `app/Http/Controllers/InstrumentController.php`, change the `$scaleBands` block (currently lines 104-115) from:

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

to (only `->scale` becomes `->instrumentScale` — nothing else changes):

```php
        $scaleBands = $instrument->schoolClass->profileVersion?->instrumentScale
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

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php artisan test --filter=InstrumentControllerTest`
Expected: PASS (all — the 3 original plus the 1 new independence test).

- [ ] **Step 5: Run the full suite, Larastan, and Pint**

Run: `php artisan test`
Run: `composer types:check`
Run: `composer lint`
Expected: all clean.

- [ ] **Step 6: Manual verification**

1. On the local site, open a class whose profile now has `instrument_scale_id` set to "Escala 1 a 5" (e.g. the one you configured in Task 3's manual check, or the demo class if its backfilled value survived).
2. Open one of its instruments' grading grid: confirm "Apreciação Qualitativa" still shows correctly (this proves the rewiring didn't break the already-working case).
3. If you have a class whose profile's `instrument_scale_id` is null but whose `scale_id` is NOT null (e.g. a profile created before this plan, if its backfill somehow didn't apply, or a brand new profile where you deliberately left the new field empty): confirm its instruments now correctly show "—" — this is the actual bug this plan fixes; before this change, `scale_id` alone would have made this show ratings even though nobody configured that for instruments.

Describe what you actually observed for both checks.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/InstrumentController.php tests/Feature/Assessment/InstrumentControllerTest.php
git commit -m "fix(instruments): read qualitative-rating bands from instrument_scale_id, not scale_id"
```
