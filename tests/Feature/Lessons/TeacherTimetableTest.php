<?php

namespace Tests\Feature\Lessons;

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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Horário do Professor» (timetable.index) — the teacher's whole week read in
 * one place, plus the two existing, unmodified ways of filling it in.
 *
 * THE PAGE IS A READING AND NOTHING ELSE. The hardest assertion here is the
 * one that nothing appears in the database when it is opened: unlike the
 * weekly «Aulas e Sumários» view, which deliberately materializes the
 * occurrences of the week it shows, a horário is the RULE and not its
 * occurrences, so opening it must create no Lesson and no RecurringLessonSlot.
 *
 * Every assertion about «whose turmas» is really an assertion about
 * SchoolClass::scopeTaughtBy — the same scoping classes.index and
 * classes.schedule-setup have always used, called here rather than reproduced.
 */
class TeacherTimetableTest extends TestCase
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
    public function an_authorized_teacher_reaches_the_page(): void
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('timetable/Index'));
    }

    #[Test]
    public function a_teacher_without_the_lessons_module_cannot_reach_it(): void
    {
        $base = User::factory()->create();
        $baseOrganization = $base->personalOrganization();

        $this->actingAs($base)
            ->withSession(['organization_id' => $baseOrganization->id])
            ->get('/timetable')
            ->assertForbidden();
    }

    #[Test]
    public function a_guest_is_sent_to_log_in(): void
    {
        $this->get('/timetable')->assertRedirect('/login');
    }

    #[Test]
    public function the_week_shows_every_slot_of_every_turma_ordered_by_day_then_time(): void
    {
        $mathematics = $this->schoolClassFor($this->teacher, '7.º C');
        $physics = $this->schoolClassFor($this->teacher, '8.º A');

        // Created out of order on purpose: the page's ordering must come from
        // the query, never from the order rows happened to be written in.
        $this->slot($physics, 3, '11:00', '11:50');
        $this->slot($mathematics, 1, '10:30', '11:20');
        $this->slot($mathematics, 1, '08:30', '09:20');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('timetable/Index')
                ->has('slots', 3)
                ->where('slots.0.day_of_week', 1)
                ->where('slots.0.starts_at', '08:30')
                ->where('slots.0.ends_at', '09:20')
                ->where('slots.0.school_class.label', '7.º C')
                ->where('slots.0.subject', 'Matemática')
                ->where('slots.1.day_of_week', 1)
                ->where('slots.1.starts_at', '10:30')
                ->where('slots.2.day_of_week', 3)
                ->where('slots.2.starts_at', '11:00')
                ->where('slots.2.school_class.label', '8.º A')
                ->etc());
    }

    /**
     * The column is a `time`, so Eloquent hands back «08:30:00». The turma's
     * own editor already trims it to «08:30» (ClassController::show) and this
     * page reads the same slots, so it must not read them differently.
     */
    #[Test]
    public function the_times_lose_the_seconds_the_time_column_carries(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->slot($schoolClass, 2, '09:05', '09:55');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('slots.0.starts_at', '09:05')
                ->where('slots.0.ends_at', '09:55')
                ->etc());
    }

    #[Test]
    public function the_optional_validity_window_travels_with_the_slot(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->slot($schoolClass, 4, '14:00', '14:50', [
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-12-18',
        ]);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('slots.0.starts_on', '2026-09-14')
                ->where('slots.0.ends_on', '2026-12-18')
                ->etc());
    }

    #[Test]
    public function another_organizations_slots_never_appear(): void
    {
        $own = $this->schoolClassFor($this->teacher, 'Minha turma');
        $this->slot($own, 1, '08:30', '09:20');

        $stranger = User::factory()->create();
        $strangerOrganization = $stranger->personalOrganization();
        $this->subscribeToPro($strangerOrganization);
        $strangerClass = $this->schoolClassFor($stranger, 'Turma de outra organização', $strangerOrganization);
        $this->slot($strangerClass, 2, '10:00', '10:50', [], $strangerOrganization);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('slots', 1)
                ->where('slots.0.school_class.label', 'Minha turma')
                ->has('classes', 1)
                ->where('classes.0.ulid', $own->ulid)
                ->etc());
    }

    #[Test]
    public function a_colleagues_slots_in_the_same_organization_never_appear(): void
    {
        $own = $this->schoolClassFor($this->teacher, 'Minha turma');
        $this->slot($own, 1, '08:30', '09:20');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $colleagueClass = $this->schoolClassFor($colleague, 'Turma do colega');
        $this->slot($colleagueClass, 5, '15:00', '15:50');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('slots', 1)
                ->where('slots.0.school_class.label', 'Minha turma')
                ->has('classes', 1)
                ->where('classes.0.ulid', $own->ulid)
                ->etc());
    }

    #[Test]
    public function a_teacher_with_no_slots_at_all_gets_an_empty_reading_not_a_broken_page(): void
    {
        $this->schoolClassFor($this->teacher, '7.º C');

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('timetable/Index')
                ->where('slots', [])
                // The manual path is still offered: having no horário yet is
                // precisely when a teacher needs the two ways of making one.
                ->has('classes', 1));
    }

    #[Test]
    public function a_brand_new_teacher_with_neither_turmas_nor_slots_still_gets_the_page(): void
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('timetable/Index')
                ->where('slots', [])
                ->where('classes', []));
    }

    /**
     * THE HARD REQUIREMENT: reading a horário writes nothing. Counted before
     * and after, over both tables, because the failure this guards against —
     * a read that quietly materializes aulas — is exactly what the weekly
     * view deliberately does and this page deliberately does not.
     */
    #[Test]
    public function opening_the_page_creates_no_slot_and_no_lesson(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->slot($schoolClass, 1, '08:30', '09:20');

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseCount('lessons', 0);

        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/timetable')
            ->assertOk();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseCount('lessons', 0);
    }

    /**
     * A support session may LOOK at the week it is being asked about, because
     * looking changes nothing — and the assertion that follows the request is
     * the proof of it, not the absence of a refusal.
     */
    #[Test]
    public function reading_the_page_during_impersonation_writes_nothing_either(): void
    {
        $schoolClass = $this->schoolClassFor($this->teacher, '7.º C');
        $this->slot($schoolClass, 1, '08:30', '09:20');

        $this->actingAs($this->teacher)
            ->withSession([...$this->tenantSession(), 'impersonator_id' => 999])
            ->get('/timetable')
            ->assertOk();

        $this->assertDatabaseCount('recurring_lesson_slots', 1);
        $this->assertDatabaseCount('lessons', 0);
    }

    /**
     * The "Importar PDF" entry point points at the real, existing, unmodified
     * import route — its internals are covered by TimetableImportTest and are
     * not re-tested here.
     */
    #[Test]
    public function the_import_entry_point_is_the_real_unmodified_import_route(): void
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
     * The manual entry point offers the same list, scoped the same way, as
     * classes.schedule-setup's own — because both call
     * SchoolClass::scopeTaughtBy rather than each building the query again.
     */
    #[Test]
    public function the_manual_entry_point_offers_the_same_class_list_as_the_existing_picker(): void
    {
        $this->schoolClassFor($this->teacher, '8.º A');
        $this->schoolClassFor($this->teacher, '7.º C');

        $colleague = User::factory()->create();
        $this->organization->members()->attach($colleague, ['joined_at' => now()]);
        $this->schoolClassFor($colleague, 'Turma do colega');

        $session = $this->tenantSession();

        $fromTimetable = $this->actingAs($this->teacher)->withSession($session)
            ->get('/timetable')->assertOk()->viewData('page')['props']['classes'];
        $fromPicker = $this->actingAs($this->teacher)->withSession($session)
            ->get('/classes/schedule-setup')->assertOk()->viewData('page')['props']['classes'];

        $this->assertSame($fromPicker, $fromTimetable);
        $this->assertSame(['7.º C', '8.º A'], array_column($fromTimetable, 'label'));
    }

    /**
     * The turma's own contextual shortcut is untouched by this phase: the
     * picker still answers at its own address and still renders its own page.
     */
    #[Test]
    public function the_existing_schedule_setup_picker_is_still_reachable_and_unchanged(): void
    {
        $this->actingAs($this->teacher)
            ->withSession($this->tenantSession())
            ->get('/classes/schedule-setup')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('classes/ScheduleSetup'));
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

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function slot(
        SchoolClass $schoolClass,
        int $dayOfWeek,
        string $startsAt,
        string $endsAt,
        array $attributes = [],
        ?Organization $organization = null,
    ): RecurringLessonSlot {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): RecurringLessonSlot => RecurringLessonSlot::create([
                'class_id' => $schoolClass->id,
                'day_of_week' => $dayOfWeek,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                ...$attributes,
            ]),
        );
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
            'starts_at' => Carbon::now()->subDay(),
        ]);
        app(Entitlements::class)->flush();
    }

    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }
}
