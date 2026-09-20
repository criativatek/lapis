<?php

namespace Tests\Feature\SubjectParticipation;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * O cenário partilhado pelos casos de frequência da disciplina: um professor,
 * a sua organização, uma turma de 2026/27 e alunos com nome — o mesmo cenário
 * que ClassGroupsTestCase monta para os grupos, porque a pergunta de fundo é a
 * mesma («que registo vale nesta data, para esta inscrição»).
 *
 * O RELÓGIO ESTÁ CONGELADO pela mesma razão que ClassGroupsTestCase o congela.
 */
abstract class SubjectParticipationTestCase extends TestCase
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

    protected function schoolClassFor(?Subject $subject = null, string $label = '8.º F'): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($subject, $label): SchoolClass {
            return SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'label' => $label,
                    'subject_id' => ($subject ?? Subject::factory()->recycle($this->organization)->create())->id,
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($this->organization)
                        ->create(['starts_on' => self::YEAR_STARTS_ON, 'ends_on' => self::YEAR_ENDS_ON])
                        ->id,
                ]);
        });
    }

    /**
     * A mesma turma (label), com outra disciplina — «8.º F · PLNM» ao lado de
     * «8.º F · Português» — para provar que uma janela de não-frequência
     * numa não toca a outra.
     */
    protected function sameLabelDifferentSubject(SchoolClass $schoolClass): SchoolClass
    {
        return $this->inTenant($this->organization, function () use ($schoolClass): SchoolClass {
            return SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'label' => $schoolClass->label,
                    'academic_year_id' => $schoolClass->academic_year_id,
                    'subject_id' => Subject::factory()->recycle($this->organization)->create()->id,
                ]);
        });
    }

    protected function enrollStudent(SchoolClass $schoolClass, string $enrolledOn = self::YEAR_STARTS_ON): Enrollment
    {
        return $this->inTenant($this->organization, function () use ($schoolClass, $enrolledOn): Enrollment {
            return Enrollment::factory()
                ->recycle($this->organization)
                ->create([
                    'class_id' => $schoolClass->id,
                    'student_id' => Student::factory()->recycle($this->organization)->create()->id,
                    'enrolled_on' => $enrolledOn,
                    'status' => 'active',
                ]);
        });
    }

    protected function sameStudentEnrolledIn(SchoolClass $schoolClass, Enrollment $enrollment): Enrollment
    {
        return $this->inTenant($this->organization, function () use ($schoolClass, $enrollment): Enrollment {
            return Enrollment::factory()
                ->recycle($this->organization)
                ->create([
                    'class_id' => $schoolClass->id,
                    'student_id' => $enrollment->student_id,
                    'enrolled_on' => $enrollment->enrolled_on,
                    'status' => 'active',
                ]);
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
}
