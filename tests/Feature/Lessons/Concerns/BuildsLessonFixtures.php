<?php

namespace Tests\Feature\Lessons\Concerns;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
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
