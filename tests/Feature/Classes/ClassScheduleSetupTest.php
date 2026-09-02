<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * classes.schedule-setup — "Configurar horários", the front door onto BOTH
 * ways a teacher fills in a turma's schedule: importing a PDF
 * (timetable-imports.create, unchanged) or configuring one turma at a time by
 * hand on its own page (LessonScheduleEditor via classes.show, unchanged).
 * This picker creates nothing itself and duplicates no scoping logic of its
 * own — every assertion here about "whose turmas" is really an assertion
 * about ClassController::teacherClasses(), the same query classes.index has
 * always used.
 */
class ClassScheduleSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Organization $organization;

    private AcademicYear $academicYear;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
        $this->subscribeToPro($this->organization);

        $this->academicYear = $this->inTenant(
            fn (): AcademicYear => AcademicYear::factory()->recycle($this->organization)->create(),
        );
    }

    #[Test]
    public function the_page_renders_with_both_the_import_and_manual_paths_data(): void
    {
        $class = $this->schoolClassFor($this->teacher, '7.º C');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/ScheduleSetup')
                ->has('classes', 1)
                ->where('classes.0.ulid', $class->ulid)
                ->where('classes.0.label', '7.º C'));
    }

    /**
     * Not swallowed as an (invalid) class ulid by GET classes/{class} — the
     * same instruments/create vs instruments/{instrument} pitfall this route
     * was deliberately registered ahead of.
     */
    #[Test]
    public function the_route_is_not_swallowed_by_the_class_wildcard(): void
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('classes/ScheduleSetup'));
    }

    #[Test]
    public function the_manual_list_never_offers_a_turma_from_another_organization(): void
    {
        $ownClass = $this->schoolClassFor($this->teacher, 'Minha turma');

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $this->schoolClassFor($stranger, 'Turma de outra organização', $strangerOrganization);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/ScheduleSetup')
                ->has('classes', 1)
                ->where('classes.0.ulid', $ownClass->ulid));
    }

    #[Test]
    public function the_manual_list_never_offers_a_colleagues_turma_in_the_same_organization(): void
    {
        $ownClass = $this->schoolClassFor($this->teacher, 'Minha turma');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->schoolClassFor($colleague, 'Turma do colega');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/ScheduleSetup')
                ->has('classes', 1)
                ->where('classes.0.ulid', $ownClass->ulid));
    }

    #[Test]
    public function a_teacher_with_no_classes_gets_an_empty_list_not_a_broken_page(): void
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/ScheduleSetup')
                ->where('classes', []));
    }

    #[Test]
    public function a_teacher_without_the_lessons_module_cannot_reach_it(): void
    {
        $base = User::factory()->create();
        $baseOrganization = $base->personalOrganization();

        $this->actingAs($base)
            ->withSession(['organization_id' => $baseOrganization->id])
            ->get('/classes/schedule-setup')
            ->assertForbidden();
    }

    /**
     * The "Importar PDF" link on this page points at the real, existing,
     * unmodified timetable-imports.create route — its own internals are
     * already covered by TimetableImportTest and are not re-tested here.
     */
    #[Test]
    public function the_import_path_resolves_to_the_real_unmodified_import_route(): void
    {
        $this->assertTrue(Route::has('timetable-imports.create'));
        $this->assertSame('/timetable-imports/create', route('timetable-imports.create', [], false));

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable-imports/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('timetable-imports/Create'));
    }

    /**
     * The manual path's "Configurar horário" link lands on the turma's own,
     * pre-existing detail page, where LessonScheduleEditor already renders —
     * adapted from WeeklyLessonsTest's own assertion that a base plan
     * receives `recurringLessonSlots: null` (and so never renders the
     * editor): here, on a Pro plan, the array is present instead.
     */
    #[Test]
    public function selecting_a_class_lands_on_its_detail_page_with_the_schedule_editor_present(): void
    {
        $class = $this->schoolClassFor($this->teacher, '7.º C');
        $this->inTenant(fn () => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
        ]));

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('classes/Show');

                $slots = $page->toArray()['props']['recurringLessonSlots'];
                $this->assertIsArray($slots);
                $this->assertCount(1, $slots);
            });
    }

    /**
     * A mesma filtragem que TeacherTimetableTest já prova para o horário do
     * professor, aqui do lado do editor da própria turma: uma linha fechada
     * por uma revisão (ReviseRecurringLessonSlot) fica na tabela — os Lesson
     * já materializados a partir dela continuam a apontar para ela — mas
     * não volta a aparecer neste ecrã ao lado da versão que a substituiu, e
     * a versão que fica já traz `already_in_vigor`.
     */
    #[Test]
    public function a_closed_slot_from_an_earlier_revision_never_appears_in_the_turmas_own_editor(): void
    {
        $class = $this->schoolClassFor($this->teacher, '7.º C');
        $today = CarbonImmutable::now('Europe/Lisbon');

        $this->inTenant(fn () => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'day_of_week' => 1,
            'starts_at' => '08:30',
            'ends_at' => '09:20',
            'ends_on' => $today->subDay()->toDateString(),
        ]));
        $this->inTenant(fn () => RecurringLessonSlot::create([
            'class_id' => $class->id,
            'day_of_week' => 1,
            'starts_at' => '09:30',
            'ends_at' => '10:20',
            'starts_on' => $today->toDateString(),
        ]));

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get("/classes/{$class->ulid}")
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) {
                $page->component('classes/Show');

                $slots = $page->toArray()['props']['recurringLessonSlots'];
                $this->assertIsArray($slots);
                $this->assertCount(1, $slots);
                $this->assertSame('09:30', $slots[0]['starts_at']);
                $this->assertTrue($slots[0]['already_in_vigor']);
            });
    }

    // --------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function tenantSession(): array
    {
        return [
            'organization_id' => $this->organization->id,
            'academic_year_id' => $this->academicYear->id,
        ];
    }

    private function schoolClassFor(User $teacher, string $label, ?Organization $organization = null): SchoolClass
    {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $label): SchoolClass {
            $academicYear = $organization->is($this->organization)
                ? $this->academicYear
                : AcademicYear::factory()->recycle($organization)->create();

            $subject = Subject::query()->firstOrCreate(['code' => 'MAT'], ['name' => 'Matemática']);

            $schoolClass = SchoolClass::factory()
                ->recycle($organization)
                ->create([
                    'label' => $label,
                    'academic_year_id' => $academicYear->getKey(),
                    'subject_id' => $subject->getKey(),
                ]);
            $schoolClass->teachers()->attach($teacher, ['role' => 'owner']);

            return $schoolClass;
        });
    }

    private function subscribeToPro(Organization $organization): void
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

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
