<?php

namespace Tests\Feature\Lessons\Concerns;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Support\Carbon;

/**
 * O andaime partilhado pelos testes desta fatia: um professor, uma organização
 * com plano, uma turma com ano letivo, e as fábricas de tempos do horário, de
 * grupos e de aulas.
 *
 * Escrito uma vez em vez de copiado por cada ficheiro — os seis testes desta
 * funcionalidade precisam exatamente do mesmo mundo, e seis cópias divergiriam
 * na primeira vez que um deles precisasse de mais um campo.
 */
trait BuildsLessonFixtures
{
    protected Organization $organization;

    protected User $teacher;

    protected SchoolClass $schoolClass;

    protected function bootLessonFixtures(): void
    {
        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->schoolClass = $this->inTenant($this->organization, function (): SchoolClass {
            $schoolClass = SchoolClass::factory()
                ->recycle($this->organization)
                ->create([
                    'academic_year_id' => AcademicYear::factory()
                        ->recycle($this->organization)
                        ->create(['starts_on' => '2026-09-01', 'ends_on' => '2027-06-30'])
                        ->id,
                ]);
            $schoolClass->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    protected function asTeacher(): self
    {
        return $this->actingAs($this->teacher)
            ->withSession(['organization_id' => $this->organization->id]);
    }

    protected function inTenant(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeLesson(array $attributes = []): Lesson
    {
        // Uma aula noutro dia sem `ends_at` explícito herdava o fim de 8 de
        // outubro e acabava antes de começar — o SQLite não o vê, e o CHECK
        // `lessons_times_check` do MySQL recusa-o. O fim segue o início.
        if (isset($attributes['starts_at']) && ! array_key_exists('ends_at', $attributes)) {
            $attributes['ends_at'] = Carbon::parse($attributes['starts_at'])->addMinutes(50)->toDateTimeString();
        }

        return $this->inTenant($this->organization, fn (): Lesson => Lesson::create(array_merge([
            'class_id' => $this->schoolClass->id,
            'starts_at' => '2026-10-08 09:30:00',
            'ends_at' => '2026-10-08 10:20:00',
            'status' => LessonStatus::Preparation,
            'created_by' => $this->teacher->id,
        ], $attributes)));
    }

    protected function makeGroup(string $label, ?SchoolClass $schoolClass = null): ClassGroup
    {
        $schoolClass ??= $this->schoolClass;

        return $this->inTenant($this->organization, fn (): ClassGroup => ClassGroup::create([
            'class_id' => $schoolClass->id,
            'label' => $label,
            'position' => 1,
        ]));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeSlot(array $attributes = []): RecurringLessonSlot
    {
        return $this->inTenant($this->organization, fn (): RecurringLessonSlot => RecurringLessonSlot::create(
            array_merge([
                'class_id' => $this->schoolClass->id,
                'day_of_week' => 4,
                'starts_at' => '09:30',
                'ends_at' => '10:20',
            ], $attributes),
        ));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function enroll(string $name, array $attributes = [], ?SchoolClass $schoolClass = null): Enrollment
    {
        $schoolClass ??= $this->schoolClass;

        return $this->inTenant($this->organization, function () use ($attributes, $name, $schoolClass): Enrollment {
            $student = Student::factory()->recycle($this->organization)->create();

            StudentIdentity::create([
                'student_id' => $student->id,
                'organization_id' => $this->organization->id,
                'display_name' => $name,
            ]);

            $enrollment = Enrollment::create(array_merge([
                'class_id' => $schoolClass->id,
                'student_id' => $student->id,
                'enrolled_on' => '2026-09-01',
                'status' => 'active',
                'is_late_entry' => false,
            ], $attributes));

            // Carregado já aqui, dentro do tenant: os testes lêem
            // `$enrollment->student->ulid` fora de `inTenant()`, e a relação
            // só responde sem uma nova query — que precisaria de tenant
            // resolvido — se já estiver em cache.
            return $enrollment->load('student.identity');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function addToGroup(Enrollment $enrollment, ClassGroup $group, array $attributes = []): ClassGroupMembership
    {
        return $this->inTenant($this->organization, fn (): ClassGroupMembership => ClassGroupMembership::create(array_merge([
            'class_group_id' => $group->id,
            'enrollment_id' => $enrollment->id,
            'effective_from' => '2026-09-01',
            'effective_until' => null,
        ], $attributes)));
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
}
