<?php

namespace Tests\Feature\Students;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\Module;
use App\Models\Organization;
use App\Models\OrganizationModuleOverride;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Alunos» — the directory.
 *
 * Two rules carry this page, and most of what follows is one of them being
 * held to: a teacher sees the students of THEIR OWN turmas (§23), and nothing
 * identifying travels that finding somebody does not need (ADR-0004, §22).
 * Everything else — the filters, the paging, the links out — is navigation.
 */
class StudentDirectoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create();
        $this->organization = $this->teacher->personalOrganization();
    }

    // ------------------------------------------------------------- fixtures

    protected function makeClass(
        string $label,
        ?User $teacher = null,
        ?Organization $organization = null,
        ?AcademicYear $year = null,
        ?Subject $subject = null,
    ): SchoolClass {
        $organization ??= $this->organization;
        $teacher ??= $this->teacher;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $label, $year, $subject): SchoolClass {
            // One 2026/2027 per organization — the label is unique per tenant,
            // and every turma this teacher has belongs to the same year unless a
            // test says otherwise.
            $year ??= AcademicYear::query()->where('label', '2026/2027')->first()
                ?? AcademicYear::factory()->recycle($organization)->create([
                    'label' => '2026/2027',
                    'starts_on' => '2026-09-14',
                    'ends_on' => '2027-06-30',
                ]);
            $subject ??= Subject::factory()->recycle($organization)->create();

            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->getKey(),
                'subject_id' => $subject->getKey(),
                'label' => $label,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }

    protected function enrol(
        SchoolClass $class,
        string $name,
        ?int $number = null,
        EnrollmentStatus $status = EnrollmentStatus::Active,
        ?Student $student = null,
        ?Organization $organization = null,
    ): Enrollment {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $class, $name, $number, $status, $student): Enrollment {
            if ($student === null) {
                $student = Student::factory()->recycle($organization)->create();

                StudentIdentity::create([
                    'student_id' => $student->getKey(),
                    'organization_id' => $organization->getKey(),
                    'display_name' => $name,
                ]);
            }

            return Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->getKey(),
                'student_id' => $student->getKey(),
                'class_number' => $number,
                'status' => $status,
            ]);
        });
    }

    /**
     * The Student behind an enrolment, read inside its own tenant — Student is
     * organization-scoped and throws outside one, which is exactly the property
     * this page depends on.
     */
    protected function studentOf(Enrollment $enrollment): Student
    {
        $organization = Organization::findOrFail($enrollment->organization_id);

        return app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): Student => Student::query()->findOrFail($enrollment->student_id),
        );
    }

    /**
     * A second organization with a teacher of its own — the other side of every
     * isolation assertion below.
     *
     * @return array{user: User, organization: Organization}
     */
    protected function elsewhere(): array
    {
        $user = User::factory()->create();

        return ['user' => $user, 'organization' => $user->personalOrganization()];
    }

    /** A colleague in the SAME organization: entitled to the tenant, not to the turma. */
    protected function colleague(): User
    {
        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);

        return $colleague;
    }

    protected function suspend(User $user, string $planKey = 'base'): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $user->personalOrganization()->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $user->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', $planKey)->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Suspended,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    // --------------------------------------------------------------- asking

    /**
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    protected function directory(array $query = [], ?User $user = null): array
    {
        $props = [];
        $url = '/students'.($query === [] ? '' : '?'.http_build_query($query));

        $this->actingAs($user ?? $this->teacher)
            ->get($url)
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$props): void {
                $page->component('students/Index');
                $props = $page->toArray()['props'];
            });

        return $props;
    }

    /**
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    protected function rows(array $query = [], ?User $user = null): array
    {
        return $this->directory($query, $user)['students']['data'];
    }

    /**
     * @param  array<string, string>  $query
     * @return list<string>
     */
    protected function names(array $query = [], ?User $user = null): array
    {
        return array_column($this->rows($query, $user), 'name');
    }

    // ------------------------------------------------------------ isolation

    #[Test]
    public function a_student_of_another_organization_never_appears(): void
    {
        $mine = $this->makeClass('7.º A');
        $this->enrol($mine, 'Ana Silva');

        $other = $this->elsewhere();
        $theirClass = $this->makeClass('7.º A', $other['user'], $other['organization']);
        $this->enrol($theirClass, 'Beatriz Costa', organization: $other['organization']);

        $this->assertSame(['Ana Silva'], $this->names());
    }

    #[Test]
    public function searching_a_name_never_crosses_organizations(): void
    {
        // The same person's name in two schools. The blind index of «Ana Silva»
        // is byte-for-byte identical in both rows — the organization is what
        // separates them, and it has to be asked for explicitly because
        // StudentIdentity carries no global scope of its own (§5, §14).
        $mine = $this->makeClass('7.º A');
        $ours = $this->enrol($mine, 'Ana Silva');

        $other = $this->elsewhere();
        $theirClass = $this->makeClass('7.º A', $other['user'], $other['organization']);
        $this->enrol($theirClass, 'Ana Silva', organization: $other['organization']);

        $rows = $this->rows(['q' => 'Ana Silva']);

        $this->assertCount(1, $rows);
        $this->assertSame($this->studentOf($ours)->pseudonym_code, $rows[0]['pseudonym']);
    }

    #[Test]
    public function a_teacher_does_not_see_the_students_of_a_colleagues_turma(): void
    {
        $mine = $this->makeClass('7.º A');
        $this->enrol($mine, 'Ana Silva');

        // Same school, same tenant, another teacher's roll. Tenancy resolves it;
        // «só as minhas turmas» is what must refuse it.
        $colleague = $this->colleague();
        $theirs = $this->makeClass('8.º B', $colleague);
        $this->enrol($theirs, 'Beatriz Costa');

        $this->assertSame(['Ana Silva'], $this->names());
        $this->assertSame(['Beatriz Costa'], $this->names(user: $colleague));
    }

    #[Test]
    public function a_shared_student_is_shown_only_through_the_turmas_the_teacher_teaches(): void
    {
        // One child, two turmas, two teachers. Each teacher sees the person —
        // and only their own enrolment of them.
        $mine = $this->makeClass('7.º A');
        $enrollment = $this->enrol($mine, 'Ana Silva');

        $colleague = $this->colleague();
        $theirs = $this->makeClass('8.º B', $colleague);
        $this->enrol($theirs, 'Ana Silva', student: $this->studentOf($enrollment));

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertSame(['7.º A'], array_column($rows[0]['enrollments'], 'class_label'));

        $theirRows = $this->rows(user: $colleague);

        $this->assertCount(1, $theirRows);
        $this->assertSame(['8.º B'], array_column($theirRows[0]['enrollments'], 'class_label'));
    }

    // ---------------------------------------------------------- entitlement

    #[Test]
    public function a_base_organization_opens_the_directory(): void
    {
        // `students` is a Base module and this slice did not move it. A fresh
        // personal organization is Base, and reaching the page proves it.
        $this->assertContains('students', app(Entitlements::class)->modulesFor($this->organization));

        $this->actingAs($this->teacher)->get('/students')->assertOk();
    }

    #[Test]
    public function an_organization_without_the_module_is_refused(): void
    {
        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->getKey(),
            'module_id' => Module::where('key', 'students')->firstOrFail()->getKey(),
            'enabled' => false,
            'reason' => 'Módulo desativado para este teste.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->teacher)->get('/students')->assertForbidden();
    }

    #[Test]
    public function a_suspended_organization_can_still_consult_the_directory(): void
    {
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva');

        $this->suspend($this->teacher);

        // ReadOnly: the data does not disappear when the subscription pauses,
        // and this page only ever reads.
        $this->assertSame(['Ana Silva'], $this->names());
    }

    // --------------------------------------------------------------- search

    #[Test]
    public function a_complete_name_is_found_through_the_blind_index(): void
    {
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva');
        $this->enrol($class, 'Beatriz Costa');

        $this->assertSame(['Ana Silva'], $this->names(['q' => 'Ana Silva']));

        // The index normalizes (squish + lower), so the teacher's typing does
        // not have to match the roll's capitalization or spacing.
        $this->assertSame(['Ana Silva'], $this->names(['q' => '  ana   silva ']));
    }

    #[Test]
    public function a_pseudonym_is_found_by_its_prefix(): void
    {
        $class = $this->makeClass('7.º A');
        $enrollment = $this->enrol($class, 'Ana Silva');
        $this->enrol($class, 'Beatriz Costa');

        $prefix = substr($this->studentOf($enrollment)->pseudonym_code, 0, 6);

        $this->assertSame(['Ana Silva'], $this->names(['q' => $prefix]));
        // Typed as a teacher would type it, not as it is stored.
        $this->assertSame(['Ana Silva'], $this->names(['q' => strtolower($prefix)]));
    }

    #[Test]
    public function half_a_name_finds_nobody_on_the_server_and_that_is_the_documented_behaviour(): void
    {
        // NOT A BUG, AND THE REASON THE PAGE HAS ITS OWN FILTER. display_name is
        // encrypted and its index answers equality only; «Ana» is not the name,
        // so the server has nothing to match it against. Partial matching over
        // the rows already on screen is the front end's job (§5, §6).
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva');

        $this->assertSame([], $this->names(['q' => 'Ana']));
    }

    #[Test]
    public function a_wildcard_in_the_search_term_matches_a_wildcard_and_not_everybody(): void
    {
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva');

        $this->assertSame([], $this->names(['q' => '%']));
        $this->assertSame([], $this->names(['q' => 'ALU%']));
    }

    // -------------------------------------------------------------- filters

    #[Test]
    public function the_turma_filter_narrows_to_that_roll(): void
    {
        $first = $this->makeClass('7.º A');
        $second = $this->makeClass('8.º B');
        $this->enrol($first, 'Ana Silva');
        $this->enrol($second, 'Beatriz Costa');

        $this->assertSame(['Ana Silva'], $this->names(['class' => $first->ulid]));
        $this->assertSame(['Beatriz Costa'], $this->names(['class' => $second->ulid]));
    }

    #[Test]
    public function a_turma_the_teacher_does_not_teach_cannot_be_used_as_a_filter(): void
    {
        $mine = $this->makeClass('7.º A');
        $this->enrol($mine, 'Ana Silva');

        $colleague = $this->colleague();
        $theirs = $this->makeClass('8.º B', $colleague);
        $this->enrol($theirs, 'Beatriz Costa');

        // The ulid does not resolve against what this teacher may see, so the
        // page answers as if no turma had been asked for — and never confirms
        // that the class exists.
        $props = $this->directory(['class' => $theirs->ulid]);

        $this->assertNull($props['filters']['class']);
        $this->assertSame(['Ana Silva'], array_column($props['students']['data'], 'name'));
    }

    #[Test]
    public function the_academic_year_filter_narrows_to_that_year(): void
    {
        $previousYear = app(CurrentOrganization::class)->runFor($this->organization, fn (): AcademicYear => AcademicYear::factory()->recycle($this->organization)->create([
            'label' => '2025/2026',
            'starts_on' => '2025-09-15',
            'ends_on' => '2026-06-30',
        ]));

        $current = $this->makeClass('7.º A');
        $previous = $this->makeClass('6.º A', year: $previousYear);
        $this->enrol($current, 'Ana Silva');
        $this->enrol($previous, 'Beatriz Costa');

        $this->assertSame(['Beatriz Costa'], $this->names(['year' => $previousYear->ulid]));
        $this->assertSame(
            ['2026/2027', '2025/2026'],
            array_column($this->directory()['academicYears'], 'label'),
        );
    }

    #[Test]
    public function a_student_who_left_keeps_their_place_with_the_state_that_says_so(): void
    {
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva', 1);
        $this->enrol($class, 'Beatriz Costa', 2, EnrollmentStatus::TransferredOut);

        // Unfiltered, the history is there: leaving in March does not erase a
        // year (§26, §58).
        $rows = $this->rows();
        $this->assertSame(['Ana Silva', 'Beatriz Costa'], array_column($rows, 'name'));
        $this->assertTrue($rows[0]['enrollments'][0]['is_current']);
        $this->assertFalse($rows[1]['enrollments'][0]['is_current']);
        $this->assertSame('Transferido', $rows[1]['enrollments'][0]['status_label']);

        // The state is the ENROLMENT's, filtered on the enrolment. There is no
        // students.status and this page invents none (§7).
        $this->assertSame(['Ana Silva'], $this->names(['status' => 'active']));
        $this->assertSame(['Beatriz Costa'], $this->names(['status' => 'transferred_out']));
    }

    // ------------------------------------------------------------ the rows

    #[Test]
    public function a_student_in_several_turmas_is_one_row_carrying_both(): void
    {
        $first = $this->makeClass('7.º A');
        $second = $this->makeClass('8.º B');

        $enrollment = $this->enrol($first, 'Ana Silva', 3);
        $this->enrol($second, 'Ana Silva', 11, student: $this->studentOf($enrollment));

        $rows = $this->rows();

        $this->assertCount(1, $rows);
        $this->assertCount(2, $rows[0]['enrollments']);
        $this->assertSame(['7.º A', '8.º B'], array_column($rows[0]['enrollments'], 'class_label'));
    }

    #[Test]
    public function each_enrolment_carries_the_turma_and_the_inscription_its_acompanhamento_needs(): void
    {
        $class = $this->makeClass('7.º A');
        $enrollment = $this->enrol($class, 'Ana Silva', 3);

        $row = $this->rows()[0];

        // The pair the reading is addressed by — never a student id (§58). The
        // page builds /classes/{class}/evolucao/{enrollment} from exactly these.
        $this->assertSame($class->ulid, $row['enrollments'][0]['class_ulid']);
        $this->assertSame($enrollment->ulid, $row['enrollments'][0]['enrollment_ulid']);
        $this->assertSame(
            "/classes/{$class->ulid}/evolucao/{$enrollment->ulid}",
            route('student-progress.student', [$class->ulid, $enrollment->ulid], false),
        );

        // And that address really is the panel, reached with these two values.
        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk();
    }

    #[Test]
    public function opening_the_turma_from_the_directory_lands_on_the_class_page(): void
    {
        $class = $this->makeClass('7.º A');
        $this->enrol($class, 'Ana Silva');

        $row = $this->rows()[0];

        $this->actingAs($this->teacher)
            ->get("/classes/{$row['enrollments'][0]['class_ulid']}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('classes/Show'));
    }

    // ------------------------------------------------------------- privacy

    #[Test]
    public function the_payload_carries_nothing_beyond_what_finding_somebody_needs(): void
    {
        $class = $this->makeClass('7.º A');
        $enrollment = $this->enrol($class, 'Ana Silva', 3);

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($enrollment): void {
            $enrollment->student->identity->update([
                'birth_date' => '2013-04-17',
                'school_number' => '20134417',
            ]);
        });

        $props = $this->directory();
        $row = $props['students']['data'][0];

        $this->assertSame(
            ['student_ulid', 'name', 'pseudonym', 'photo_url', 'enrollments'],
            array_keys($row),
        );
        $this->assertSame(
            [
                'enrollment_ulid', 'class_ulid', 'class_label', 'subject',
                'academic_year', 'class_number', 'status', 'status_label', 'is_current',
            ],
            array_keys($row['enrollments'][0]),
        );

        // Not merely absent from the row: absent from the whole page. A nested
        // model serialized by accident would show up here.
        $encoded = json_encode($props);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('birth_date', $encoded);
        $this->assertStringNotContainsString('2013-04-17', $encoded);
        $this->assertStringNotContainsString('school_number', $encoded);
        $this->assertStringNotContainsString('20134417', $encoded);
        $this->assertStringNotContainsString('display_name_index', $encoded);
    }

    #[Test]
    public function a_photo_travels_as_a_guarded_url_and_never_as_a_path_or_bytes(): void
    {
        $class = $this->makeClass('7.º A');
        $enrollment = $this->enrol($class, 'Ana Silva');

        app(CurrentOrganization::class)->runFor($this->organization, function () use ($enrollment): void {
            $enrollment->student->identity->update([
                'photo_path' => 'student-photos/1/segredo.jpg',
            ]);
        });

        $props = $this->directory();
        $student = $this->studentOf($enrollment);

        $this->assertStringContainsString(
            "/students/{$student->ulid}/photo",
            (string) $props['students']['data'][0]['photo_url'],
        );

        $encoded = json_encode($props);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('photo_path', $encoded);
        $this->assertStringNotContainsString('segredo.jpg', $encoded);
    }

    // --------------------------------------------------- cost and paging

    #[Test]
    public function the_directory_costs_the_same_number_of_queries_whatever_the_roll_size(): void
    {
        $first = $this->makeClass('7.º A');
        $second = $this->makeClass('8.º B');
        $this->enrol($first, 'Aluno 1', 1);

        // One request first, thrown away: the plan, its modules and the
        // overrides are resolved once per process and cached, and counting them
        // into the first measurement alone would compare a cold request with a
        // warm one instead of comparing one roll with another.
        $this->actingAs($this->teacher)->get('/students')->assertOk();

        $queriesForOne = $this->countQueries();

        foreach (range(2, 12) as $index) {
            $this->enrol($index % 2 === 0 ? $first : $second, "Aluno {$index}", $index);
        }

        // Identity, turma, disciplina and ano are all eager-loaded, and
        // photoUrl() reads the identity that is already in memory: twelve
        // students across two turmas must cost exactly what one costs.
        $this->assertSame($queriesForOne, $this->countQueries());
    }

    protected function countQueries(): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });

        $this->actingAs($this->teacher)->get('/students')->assertOk();

        // Laravel has no public "forget listeners": the closure stays, and the
        // counter is what the next call resets.
        return $count;
    }

    #[Test]
    public function the_roll_is_paginated_and_no_student_falls_between_two_pages(): void
    {
        $class = $this->makeClass('7.º A');

        foreach (range(1, 101) as $number) {
            $this->enrol($class, "Aluno {$number}", $number);
        }

        $first = $this->directory(['class' => $class->ulid]);

        $this->assertSame(100, $first['students']['per_page']);
        $this->assertSame(101, $first['students']['total']);
        $this->assertCount(100, $first['students']['data']);

        $second = $this->directory(['class' => $class->ulid, 'page' => '2']);

        $this->assertCount(1, $second['students']['data']);
        // The filter survives the page change (withQueryString), so page 2 is
        // page 2 OF THE SAME QUESTION.
        $this->assertSame($class->ulid, $second['filters']['class']);

        // The order is stable, so the two pages partition the roll instead of
        // overlapping it.
        $ulids = [
            ...array_column($first['students']['data'], 'student_ulid'),
            ...array_column($second['students']['data'], 'student_ulid'),
        ];
        $this->assertCount(101, array_unique($ulids));

        // Ordered by the roll's own number, which is how the Relação de Turma
        // has it — the name is encrypted and cannot be sorted on.
        $this->assertSame('Aluno 1', $first['students']['data'][0]['name']);
        $this->assertSame('Aluno 101', $second['students']['data'][0]['name']);
    }

    // ---------------------------------------------------------- empty states

    #[Test]
    public function a_teacher_with_no_turmas_is_told_so_and_not_that_they_have_no_students(): void
    {
        $props = $this->directory();

        $this->assertSame([], $props['classes']);
        $this->assertSame(0, $props['students']['total']);
    }

    #[Test]
    public function a_teacher_with_empty_turmas_has_the_turmas_but_no_rows(): void
    {
        $this->makeClass('7.º A');

        $props = $this->directory();

        $this->assertCount(1, $props['classes']);
        $this->assertSame(0, $props['students']['total']);
    }
}
