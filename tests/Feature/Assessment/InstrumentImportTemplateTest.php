<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
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
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
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

            // The destination class must track the same domain for the
            // allocation to survive import — give it a profile version that does.
            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create();
            $version->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);
            $targetClass = $this->makeClass($subject);
            $targetClass->update(['assessment_profile_version_id' => $version->id]);

            $this->actingAs($this->user)
                ->get("/classes/{$targetClass->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page
                    ->has('importableInstruments', 1)
                    ->where('importableInstruments.0.ulid', $source->ulid)
                    ->where('importableInstruments.0.title', 'Teste original')
                    // Whole-number floats (100.0) round-trip through JSON as
                    // plain integers (PHP's json_encode drops the trailing
                    // ".0"), so the assertion compares against the int the
                    // client actually receives rather than a float literal.
                    ->where('importableInstruments.0.total_points', 100)
                    ->has('importableInstruments.0.items', 2)
                    ->where('importableInstruments.0.items.0.code', 'Q1')
                    ->where('importableInstruments.0.items.0.domains.0.domain_id', $domain->id)
                    ->where('importableInstruments.0.items.0.domains.0.allocation_percent', 100));
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
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
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
    public function a_domain_allocation_the_destination_class_does_not_track_is_dropped_on_import(): void
    {
        $this->inTenant(function (): void {
            $subject = Subject::factory()->recycle($this->organization)->create();
            $sourceDomain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $subject->id]);
            $destinationDomain = Domain::factory()->recycle($this->organization)->create(['subject_id' => $subject->id]);

            $sourceClass = $this->makeClass($subject);
            $period = AcademicPeriod::factory()->recycle($this->organization)->for($sourceClass->academicYear)->create();

            app(InstrumentBuilder::class)->create($sourceClass, [
                'academic_period_id' => $period->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Teste de outro ano',
                'applied_on' => '2026-10-15',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [
                ['code' => 'Q1', 'points_possible' => 100, 'domains' => [['domain_id' => $sourceDomain->id, 'allocation_percent' => 100]]],
            ]);

            // Same subject as the source class, but a DIFFERENT profile version
            // tracking a different domain — same subject_id alone does not
            // guarantee the two classes assess the same domains (e.g. different
            // grade levels or academic years under separate profile versions).
            $destinationVersion = AssessmentProfileVersion::factory()->recycle($this->organization)->create();
            $destinationVersion->domains()->create(['domain_id' => $destinationDomain->id, 'weight_percent' => 100, 'sequence' => 1]);
            $targetClass = $this->makeClass($subject);
            $targetClass->update(['assessment_profile_version_id' => $destinationVersion->id]);

            $this->actingAs($this->user)
                ->get("/classes/{$targetClass->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page
                    ->has('importableInstruments', 1)
                    ->where('importableInstruments.0.items.0.code', 'Q1')
                    ->where('importableInstruments.0.items.0.domains', []));
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
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
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
