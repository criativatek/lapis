<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * TURMAS DE APOIO — Fase 1: reunir alunos que já existem, sem os duplicar.
 *
 * Três coisas seguram esta feature e quase tudo abaixo é uma delas:
 *   - uma turma normal continua exatamente igual;
 *   - o aluno reutilizado é O MESMO Student — nome, n.º, fotografia —, só a
 *     inscrição é nova;
 *   - a pesquisa e a inscrição nunca saem das turmas que o professor leciona
 *     neste ano, e o servidor volta a verificar o ULID que recebe.
 */
class SupportClassTest extends TestCase
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

    protected function year(Organization $organization, string $label = '2026/2027'): AcademicYear
    {
        return app(CurrentOrganization::class)->runFor($organization, fn (): AcademicYear => AcademicYear::query()->where('label', $label)->first()
            ?? AcademicYear::factory()->recycle($organization)->create([
                'label' => $label,
                'starts_on' => $label === '2026/2027' ? '2026-09-14' : '2025-09-15',
                'ends_on' => $label === '2026/2027' ? '2027-06-30' : '2026-06-30',
            ]));
    }

    protected function makeClass(
        string $label,
        bool $support = false,
        ?User $teacher = null,
        ?Organization $organization = null,
        ?AcademicYear $year = null,
    ): SchoolClass {
        $organization ??= $this->organization;
        $teacher ??= $this->teacher;
        $year ??= $this->year($organization);

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher, $label, $support, $year): SchoolClass {
            $class = SchoolClass::factory()->recycle($organization)->create([
                'academic_year_id' => $year->getKey(),
                'subject_id' => Subject::factory()->recycle($organization)->create()->getKey(),
                'label' => $label,
                'is_support_class' => $support,
            ]);
            $class->teachers()->attach($teacher, ['role' => 'owner']);

            return $class;
        });
    }

    protected function enrol(
        SchoolClass $class,
        string $name,
        ?int $number = null,
        ?string $processNumber = null,
        ?string $photoPath = null,
        ?Organization $organization = null,
        EnrollmentStatus $status = EnrollmentStatus::Active,
    ): Enrollment {
        $organization ??= $this->organization;

        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $class, $name, $number, $processNumber, $photoPath, $status): Enrollment {
            $student = Student::factory()->recycle($organization)->create();

            StudentIdentity::create([
                'student_id' => $student->getKey(),
                'organization_id' => $organization->getKey(),
                'display_name' => $name,
                'school_number' => $processNumber,
                'photo_path' => $photoPath,
            ]);

            return Enrollment::factory()->recycle($organization)->create([
                'class_id' => $class->getKey(),
                'student_id' => $student->getKey(),
                'class_number' => $number,
                'status' => $status,
                'enrolled_on' => '2026-09-14',
            ]);
        });
    }

    protected function studentOf(Enrollment $enrollment): Student
    {
        return app(CurrentOrganization::class)->runFor(
            Organization::findOrFail($enrollment->organization_id),
            fn (): Student => Student::query()->with('identity')->findOrFail($enrollment->student_id),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function search(SchoolClass $class, string $query, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->teacher)
            ->getJson("/classes/{$class->ulid}/existing-students?q=".urlencode($query))
            ->assertOk()
            ->json('data');
    }

    protected function addExisting(SchoolClass $class, string $studentUlid, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? $this->teacher)
            ->from("/classes/{$class->ulid}")
            ->post("/classes/{$class->ulid}/existing-students", ['student_ulid' => $studentUlid]);
    }

    protected function studentCount(): int
    {
        return DB::table('students')->count();
    }

    /**
     * @return list<int>
     */
    protected function activeStudentIdsIn(SchoolClass $class): array
    {
        return DB::table('enrollments')
            ->where('class_id', $class->getKey())
            ->where('status', EnrollmentStatus::Active->value)
            ->orderBy('student_id')
            ->pluck('student_id')
            ->map(intval(...))
            ->all();
    }

    // ------------------------------------------------------------ o tipo de turma

    #[Test]
    public function a_class_is_a_normal_class_by_default(): void
    {
        $year = $this->year($this->organization);
        $subject = app(CurrentOrganization::class)->runFor($this->organization, fn () => Subject::factory()->recycle($this->organization)->create());

        $this->actingAs($this->teacher)->post('/classes', [
            'label' => '8.º F',
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('classes', ['label' => '8.º F', 'is_support_class' => false]);
    }

    #[Test]
    public function a_support_class_can_be_created_and_the_flag_persists(): void
    {
        $year = $this->year($this->organization);
        $subject = app(CurrentOrganization::class)->runFor($this->organization, fn () => Subject::factory()->recycle($this->organization)->create());

        $this->actingAs($this->teacher)->post('/classes', [
            'label' => 'Apoio Matemática',
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
            'is_support_class' => true,
        ])->assertRedirect();

        $class = SchoolClass::withoutGlobalScope('organization')->where('label', 'Apoio Matemática')->firstOrFail();
        $this->assertTrue($class->is_support_class);

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/Show')
                ->where('schoolClass.is_support_class', true));
    }

    #[Test]
    public function the_flag_can_be_edited_and_an_edit_without_it_leaves_it_alone(): void
    {
        $class = $this->makeClass('8.º F');

        $this->actingAs($this->teacher)->put("/classes/{$class->ulid}", ['label' => '8.º F', 'is_support_class' => true])->assertRedirect();
        $this->assertTrue($class->fresh()->is_support_class);

        // O formulário antigo — só a designação — não desliga nada.
        $this->actingAs($this->teacher)->put("/classes/{$class->ulid}", ['label' => 'Apoio 8.º'])->assertRedirect();
        $this->assertTrue($class->fresh()->is_support_class);
        $this->assertSame('Apoio 8.º', $class->fresh()->label);

        $this->actingAs($this->teacher)->put("/classes/{$class->ulid}", ['label' => 'Apoio 8.º', 'is_support_class' => false])->assertRedirect();
        $this->assertFalse($class->fresh()->is_support_class);
    }

    #[Test]
    public function a_normal_class_has_no_existing_student_door(): void
    {
        $normal = $this->makeClass('8.º F');
        $other = $this->makeClass('8.º G');
        $joao = $this->enrol($other, 'João Silva');

        $this->actingAs($this->teacher)->getJson("/classes/{$normal->ulid}/existing-students?q=joao")->assertNotFound();
        $this->addExisting($normal, $this->studentOf($joao)->ulid)->assertNotFound();

        $this->assertSame([], $this->activeStudentIdsIn($normal));
    }

    #[Test]
    public function the_manual_flow_still_creates_a_new_student_in_both_kinds_of_class(): void
    {
        $normal = $this->makeClass('8.º F');
        $support = $this->makeClass('Apoio', support: true);

        $this->actingAs($this->teacher)->post("/classes/{$normal->ulid}/students", ['name' => 'Rita Lopes'])->assertRedirect();
        $this->actingAs($this->teacher)->post("/classes/{$support->ulid}/students", ['name' => 'Rui Lopes'])->assertRedirect();

        $this->assertSame(2, $this->studentCount());
        $this->assertCount(1, $this->activeStudentIdsIn($normal));
        $this->assertCount(1, $this->activeStudentIdsIn($support));
    }

    // ------------------------------------------------------------ a pesquisa

    #[Test]
    public function search_finds_by_partial_name_ignoring_accents_and_case(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $this->enrol($this->makeClass('8.º F'), 'João Silva', number: 12);

        $rows = $this->search($support, 'joao sil');

        $this->assertCount(1, $rows);
        $this->assertSame('João Silva', $rows[0]['name']);
        $this->assertSame([['label' => '8.º F', 'class_number' => 12]], $rows[0]['origins']);
        $this->assertFalse($rows[0]['already_in_class']);
    }

    #[Test]
    public function search_finds_by_process_number(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $this->enrol($this->makeClass('8.º F'), 'Marta Reis', processNumber: '004512');

        $rows = $this->search($support, '0045');

        $this->assertSame(['Marta Reis'], array_column($rows, 'name'));
        $this->assertSame('004512', $rows[0]['process_number']);
    }

    #[Test]
    public function homonyms_appear_separately_with_their_own_class_and_number(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $first = $this->enrol($this->makeClass('8.º F'), 'João Silva', number: 12);
        $second = $this->enrol($this->makeClass('8.º H'), 'João Silva', number: 7);

        $rows = $this->search($support, 'João Silva');

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            [$this->studentOf($first)->ulid, $this->studentOf($second)->ulid],
            array_column($rows, 'ulid'),
        );
        $this->assertEqualsCanonicalizing(
            [[['label' => '8.º F', 'class_number' => 12]], [['label' => '8.º H', 'class_number' => 7]]],
            array_column($rows, 'origins'),
        );
    }

    #[Test]
    public function a_student_of_another_organization_never_appears_nor_can_be_added(): void
    {
        $support = $this->makeClass('Apoio', support: true);

        $stranger = User::factory()->create();
        $theirOrganization = $stranger->personalOrganization();
        $theirClass = $this->makeClass('8.º F', teacher: $stranger, organization: $theirOrganization);
        $theirs = $this->enrol($theirClass, 'João Silva', organization: $theirOrganization);

        $this->assertSame([], $this->search($support, 'João'));

        $this->addExisting($support, $this->studentOf($theirs)->ulid)->assertSessionHasErrors('student_ulid');
        $this->assertSame([], $this->activeStudentIdsIn($support));
    }

    #[Test]
    public function a_colleagues_student_in_the_same_organization_is_not_reusable(): void
    {
        $support = $this->makeClass('Apoio', support: true);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $theirs = $this->enrol($this->makeClass('8.º G', teacher: $colleague), 'Beatriz Costa');

        $this->assertSame([], $this->search($support, 'Beatriz'));

        $this->addExisting($support, $this->studentOf($theirs)->ulid)->assertSessionHasErrors('student_ulid');
        $this->assertSame([], $this->activeStudentIdsIn($support));
    }

    #[Test]
    public function students_who_left_archived_classes_and_other_years_are_out_of_scope(): void
    {
        $support = $this->makeClass('Apoio', support: true);

        $this->enrol($this->makeClass('8.º F'), 'Ana Saiu', status: EnrollmentStatus::Left);

        $archived = $this->makeClass('8.º X');
        $archived->forceFill(['archived_at' => now()])->save();
        $this->enrol($archived, 'Ana Arquivada');

        $lastYear = $this->makeClass('7.º F', year: $this->year($this->organization, '2025/2026'));
        $this->enrol($lastYear, 'Ana Antiga');

        $this->assertSame([], $this->search($support, 'Ana'));
    }

    #[Test]
    public function an_empty_or_too_short_query_returns_nothing(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $this->enrol($this->makeClass('8.º F'), 'João Silva');

        $this->assertSame([], $this->search($support, ''));
        $this->assertSame([], $this->search($support, 'j'));
        $this->assertSame([], $this->search($support, 'Zacarias'));
    }

    #[Test]
    public function a_teacher_who_does_not_teach_the_support_class_cannot_search_it(): void
    {
        $support = $this->makeClass('Apoio', support: true);

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);

        $this->actingAs($colleague)->getJson("/classes/{$support->ulid}/existing-students?q=joao")->assertNotFound();
    }

    // ------------------------------------------------------------ a reutilização

    #[Test]
    public function adding_an_existing_student_reuses_the_same_student_and_creates_only_an_enrollment(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $origin = $this->makeClass('8.º F');
        $originEnrollment = $this->enrol($origin, 'João Silva', number: 12, processNumber: '004512', photoPath: 'student-photos/joao.jpg');
        $student = $this->studentOf($originEnrollment);

        $studentsBefore = $this->studentCount();
        $identitiesBefore = DB::table('student_identities')->count();

        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors()->assertRedirect("/classes/{$support->ulid}");

        $this->assertSame($studentsBefore, $this->studentCount());
        $this->assertSame($identitiesBefore, DB::table('student_identities')->count());
        $this->assertSame([$student->id], $this->activeStudentIdsIn($support));

        $reloaded = $this->studentOf($originEnrollment);
        $this->assertSame($student->pseudonym_code, $reloaded->pseudonym_code);
        $this->assertSame('João Silva', $reloaded->identity?->display_name);
        $this->assertSame('004512', $reloaded->identity?->school_number);
        $this->assertSame('student-photos/joao.jpg', $reloaded->identity?->photo_path);

        // A turma de origem fica exatamente como estava.
        $this->assertSame([$student->id], $this->activeStudentIdsIn($origin));
        $this->assertSame(12, $originEnrollment->fresh()->class_number);
        $this->assertSame(EnrollmentStatus::Active, $originEnrollment->fresh()->status);

        // A página da turma de apoio mostra a MESMA fotografia: o mesmo URL do aluno.
        $this->actingAs($this->teacher)->get("/classes/{$support->ulid}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('classes/Show')
                ->has('students', 1)
                ->where('students.0.name', 'João Silva'));

        $supportPhoto = $this->photoUrlOnPage($support);
        $originPhoto = $this->photoUrlOnPage($origin);
        $this->assertNotNull($supportPhoto);
        $this->assertSame($originPhoto, $supportPhoto);
    }

    protected function photoUrlOnPage(SchoolClass $class): ?string
    {
        $url = null;

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
            ->assertInertia(function (AssertableInertia $page) use (&$url): void {
                $url = $page->toArray()['props']['students'][0]['photo_url'] ?? null;
            });

        return $url;
    }

    #[Test]
    public function a_support_class_gathers_students_from_several_classes_without_a_base_class(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $students = collect(['8.º F' => 'Ana', '8.º G' => 'Bruno', '8.º H' => 'Carla'])
            ->map(fn (string $name, string $label) => $this->studentOf($this->enrol($this->makeClass($label), $name)));

        foreach ($students as $student) {
            $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();
        }

        $this->assertSame(3, $this->studentCount());
        $this->assertEqualsCanonicalizing($students->pluck('id')->all(), $this->activeStudentIdsIn($support));
        $this->assertFalse(Schema::hasColumn('classes', 'base_class_id'));
    }

    #[Test]
    public function the_same_student_cannot_be_added_twice_to_the_same_class(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $student = $this->studentOf($this->enrol($this->makeClass('8.º F'), 'João Silva'));

        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();
        $this->addExisting($support, $student->ulid)
            ->assertSessionHasErrors(['student_ulid' => 'Este aluno já pertence a esta turma.']);

        $this->assertSame([$student->id], $this->activeStudentIdsIn($support));
        $this->assertSame([true], array_column($this->search($support, 'João'), 'already_in_class'));
    }

    #[Test]
    public function the_support_enrollment_starts_today_not_at_the_start_of_the_year(): void
    {
        $this->travelTo(Carbon::parse('2026-12-03 10:00', 'Europe/Lisbon'));

        $support = $this->makeClass('Apoio', support: true);
        $student = $this->studentOf($this->enrol($this->makeClass('8.º F'), 'João Silva'));

        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();

        $enrollment = Enrollment::withoutGlobalScope('organization')->where('class_id', $support->id)->firstOrFail();
        $this->assertSame('2026-12-03', $enrollment->enrolled_on->toDateString());
        $this->assertTrue($enrollment->is_late_entry);
    }

    // ------------------------------------------------------------ a remoção

    #[Test]
    public function removing_from_the_support_class_keeps_the_student_the_photo_and_the_origin_enrollment(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $origin = $this->makeClass('8.º F');
        $originEnrollment = $this->enrol($origin, 'João Silva', photoPath: 'student-photos/joao.jpg');
        $student = $this->studentOf($originEnrollment);

        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();
        $supportEnrollment = Enrollment::withoutGlobalScope('organization')->where('class_id', $support->id)->firstOrFail();

        $this->actingAs($this->teacher)->delete("/classes/{$support->ulid}/students/{$supportEnrollment->ulid}")->assertRedirect();

        $this->assertSame([], $this->activeStudentIdsIn($support));
        $this->assertSame([$student->id], $this->activeStudentIdsIn($origin));
        $this->assertDatabaseHas('students', ['id' => $student->id]);
        $this->assertSame('student-photos/joao.jpg', $this->studentOf($originEnrollment)->identity?->photo_path);

        // E pode voltar mais tarde — mesmo aluno, nova inscrição.
        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();
        $this->assertSame([$student->id], $this->activeStudentIdsIn($support));
        $this->assertSame(1, $this->studentCount());
    }

    #[Test]
    public function removing_a_support_enrollment_with_history_is_refused_like_any_other(): void
    {
        $support = $this->makeClass('Apoio', support: true);
        $student = $this->studentOf($this->enrol($this->makeClass('8.º F'), 'João Silva'));
        $this->addExisting($support, $student->ulid)->assertSessionHasNoErrors();
        $supportEnrollment = Enrollment::withoutGlobalScope('organization')->where('class_id', $support->id)->firstOrFail();

        app(CurrentOrganization::class)->runFor($this->organization, fn (): EvidenceRecord => EvidenceRecord::create([
            'class_id' => $support->id,
            'enrollment_id' => $supportEnrollment->id,
            'kind' => EvidenceKind::Difficulty,
            'occurred_at' => '2026-10-01',
            'description' => 'Observação registada no apoio.',
            'created_by' => $this->teacher->id,
        ]));

        $this->actingAs($this->teacher)->delete("/classes/{$support->ulid}/students/{$supportEnrollment->ulid}")->assertRedirect();

        $this->assertDatabaseHas('enrollments', ['id' => $supportEnrollment->id]);
        $this->assertSame([$student->id], $this->activeStudentIdsIn($support));
    }
}
