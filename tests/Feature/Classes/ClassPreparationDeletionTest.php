<?php

namespace Tests\Feature\Classes;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\ClassGroupMembership;
use App\Models\ClassStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\Lesson;
use App\Models\LessonStatus;
use App\Models\LessonSummary;
use App\Models\Organization;
use App\Models\RecurringLessonSlot;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * UMA TURMA CRIADA POR ENGANO sai já — sem arquivar e sem os três anos de
 * retenção — enquanto estiver em preparação e só tiver preparação: alunos sem
 * história, grupos, horário e as aulas que a vista semanal materializou desse
 * horário. Tudo o que é história continua a bloquear, e nunca se apaga um
 * aluno partilhado nem a inscrição dele noutra turma.
 */
class ClassPreparationDeletionTest extends TestCase
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

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    protected function makeClass(string $label = '7.º Z', ClassStatus $status = ClassStatus::Preparation): SchoolClass
    {
        return $this->inTenant(function () use ($label, $status): SchoolClass {
            $year = AcademicYear::query()->first() ?? AcademicYear::factory()->recycle($this->organization)->create([
                'starts_on' => '2026-09-14',
                'ends_on' => '2027-06-30',
            ]);

            $class = SchoolClass::factory()->recycle($this->organization)->create([
                'academic_year_id' => $year->getKey(),
                'subject_id' => Subject::factory()->recycle($this->organization)->create()->getKey(),
                'label' => $label,
                'status' => $status,
            ]);
            $class->teachers()->attach($this->teacher, ['role' => 'owner']);

            return $class;
        });
    }

    protected function enrol(SchoolClass $class, ?Student $student = null): Enrollment
    {
        return $this->inTenant(fn (): Enrollment => Enrollment::factory()->recycle($this->organization)->create([
            'class_id' => $class->getKey(),
            'student_id' => ($student ?? Student::factory()->recycle($this->organization)->create())->getKey(),
            'enrolled_on' => '2026-09-14',
        ]));
    }

    /**
     * Grupo com um aluno, um tempo do horário desse grupo e uma aula
     * materializada dele — a preparação completa de uma turma.
     *
     * @return array{group: ClassGroup, slot: RecurringLessonSlot, lesson: Lesson}
     */
    protected function prepare(SchoolClass $class, Enrollment $enrollment): array
    {
        return $this->inTenant(function () use ($class, $enrollment): array {
            $group = ClassGroup::factory()->recycle($this->organization)->create(['class_id' => $class->getKey()]);
            ClassGroupMembership::factory()->recycle($this->organization)->create([
                'class_group_id' => $group->getKey(),
                'enrollment_id' => $enrollment->getKey(),
            ]);
            $slot = RecurringLessonSlot::create([
                'class_id' => $class->getKey(),
                'class_group_id' => $group->getKey(),
                'day_of_week' => 1,
                'starts_at' => '08:15',
                'ends_at' => '09:05',
            ]);
            $lesson = Lesson::create([
                'class_id' => $class->getKey(),
                'class_group_id' => $group->getKey(),
                'recurring_lesson_slot_id' => $slot->getKey(),
                'starts_at' => '2026-09-14 08:15:00',
                'ends_at' => '2026-09-14 09:05:00',
                'lesson_number' => 1,
                'status' => LessonStatus::Preparation,
                'created_by' => $this->teacher->getKey(),
            ]);

            return ['group' => $group, 'slot' => $slot, 'lesson' => $lesson];
        });
    }

    /**
     * @return array{type: string, message: string}
     */
    protected function toast(): array
    {
        $flash = session('inertia.flash_data');
        $this->assertIsArray($flash, 'Nenhuma mensagem foi mostrada ao professor.');

        return $flash['toast'];
    }

    protected function exists(SchoolClass $class): bool
    {
        return DB::table('classes')->where('id', $class->getKey())->exists();
    }

    #[Test]
    public function an_empty_class_in_preparation_is_deleted_immediately_without_archiving(): void
    {
        $class = $this->makeClass();

        $this->actingAs($this->teacher)->delete("/classes/{$class->ulid}")->assertRedirect('/classes');

        $this->assertFalse($this->exists($class));
    }

    #[Test]
    public function a_class_with_only_preparation_is_deleted_with_its_preparation_and_the_shared_student_survives(): void
    {
        $class = $this->makeClass();
        $other = $this->makeClass('8.º F', ClassStatus::Active);
        $otherEnrollment = $this->enrol($other);
        $shared = $this->inTenant(fn (): Student => Student::query()->findOrFail($otherEnrollment->student_id));
        $enrollment = $this->enrol($class, $shared);
        $prepared = $this->prepare($class, $enrollment);

        $this->actingAs($this->teacher)->delete("/classes/{$class->ulid}")->assertRedirect('/classes');

        $this->assertFalse($this->exists($class));
        $this->assertFalse(DB::table('enrollments')->where('id', $enrollment->getKey())->exists());
        $this->assertFalse(DB::table('class_groups')->where('id', $prepared['group']->getKey())->exists());
        $this->assertFalse(DB::table('recurring_lesson_slots')->where('id', $prepared['slot']->getKey())->exists());
        $this->assertFalse(DB::table('lessons')->where('id', $prepared['lesson']->getKey())->exists());
        $this->assertFalse(DB::table('class_group_memberships')->where('enrollment_id', $enrollment->getKey())->exists());

        // O aluno partilhado e a sua inscrição na outra turma ficam intactos.
        $this->assertTrue(DB::table('students')->where('id', $shared->getKey())->exists());
        $this->assertTrue(DB::table('enrollments')->where('id', $otherEnrollment->getKey())->exists());
        $this->assertTrue($this->exists($other));
    }

    #[Test]
    public function a_class_in_preparation_with_an_instrument_is_refused_with_a_readable_message(): void
    {
        $class = $this->makeClass();
        $this->inTenant(fn () => Instrument::factory()->recycle($this->organization)->for($class, 'schoolClass')->create());

        $response = $this->actingAs($this->teacher)->delete("/classes/{$class->ulid}");

        $response->assertRedirect();
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame('error', $this->toast()['type']);
        $this->assertStringContainsString('elementos de avaliação', $this->toast()['message']);
        $this->assertStringContainsString('arquivar', $this->toast()['message']);
        $this->assertTrue($this->exists($class));
    }

    #[Test]
    public function a_lesson_with_a_summary_or_already_taught_blocks_and_nothing_is_deleted(): void
    {
        foreach (['summary', 'taught'] as $case) {
            $class = $this->makeClass("7.º {$case}");
            $enrollment = $this->enrol($class);
            $prepared = $this->prepare($class, $enrollment);

            $this->inTenant(function () use ($case, $prepared): void {
                $case === 'summary'
                    ? LessonSummary::create(['lesson_id' => $prepared['lesson']->getKey(), 'content' => 'Sumário escrito.'])
                    : $prepared['lesson']->update(['status' => LessonStatus::Taught]);
            });

            $this->actingAs($this->teacher)->delete("/classes/{$class->ulid}")->assertRedirect();

            $this->assertStringContainsString('aulas lecionadas', $this->toast()['message']);
            $this->assertTrue($this->exists($class));
            $this->assertTrue(DB::table('lessons')->where('id', $prepared['lesson']->getKey())->exists());
            $this->assertTrue(DB::table('enrollments')->where('id', $enrollment->getKey())->exists());
        }
    }

    #[Test]
    public function an_active_class_still_has_to_be_archived_first(): void
    {
        $class = $this->makeClass(status: ClassStatus::Active);

        $this->actingAs($this->teacher)->delete("/classes/{$class->ulid}")->assertRedirect();

        $this->assertStringContainsString('Arquive', $this->toast()['message']);
        $this->assertTrue($this->exists($class));
    }

    #[Test]
    public function only_the_owner_may_delete_and_another_organization_gets_not_found(): void
    {
        $class = $this->makeClass();

        $colleague = User::factory()->create();
        $colleague->organizations()->attach($this->organization, ['joined_at' => now()]);
        $this->inTenant(fn () => $class->teachers()->attach($colleague, ['role' => 'co_teacher']));
        $this->withSession(['organization_id' => $this->organization->id])->actingAs($colleague)->delete("/classes/{$class->ulid}")->assertForbidden();

        $this->actingAs(User::factory()->create())->delete("/classes/{$class->ulid}")->assertNotFound();

        $this->assertTrue($this->exists($class));
    }

    #[Test]
    public function the_page_offers_deletion_only_when_eligible(): void
    {
        $eligible = $this->makeClass('7.º A');
        $withHistory = $this->makeClass('7.º B');
        $this->inTenant(fn () => Instrument::factory()->recycle($this->organization)->for($withHistory, 'schoolClass')->create());
        $active = $this->makeClass('7.º C', ClassStatus::Active);

        foreach ([[$eligible, true], [$withHistory, false], [$active, false]] as [$class, $expected]) {
            $this->actingAs($this->teacher)->get("/classes/{$class->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('schoolClass.can_delete_in_preparation', $expected));
        }
    }
}
