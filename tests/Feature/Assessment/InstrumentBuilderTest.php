<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Domain;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
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
}
