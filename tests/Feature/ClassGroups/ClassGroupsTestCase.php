<?php

namespace Tests\Feature\ClassGroups;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O cenário partilhado por todos os casos dos grupos: um professor, a sua
 * organização, uma turma de 2026/27 e alunos com nome.
 *
 * O RELÓGIO ESTÁ CONGELADO, pela mesma razão que LessonScheduleTest o congela
 * (ver a constante FROZEN_NOW lá): estes casos fixam o ano letivo e datas de
 * entrada em vigor a dias de distância umas das outras, e ligados ao relógio
 * real passariam a contradizer-se no dia em que a data de execução saísse do
 * intervalo escolhido.
 */
abstract class ClassGroupsTestCase extends TestCase
{
    use RefreshDatabase;

    protected const FROZEN_NOW = '2026-10-15 10:00:00';

    protected const TIMEZONE = 'Europe/Lisbon';

    protected const YEAR_STARTS_ON = '2026-09-01';

    protected const YEAR_ENDS_ON = '2027-06-30';

    protected Organization $organization;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse(self::FROZEN_NOW, self::TIMEZONE));

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);
    }

    protected function schoolClassFor(User $teacher, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return $this->inTenant($organization, function () use ($organization, $teacher): SchoolClass {
            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'label' => '8.º F',
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($organization)
                        ->create(['starts_on' => self::YEAR_STARTS_ON, 'ends_on' => self::YEAR_ENDS_ON])
                        ->id,
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    /**
     * @return list<Enrollment>
     */
    protected function enroll(SchoolClass $schoolClass, int $count, string $enrolledOn = self::YEAR_STARTS_ON): array
    {
        $organization = Organization::withoutGlobalScope('organization')->findOrFail($schoolClass->organization_id);

        return $this->inTenant($organization, function () use ($organization, $schoolClass, $count, $enrolledOn): array {
            $enrollments = [];

            for ($number = 1; $number <= $count; $number++) {
                $enrollments[] = Enrollment::factory()
                    ->recycle($organization)
                    ->create([
                        'class_id' => $schoolClass->id,
                        'student_id' => Student::factory()->recycle($organization)->create()->id,
                        'class_number' => $number,
                        'enrolled_on' => $enrolledOn,
                        'status' => 'active',
                    ]);
            }

            return $enrollments;
        });
    }

    protected function subscribeToPro(Organization $organization): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $organization->id)
            ->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::query()->where('key', 'pro')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
        app(Entitlements::class)->flush();
    }

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    protected function asTeacher(?User $user = null): static
    {
        $user ??= $this->teacher;

        return $this->actingAs($user)->withSession(['organization_id' => $this->organization->id]);
    }
}
