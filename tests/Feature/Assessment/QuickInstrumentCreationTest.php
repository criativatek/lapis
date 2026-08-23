<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class QuickInstrumentCreationTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    #[Test]
    public function the_creation_page_defaults_to_quick_mode_and_keeps_the_detailed_mode_available(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());

        $this->actingAs($this->teacher)
            ->get("/classes/{$scenario['class']->ulid}/instruments/create")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Create')
                ->where('defaultCreationMode', 'quick')
                ->where('schoolClass.label', $scenario['class']->label)
                ->has('domains', 1));
    }

    #[Test]
    public function quick_creation_uses_the_canonical_instrument_item_and_allocation_without_scores_or_completion(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $this->quickPayload($scenario))
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($scenario): void {
            $instrument = Instrument::where('class_id', $scenario['class']->id)->sole();
            $item = $instrument->items()->sole();

            $this->assertSame(InstrumentStatus::Prepared, $instrument->status);
            $this->assertNull($instrument->completed_at);
            $this->assertSame('Q1', $item->code);
            $this->assertSame('100.0000', $item->points_possible);
            $this->assertSame(1, $instrument->groups()->count());
            $this->assertNull($instrument->groups()->sole()->label);
            $this->assertDatabaseHas('item_domain_allocations', [
                'instrument_item_id' => $item->id,
                'domain_id' => $scenario['domain']->id,
                'allocation_percent' => 100,
            ]);
            $this->assertSame(0, StudentItemScore::where('instrument_id', $instrument->id)->count());
        });
    }

    #[Test]
    public function quick_creation_supports_three_real_questions_in_one_domain(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenarioWithDomains(1));
        $payload = $this->multiDomainPayload($scenario, [3], [20, 30, 50]);

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($scenario): void {
            $instrument = Instrument::sole();

            $this->assertSame(['Q1', 'Q2', 'Q3'], $instrument->items()->pluck('code')->all());
            $this->assertSame(['20.0000', '30.0000', '50.0000'], $instrument->items()->pluck('points_possible')->all());
            $this->assertSame(1, $instrument->groups()->count());
            $this->assertNull($instrument->groups()->sole()->label);
            $this->assertSame(
                [$scenario['domains'][0]->id, $scenario['domains'][0]->id, $scenario['domains'][0]->id],
                $instrument->items()->with('domainAllocations')->get()
                    ->map(fn ($item) => $item->domainAllocations->sole()->domain_id)->all(),
            );
            $this->assertSame(0, StudentItemScore::count());
            $this->assertSame(InstrumentStatus::Prepared, $instrument->status);
            $this->assertNull($instrument->completed_at);
        });
    }

    #[Test]
    public function quick_creation_supports_five_domains_with_five_questions_each(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenarioWithDomains(5));
        $payload = $this->multiDomainPayload($scenario, [5, 5, 5, 5, 5], array_fill(0, 25, 4));

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($scenario): void {
            $instrument = Instrument::sole();
            $this->assertSame(25, $instrument->items()->count());
            // items() already orders by sequence ascending; Eloquent appends
            // rather than replaces, so orderByDesc('sequence') here would just
            // stack behind that first (fully-ordering) clause and do nothing.
            $this->assertSame('Q25', $instrument->items()->get()->last()->code);

            foreach ($scenario['domains'] as $domain) {
                $this->assertSame(5, $instrument->items()
                    ->whereHas('domainAllocations', fn ($query) => $query->where('domain_id', $domain->id))
                    ->count());
            }
        });
    }

    #[Test]
    public function quick_creation_supports_variable_question_counts_and_derives_domain_weights_from_cotations(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenarioWithDomains(3));
        $points = [5, 5, 10, 5, 5, 5, 5, 5, 5, 20, 15];
        $payload = $this->multiDomainPayload($scenario, [3, 6, 2], $points);

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($scenario): void {
            $instrument = Instrument::sole();
            $domainPoints = $instrument->items()->with('domainAllocations')->get()
                ->groupBy(fn ($item) => $item->domainAllocations->sole()->domain_id)
                ->map(fn ($items) => (float) $items->sum('points_possible'));

            $this->assertSame(20.0, $domainPoints[$scenario['domains'][0]->id]);
            $this->assertSame(30.0, $domainPoints[$scenario['domains'][1]->id]);
            $this->assertSame(35.0, $domainPoints[$scenario['domains'][2]->id]);
            $this->assertSame(85.0, (float) $instrument->total_points);
        });
    }

    #[Test]
    public function quick_creation_persists_a_seventy_thirty_multi_domain_question(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenarioWithDomains(2));
        $payload = $this->multiDomainPayload($scenario, [1, 1], [60, 40]);
        $payload['items'][0]['domains'] = [
            ['domain_id' => $scenario['domains'][0]->id, 'allocation_percent' => 70],
            ['domain_id' => $scenario['domains'][1]->id, 'allocation_percent' => 30],
        ];

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $allocations = Instrument::sole()->items()->where('code', 'Q1')->sole()
                ->domainAllocations()->orderBy('allocation_percent', 'desc')->pluck('allocation_percent')->all();

            $this->assertSame(['70.0000', '30.0000'], $allocations);
        });
    }

    #[Test]
    public function quick_creation_rejects_allocation_totals_above_and_below_one_hundred(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenarioWithDomains(2));

        foreach ([[60, 50], [70, 20]] as $percentages) {
            $payload = $this->multiDomainPayload($scenario, [1, 1], [50, 50]);
            $payload['items'][0]['domains'] = [
                ['domain_id' => $scenario['domains'][0]->id, 'allocation_percent' => $percentages[0]],
                ['domain_id' => $scenario['domains'][1]->id, 'allocation_percent' => $percentages[1]],
            ];

            $this->actingAs($this->teacher)
                ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
                ->assertSessionHasErrors('items');
        }

        $this->inTenant(fn () => $this->assertSame(0, Instrument::count()));
    }

    #[Test]
    public function a_quickly_created_element_opens_and_edits_in_the_normal_detailed_flow(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());
        $this->actingAs($this->teacher)->post(
            "/classes/{$scenario['class']->ulid}/instruments",
            $this->quickPayload($scenario),
        );
        $instrument = $this->inTenant(fn (): Instrument => Instrument::where('class_id', $scenario['class']->id)->sole());

        $this->actingAs($this->teacher)
            ->get("/instruments/{$instrument->ulid}/edit")
            ->assertInertia(fn ($page) => $page
                ->component('instruments/Edit')
                ->where('instrument.ulid', $instrument->ulid)
                ->where('instrument.items.0.domains.0.allocation_percent', 100));

        $item = $this->inTenant(fn () => $instrument->items()->sole());
        $group = $this->inTenant(fn () => $instrument->groups()->sole());
        $payload = $this->quickPayload($scenario);
        $payload['title'] = 'Ficha rápida editada';
        $payload['groups'][0]['ulid'] = $group->ulid;
        $payload['items'][0]['ulid'] = $item->ulid;

        $this->actingAs($this->teacher)
            ->put("/instruments/{$instrument->ulid}", $payload)
            ->assertRedirect(route('instruments.show', $instrument->ulid));

        $this->inTenant(fn () => $this->assertSame('Ficha rápida editada', $instrument->fresh()->title));

        $this->inTenant(function () use ($instrument, $item, $scenario): void {
            $enrollment = Enrollment::factory()->recycle($this->organization)->create([
                'class_id' => $scenario['class']->id,
                'enrolled_on' => '2026-09-01',
            ]);
            StudentItemScore::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'instrument_item_id' => $item->id,
                'enrollment_id' => $enrollment->id,
                'result_state' => 'assessed',
                'points_earned' => 75,
            ]);
            $instrument->update(['status' => InstrumentStatus::InCorrection]);
        });

        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/complete")->assertSessionHasNoErrors();
        $this->actingAs($this->teacher)->post("/instruments/{$instrument->ulid}/reopen")->assertSessionHasNoErrors();
        $this->inTenant(fn () => $this->assertSame(InstrumentStatus::InCorrection, $instrument->fresh()->status));
    }

    #[Test]
    public function detailed_creation_preserves_multiple_domain_allocations(): void
    {
        $scenario = $this->inTenant(function (): array {
            $scenario = $this->scenario();
            $secondDomain = Domain::factory()->recycle($this->organization)->create();
            $scenario['class']->profileVersion->domains()->create([
                'domain_id' => $secondDomain->id,
                'weight_percent' => 0,
                'sequence' => 2,
            ]);
            $scenario['secondDomain'] = $secondDomain;

            return $scenario;
        });
        $payload = $this->quickPayload($scenario);
        $payload['quick'] = false;
        $payload['items'][0]['domains'] = [
            ['domain_id' => $scenario['domain']->id, 'allocation_percent' => 60],
            ['domain_id' => $scenario['secondDomain']->id, 'allocation_percent' => 40],
        ];

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($scenario): void {
            $allocations = Instrument::sole()->items()->sole()->domainAllocations()->orderBy('domain_id')->get();
            $this->assertCount(2, $allocations);
            $this->assertEqualsCanonicalizing([60.0, 40.0], $allocations->pluck('allocation_percent')->map(fn ($value) => (float) $value)->all());
            $this->assertEqualsCanonicalizing(
                [$scenario['domain']->id, $scenario['secondDomain']->id],
                $allocations->pluck('domain_id')->all(),
            );
        });
    }

    #[Test]
    public function a_teacher_not_assigned_to_the_class_cannot_use_quick_creation_even_while_impersonated(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());
        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);

        $this->withSession([
            'organization_id' => $this->organization->id,
            'impersonator_id' => $this->teacher->id,
        ])->actingAs($colleague)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $this->quickPayload($scenario))
            ->assertForbidden();
    }

    #[Test]
    public function quick_creation_rejects_a_domain_period_and_custom_type_from_another_tenant(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());
        $outsider = User::factory()->create();
        $foreign = app(CurrentOrganization::class)->runFor($outsider->personalOrganization(), function () use ($outsider): array {
            $year = AcademicYear::factory()->recycle($outsider->personalOrganization())->create();

            return [
                'period' => AcademicPeriod::factory()->recycle($outsider->personalOrganization())->for($year)->create(),
                'domain' => Domain::factory()->recycle($outsider->personalOrganization())->create(),
                'type' => InstrumentType::create([
                    'name' => 'Tipo alheio',
                    'code' => 'FOREIGN',
                    'default_purpose' => 'summative',
                    'is_active' => true,
                ]),
            ];
        });

        $payload = $this->quickPayload($scenario);
        $payload['academic_period_id'] = $foreign['period']->id;
        $payload['instrument_type_id'] = $foreign['type']->id;
        $payload['items'][0]['domains'][0]['domain_id'] = $foreign['domain']->id;

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasErrors([
                'academic_period_id',
                'instrument_type_id',
                'items.0.domains.0.domain_id',
            ]);

        $this->inTenant(fn () => $this->assertSame(0, Instrument::count()));
    }

    #[Test]
    public function quick_creation_rejects_same_tenant_periods_and_domains_outside_the_class_context(): void
    {
        $scenario = $this->inTenant(function (): array {
            $scenario = $this->scenario();
            $otherYear = AcademicYear::factory()->recycle($this->organization)->create();
            $scenario['otherPeriod'] = AcademicPeriod::factory()->recycle($this->organization)->for($otherYear)->create();
            $scenario['otherDomain'] = Domain::factory()->recycle($this->organization)->create();

            return $scenario;
        });
        $payload = $this->quickPayload($scenario);
        $payload['academic_period_id'] = $scenario['otherPeriod']->id;
        $payload['items'][0]['domains'][0]['domain_id'] = $scenario['otherDomain']->id;

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasErrors(['academic_period_id', 'items.0.domains.0.domain_id']);

        $this->inTenant(fn () => $this->assertSame(0, Instrument::count()));
    }

    #[Test]
    public function a_tampered_quick_payload_cannot_smuggle_detailed_structure(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());
        $payload = $this->quickPayload($scenario);
        $payload['status'] = 'completed';
        $payload['total_points'] = 50;
        $payload['allow_bonus'] = true;
        $payload['groups'] = [['label' => 'Grupo I'], ['label' => 'Grupo II']];
        $payload['items'][0]['code'] = 'Q9';
        $payload['items'][0]['points_possible'] = 50;
        $payload['items'][0]['is_bonus'] = true;
        $payload['items'][0]['domains'][0]['allocation_percent'] = 50;
        $payload['items'][] = $payload['items'][0];

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasErrors([
                // status/allow_bonus/is_bonus/groups stay rejected outright.
                'status',
                'allow_bonus',
                'groups',
                'groups.0.label',
                'items.0.is_bonus',
                // total_points, items.*.points_possible and allocation_percent
                // are no longer pinned to fixed values — Fatia H.2 lets simple
                // creation carry real points and per-domain percentages, so
                // 50/50 alone isn't tampering. What still catches this payload:
                // item 0's code no longer matches its required Q1 position.
                'items.0.code',
            ]);

        $this->inTenant(fn () => $this->assertSame(0, Instrument::count()));
    }

    #[Test]
    public function malformed_or_null_quick_structure_is_rejected_without_creating_an_element(): void
    {
        $scenario = $this->inTenant(fn (): array => $this->scenario());
        $payload = $this->quickPayload($scenario);
        $payload['total_points'] = null;
        $payload['items'] = ['malformed'];

        $this->actingAs($this->teacher)
            ->post("/classes/{$scenario['class']->ulid}/instruments", $payload)
            ->assertSessionHasErrors(['total_points', 'items.0']);

        $this->inTenant(fn () => $this->assertSame(0, Instrument::count()));
    }

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod, domain: Domain, type: InstrumentType}
     */
    protected function scenario(): array
    {
        $year = AcademicYear::factory()->recycle($this->organization)->create();
        $period = AcademicPeriod::factory()->recycle($this->organization)->for($year)->create();
        $subject = Subject::factory()->recycle($this->organization)->create();
        $domain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $subject->id]);
        $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create();
        $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);
        $class = SchoolClass::factory()->recycle($this->organization)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
            'assessment_profile_version_id' => $version->id,
        ]);
        $class->teachers()->attach($this->teacher, ['role' => 'owner']);
        $type = InstrumentType::where('code', 'TEST')->firstOrFail();

        return compact('class', 'period', 'domain', 'type');
    }

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod, domain: Domain, domains: list<Domain>, type: InstrumentType}
     */
    protected function scenarioWithDomains(int $count): array
    {
        $scenario = $this->scenario();
        $domains = [$scenario['domain']];

        for ($index = 1; $index < $count; $index++) {
            $domain = Domain::factory()->recycle($this->organization)->create([
                'subject_id' => $scenario['class']->subject_id,
                'name' => 'Domínio '.($index + 1),
            ]);
            $scenario['class']->profileVersion->domains()->create([
                'domain_id' => $domain->id,
                'weight_percent' => 0,
                'sequence' => $index + 1,
            ]);
            $domains[] = $domain;
        }

        return [...$scenario, 'domains' => $domains];
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod, domains: list<Domain>, type: InstrumentType}  $scenario
     * @param  list<int>  $counts
     * @param  list<int|float>  $points
     * @return array<string, mixed>
     */
    protected function multiDomainPayload(array $scenario, array $counts, array $points): array
    {
        $payload = $this->quickPayload($scenario);
        $payload['items'] = [];
        $pointIndex = 0;

        foreach ($counts as $domainIndex => $count) {
            for ($question = 0; $question < $count; $question++) {
                $payload['items'][] = [
                    'group_index' => 0,
                    'code' => 'Q'.($pointIndex + 1),
                    'label' => null,
                    'points_possible' => $points[$pointIndex],
                    'is_bonus' => false,
                    'domains' => [[
                        'domain_id' => $scenario['domains'][$domainIndex]->id,
                        'allocation_percent' => 100,
                    ]],
                ];
                $pointIndex++;
            }
        }

        $payload['total_points'] = array_sum($points);

        return $payload;
    }

    /**
     * @param  array{class: SchoolClass, period: AcademicPeriod, domain: Domain, type: InstrumentType}  $scenario
     * @return array<string, mixed>
     */
    protected function quickPayload(array $scenario): array
    {
        return [
            'quick' => true,
            'title' => 'Ficha rápida',
            'academic_period_id' => $scenario['period']->id,
            'instrument_type_id' => $scenario['type']->id,
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'purpose' => 'summative',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'groups' => [['label' => null]],
            'items' => [[
                'code' => 'Q1',
                'label' => null,
                'points_possible' => 100,
                'is_bonus' => false,
                'domains' => [[
                    'domain_id' => $scenario['domain']->id,
                    'allocation_percent' => 100,
                ]],
            ]],
        ];
    }

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
