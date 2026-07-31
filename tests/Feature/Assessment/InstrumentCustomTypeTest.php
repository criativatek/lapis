<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InstrumentCustomTypeTest extends TestCase
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

    /**
     * @return array{class: SchoolClass, period: AcademicPeriod}
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

        return ['class' => $class, 'period' => $period];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(AcademicPeriod $period, array $overrides = []): array
    {
        return array_merge([
            'title' => 'Teste',
            'academic_period_id' => $period->id,
            'instrument_type_id' => 0,
            'custom_instrument_type_name' => 'Portfólio Digital',
            'applied_on' => '2026-10-15',
            'status' => 'prepared',
            'purpose' => 'summative',
            'counts_toward_classification' => true,
            'total_points' => 100,
            'allow_bonus' => false,
            'items' => [
                ['code' => 'Q1', 'points_possible' => 100],
            ],
        ], $overrides);
    }

    #[Test]
    public function selecting_outro_creates_a_new_organization_scoped_instrument_type(): void
    {
        ['class' => $class, 'period' => $period] = $this->scenario();

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/instruments", $this->payload($period))
            ->assertRedirect();

        $type = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => InstrumentType::where('organization_id', $this->organization->id)->where('code', 'PORTFOLIO_DIGITAL')->first(),
        );

        $this->assertNotNull($type);
        $this->assertSame('Portfólio Digital', $type->name);

        $instrument = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => $class->instruments()->firstOrFail(),
        );
        $this->assertSame($type->id, $instrument->instrument_type_id);
    }

    #[Test]
    public function reusing_the_same_custom_name_reuses_the_same_instrument_type(): void
    {
        ['class' => $class, 'period' => $period] = $this->scenario();

        $this->actingAs($this->user)->post("/classes/{$class->ulid}/instruments", $this->payload($period, ['title' => 'Primeiro']));
        $this->actingAs($this->user)->post("/classes/{$class->ulid}/instruments", $this->payload($period, ['title' => 'Segundo']));

        $count = app(CurrentOrganization::class)->runFor(
            $this->organization,
            fn () => InstrumentType::where('organization_id', $this->organization->id)->where('code', 'PORTFOLIO_DIGITAL')->count(),
        );

        $this->assertSame(1, $count, 'A second instrument with the same custom type name must not create a duplicate InstrumentType.');
    }

    #[Test]
    public function outro_without_a_name_fails_validation(): void
    {
        ['class' => $class, 'period' => $period] = $this->scenario();

        $this->actingAs($this->user)
            ->post("/classes/{$class->ulid}/instruments", $this->payload($period, ['custom_instrument_type_name' => null]))
            ->assertSessionHasErrors('custom_instrument_type_name');
    }
}
