<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\InstrumentItem;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Support\Assessment\InstrumentValidationException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentBuilderTest extends TestCase
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

    protected function schoolClass(): SchoolClass
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();

        return SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function attributes(SchoolClass $class, array $overrides = []): array
    {
        return array_merge([
            'academic_period_id' => AcademicPeriod::where('academic_year_id', $class->academic_year_id)->firstOrFail()->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Teste de Compreensão Leitora',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], $overrides);
    }

    #[Test]
    public function it_creates_an_instrument_with_items(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);

            $this->assertSame(2, $instrument->items()->count());
            $this->assertSame('100.0000', $instrument->itemPointsTotal());
        });
    }

    #[Test]
    public function a_question_can_be_split_across_two_domains(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $leitura = Domain::factory()->recycle($this->organization)->create(['name' => 'Leitura']);
            $escrita = Domain::factory()->recycle($this->organization)->create(['name' => 'Escrita']);

            // Scenario A2: one question shared 60/40 between two domains.
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100, 'domains' => [
                    ['domain_id' => $leitura->id, 'allocation_percent' => 60],
                    ['domain_id' => $escrita->id, 'allocation_percent' => 40],
                ]],
            ]);

            $allocations = $instrument->items()->firstOrFail()->domainAllocations;

            $this->assertCount(2, $allocations);
            $this->assertSame('60.0000', $allocations->firstWhere('domain_id', $leitura->id)->allocation_percent);
            $this->assertSame('40.0000', $allocations->firstWhere('domain_id', $escrita->id)->allocation_percent);
        });
    }

    #[Test]
    public function domain_allocations_must_total_100_per_item(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $domain = Domain::factory()->recycle($this->organization)->create();

            $this->expectException(InstrumentValidationException::class);

            app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100, 'domains' => [
                    ['domain_id' => $domain->id, 'allocation_percent' => 70], // only 70
                ]],
            ]);
        });
    }

    #[Test]
    public function an_item_with_no_domain_allocation_is_allowed(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            // Legitimate: a "presentation" question may belong to no domain. It
            // counts toward the instrument total only (§4.3).
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertCount(0, $instrument->items()->firstOrFail()->domainAllocations);
        });
    }

    #[Test]
    public function item_points_must_match_the_declared_total(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $this->expectException(InstrumentValidationException::class);

            app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 30], // 90, not 100
            ]);
        });
    }

    #[Test]
    public function bonus_points_are_allowed_when_explicitly_enabled(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            // §12.3: scores above the total only with an explicit bonus option.
            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['allow_bonus' => true]),
                [
                    ['code' => 'Q1', 'points_possible' => 100],
                    ['code' => 'B1', 'points_possible' => 10, 'is_bonus' => true],
                ],
            );

            // The bonus item exists but stays out of the denominator.
            $this->assertSame(2, $instrument->items()->count());
            $this->assertSame('100.0000', $instrument->itemPointsTotal());
        });
    }

    #[Test]
    public function an_instrument_needs_at_least_one_item(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $this->expectException(InstrumentValidationException::class);

            app(InstrumentBuilder::class)->create($class, $this->attributes($class), []);
        });
    }

    #[Test]
    public function a_diagnostic_instrument_can_still_count_if_the_teacher_says_so(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            // The three axes are independent: purpose is a label, and it must not
            // decide whether the instrument counts (menus §6).
            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['purpose' => 'diagnostic', 'counts_toward_classification' => true]),
                [['code' => 'Q1', 'points_possible' => 100]],
            );

            $this->assertSame('diagnostic', $instrument->purpose);
            $this->assertTrue($instrument->entersCalculation());
        });
    }

    #[Test]
    public function a_diagnostic_instrument_defaults_to_not_counting_when_the_field_is_not_provided(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $attributes = $this->attributes($class, ['purpose' => 'diagnostic']);
            unset($attributes['counts_toward_classification']);

            $instrument = app(InstrumentBuilder::class)->create($class, $attributes, [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertFalse($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function a_diagnostic_instrument_stays_false_when_explicitly_set_false(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['purpose' => 'diagnostic', 'counts_toward_classification' => false]),
                [['code' => 'Q1', 'points_possible' => 100]],
            );

            $this->assertFalse($instrument->counts_toward_classification);
        });
    }

    #[Test]
    public function a_non_diagnostic_instrument_is_unaffected_by_the_diagnostic_default_even_when_the_field_is_absent(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $attributes = $this->attributes($class, ['purpose' => 'summative']);
            unset($attributes['counts_toward_classification']);

            // No InstrumentBuilder default applies here — this falls through to
            // the instruments table's own column default (true). Eloquent's
            // in-memory model never learns of a DB-applied default on its own,
            // so this reads back the persisted row rather than the object
            // create() returned.
            $instrument = app(InstrumentBuilder::class)->create($class, $attributes, [
                ['code' => 'Q1', 'points_possible' => 100],
            ]);

            $this->assertTrue($instrument->fresh()->counts_toward_classification);
        });
    }

    #[Test]
    public function updating_to_diagnostic_purpose_never_silently_changes_an_already_persisted_counts_value(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['purpose' => 'summative', 'counts_toward_classification' => true]),
                [['code' => 'Q1', 'points_possible' => 100]],
            );
            $existing = $instrument->items()->firstOrFail();

            // The diagnostic default (create() only) must never reach here —
            // update() always respects exactly what it is given.
            app(InstrumentBuilder::class)->update(
                $instrument,
                $this->attributes($class, ['purpose' => 'diagnostic', 'counts_toward_classification' => true]),
                [['ulid' => $existing->ulid, 'code' => 'Q1', 'points_possible' => 100]],
            );

            $this->assertTrue($instrument->fresh()->counts_toward_classification);
        });
    }

    #[Test]
    public function a_draft_or_cancelled_instrument_never_enters_the_calculation(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();

            $draft = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['status' => 'draft', 'title' => 'Rascunho']),
                [['code' => 'Q1', 'points_possible' => 100]],
            );

            $this->assertFalse($draft->entersCalculation());

            // But one being marked right now DOES enter, with what it has so far.
            $inCorrection = app(InstrumentBuilder::class)->create(
                $class,
                $this->attributes($class, ['status' => 'in_correction', 'title' => 'Em correção']),
                [['code' => 'Q1', 'points_possible' => 100]],
            );

            $this->assertTrue($inCorrection->entersCalculation());
        });
    }

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

    #[Test]
    public function swapping_two_existing_items_codes_in_the_same_update_succeeds(): void
    {
        $this->inTenant(function (): void {
            $class = $this->schoolClass();
            $instrument = app(InstrumentBuilder::class)->create($class, $this->attributes($class), [
                ['code' => 'Q1', 'points_possible' => 60],
                ['code' => 'Q2', 'points_possible' => 40],
            ]);
            $q1 = $instrument->items()->where('code', 'Q1')->firstOrFail();
            $q2 = $instrument->items()->where('code', 'Q2')->firstOrFail();

            app(InstrumentBuilder::class)->update($instrument, $this->attributes($class), [
                ['ulid' => $q1->ulid, 'code' => 'Q2', 'points_possible' => 60],
                ['ulid' => $q2->ulid, 'code' => 'Q1', 'points_possible' => 40],
            ]);

            $this->assertSame('Q2', $q1->fresh()->code);
            $this->assertSame('Q1', $q2->fresh()->code);
        });
    }
}
