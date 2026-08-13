<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ?period= query param instruments.create reads for Avaliações' class
 * picker (§Passo 3, ponto A) — a convenience default, not a new form field.
 */
class InstrumentCreatePeriodPrefillTest extends TestCase
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
    public function it_prefills_the_period_when_it_belongs_to_the_classs_academic_year(): void
    {
        $this->inTenant(function (): void {
            $org = $this->organization;
            $year = AcademicYear::factory()->recycle($org)->create();
            $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
            $subject = Subject::factory()->recycle($org)->create();
            $class = SchoolClass::factory()->recycle($org)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($this->user, ['role' => 'owner']);

            $this->actingAs($this->user)
                ->get("/classes/{$class->ulid}/instruments/create?period={$period->id}")
                ->assertInertia(fn ($page) => $page
                    ->component('instruments/Create')
                    ->where('defaultAcademicPeriodId', $period->id));
        });
    }

    #[Test]
    public function it_ignores_a_period_id_from_a_different_academic_year(): void
    {
        $this->inTenant(function (): void {
            $org = $this->organization;
            $year = AcademicYear::factory()->recycle($org)->create();
            $subject = Subject::factory()->recycle($org)->create();
            $class = SchoolClass::factory()->recycle($org)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($this->user, ['role' => 'owner']);

            // A period from a DIFFERENT academic year — e.g. carried over from
            // an Avaliações filter set while looking at another class.
            $otherYear = AcademicYear::factory()->recycle($org)->create();
            $foreignPeriod = AcademicPeriod::factory()->recycle($org)->for($otherYear)->create();

            $this->actingAs($this->user)
                ->get("/classes/{$class->ulid}/instruments/create?period={$foreignPeriod->id}")
                ->assertInertia(fn ($page) => $page
                    ->component('instruments/Create')
                    ->where('defaultAcademicPeriodId', null));
        });
    }

    #[Test]
    public function it_has_no_default_period_when_none_is_requested(): void
    {
        $this->inTenant(function (): void {
            $org = $this->organization;
            $year = AcademicYear::factory()->recycle($org)->create();
            $subject = Subject::factory()->recycle($org)->create();
            $class = SchoolClass::factory()->recycle($org)->create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
            ]);
            $class->teachers()->attach($this->user, ['role' => 'owner']);

            $this->actingAs($this->user)
                ->get("/classes/{$class->ulid}/instruments/create")
                ->assertInertia(fn ($page) => $page
                    ->component('instruments/Create')
                    ->where('defaultAcademicPeriodId', null));
        });
    }
}
