<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Instrument;
use App\Models\InstrumentItem;
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
            $scale->levels()->create(['code' => '1', 'label' => 'Fraco', 'sequence' => 1, 'is_negative' => true, 'band_min_normalized' => '0', 'band_max_normalized' => '19.499999']);
            $scale->levels()->create(['code' => '2', 'label' => 'Insuficiente', 'sequence' => 2, 'is_negative' => true, 'band_min_normalized' => '19.5', 'band_max_normalized' => '49.499999']);

            $version = AssessmentProfileVersion::factory()->recycle($this->organization)->create(['scale_id' => $scale->id]);
            ['instrument' => $instrument] = $this->scenario($version);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->has('scaleBands', 2)
                    ->where('scaleBands.0.label', 'Fraco')
                    ->where('scaleBands.0.band_min', '0.000000')
                    ->where('scaleBands.0.band_max', '19.499999')
                    // sequence and is_negative travel with every band so the
                    // grid can colour it structurally, never by matching its
                    // label text (§ qualitative tone — Grid.vue domain badges).
                    ->where('scaleBands.0.sequence', 1)
                    ->where('scaleBands.0.is_negative', true)
                    ->where('scaleBands.1.label', 'Insuficiente')
                    ->where('scaleBands.1.sequence', 2)
                    ->where('scaleBands.1.is_negative', true));
        });
    }

    #[Test]
    public function the_grid_receives_each_items_domain_allocations_with_a_stable_domain_id(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'instrument' => $instrument] = $this->scenario();
            $domain = Domain::factory()->recycle($this->organization)->create(['name' => 'Leitura']);
            $item = InstrumentItem::factory()->recycle($this->organization)->create([
                'instrument_id' => $instrument->id,
                'points_possible' => 100,
            ]);
            $item->domainAllocations()->create(['domain_id' => $domain->id, 'allocation_percent' => 100]);

            $this->actingAs($this->user)
                ->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn ($page) => $page
                    ->where('items.0.domains.0.domain_id', $domain->id)
                    ->where('items.0.domains.0.name', 'Leitura')
                    ->where('items.0.domains.0.percent', 100));
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
