<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentStudentComment;
use App\Models\InstrumentType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\EnrollmentHistory;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The teacher's private comment on ONE student in an element of assessment
 * (`instrument_student_comments`): the endpoint, the grid and Comments props,
 * the student's own page, and the rule that it blocks removing the student.
 */
class InstrumentStudentCommentsTest extends TestCase
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

    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * @return array{instrument: Instrument, class: SchoolClass, enrollments: list<Enrollment>}
     */
    protected function scenario(string $status = 'prepared', string $label = '7.º A'): array
    {
        $org = $this->organization;
        $year = AcademicYear::factory()->recycle($org)->create();
        $period = AcademicPeriod::factory()->recycle($org)->for($year)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $class = SchoolClass::factory()->recycle($org)->create([
            'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => $label,
        ]);
        $class->teachers()->attach($this->user, ['role' => 'owner']);

        $enrollments = [];

        foreach ([1 => 'Aluno Um', 2 => 'Aluno Dois'] as $number => $name) {
            $student = Student::factory()->recycle($org)->create();
            StudentIdentity::create(['student_id' => $student->id, 'organization_id' => $org->id, 'display_name' => $name]);
            $enrollments[] = Enrollment::factory()->recycle($org)->create([
                'class_id' => $class->id, 'student_id' => $student->id, 'enrolled_on' => '2026-01-10', 'class_number' => $number,
            ]);
        }

        $instrument = app(InstrumentBuilder::class)->create($class, [
            'academic_period_id' => $period->id,
            'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
            'title' => 'Apresentação oral',
            'applied_on' => '2026-10-15',
            'status' => $status,
            'counts_toward_classification' => true,
            'purpose' => 'summative',
            'total_points' => 100,
        ], [
            ['code' => 'Q1', 'points_possible' => 50],
            ['code' => 'Q2', 'points_possible' => 50],
        ]);

        return ['instrument' => $instrument, 'class' => $class, 'enrollments' => $enrollments];
    }

    protected function url(Instrument $instrument, Enrollment $enrollment): string
    {
        return "/instruments/{$instrument->ulid}/students/{$enrollment->ulid}/comment";
    }

    protected function saveComment(Instrument $instrument, Enrollment $enrollment, ?string $body, int $lockVersion = 0)
    {
        return $this->actingAs($this->user)->put($this->url($instrument, $enrollment), ['body' => $body, 'lock_version' => $lockVersion]);
    }

    #[Test]
    public function the_owner_creates_updates_and_deletes_the_comment_with_an_empty_body(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();

            $this->saveComment($instrument, $one, '  Apanhado a copiar na Q2  ')->assertRedirect();
            $comment = InstrumentStudentComment::firstOrFail();
            $this->assertSame('Apanhado a copiar na Q2', $comment->body);
            $this->assertSame(1, $comment->lock_version);
            $this->assertSame($this->user->id, $comment->created_by);

            $this->saveComment($instrument, $one, 'Não teve tempo para a Q6', 1)->assertRedirect();
            $comment->refresh();
            $this->assertSame('Não teve tempo para a Q6', $comment->body);
            $this->assertSame(2, $comment->lock_version);
            $this->assertSame(1, InstrumentStudentComment::count());

            $this->saveComment($instrument, $one, '   ', 2)->assertRedirect();
            $this->assertSame(0, InstrumentStudentComment::count());

            $this->assertSame(
                ['instrument.student_comment_created', 'instrument.student_comment_updated', 'instrument.student_comment_deleted'],
                AuditEvent::where('event', 'like', 'instrument.student_comment_%')->orderBy('id')->pluck('event')->all(),
            );
        });
    }

    #[Test]
    public function an_empty_body_for_a_missing_comment_writes_nothing_and_audits_nothing(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();

            $this->saveComment($instrument, $one, '')->assertRedirect();

            $this->assertSame(0, InstrumentStudentComment::count());
            $this->assertSame(0, AuditEvent::where('event', 'like', 'instrument.student_comment_%')->count());
        });
    }

    #[Test]
    public function the_audit_trail_never_carries_the_text(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();

            $this->saveComment($instrument, $one, 'Segredo fictício sobre a criança')->assertRedirect();

            $events = AuditEvent::where('event', 'like', 'instrument.student_comment_%')->get();
            $this->assertCount(1, $events);
            $this->assertStringNotContainsString('Segredo fictício', json_encode($events->first()->toArray(), JSON_UNESCAPED_UNICODE));
            $this->assertSame($one->id, $events->first()->properties['enrollment_id']);
        });
    }

    #[Test]
    public function a_stale_lock_version_is_a_conflict_and_changes_nothing(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();

            $this->saveComment($instrument, $one, 'Primeira')->assertRedirect();

            $this->saveComment($instrument, $one, 'Outra janela', 0)
                ->assertSessionHasErrors(['body' => 'O comentário foi alterado noutra janela. Recarregue a página para ver a versão mais recente.']);
            $this->saveComment($instrument, $one, '', 0)->assertSessionHasErrors('body');

            $this->assertSame('Primeira', InstrumentStudentComment::firstOrFail()->body);
            $this->assertSame(1, InstrumentStudentComment::firstOrFail()->lock_version);
        });
    }

    #[Test]
    public function an_overlong_comment_is_a_validation_error(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();

            $this->saveComment($instrument, $one, str_repeat('a', 2001))->assertSessionHasErrors('body');
            $this->saveComment($instrument, $one, str_repeat('a', 2000))->assertSessionHasNoErrors();
        });
    }

    #[Test]
    public function a_colleague_who_does_not_teach_the_class_is_forbidden(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();
            $colleague = User::factory()->create();
            $this->organization->members()->attach($colleague, ['joined_at' => now()]);

            $this->withSession(['organization_id' => $this->organization->id])
                ->actingAs($colleague)
                ->put($this->url($instrument, $one), ['body' => 'Intruso', 'lock_version' => 0])
                ->assertForbidden();

            $this->assertSame(0, InstrumentStudentComment::count());
        });
    }

    #[Test]
    public function a_cancelled_instrument_refuses_it(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();
            $this->actingAs($this->user)->post("/instruments/{$instrument->ulid}/cancel", ['reason' => 'Anulado.']);
            $this->assertSame('cancelled', $instrument->fresh()->status->value);

            $this->saveComment($instrument, $one, 'Tarde demais')->assertForbidden();
            $this->assertSame(0, InstrumentStudentComment::count());
        });
    }

    #[Test]
    public function it_works_on_a_completed_correction(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();
            $instrument->forceFill(['status' => 'completed'])->save();

            $this->saveComment($instrument, $one, 'Depois de concluir')->assertRedirect()->assertSessionHasNoErrors();
            $this->assertSame(1, InstrumentStudentComment::count());
        });
    }

    #[Test]
    public function an_enrollment_of_another_class_is_a_404(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument] = $this->scenario();
            ['enrollments' => [$foreign]] = $this->scenario('prepared', '7.º B');

            $this->saveComment($instrument, $foreign, 'Outra turma')->assertNotFound();
            $this->assertSame(0, InstrumentStudentComment::count());
        });
    }

    #[Test]
    public function a_comment_blocks_removing_the_student_from_the_class(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'class' => $class, 'enrollments' => [$one, $two]] = $this->scenario();
            $this->saveComment($instrument, $one, 'Escrito sobre a criança')->assertRedirect();

            $blocking = app(EnrollmentHistory::class)->blocking($one);
            $this->assertContains('comentários nos elementos de avaliação', $blocking);
            $this->assertNotContains('comentários nos elementos de avaliação', app(EnrollmentHistory::class)->blocking($two));

            $this->actingAs($this->user)->delete("/classes/{$class->ulid}/students/{$one->ulid}");
            $this->assertNotNull($one->fresh());
        });
    }

    #[Test]
    public function the_grid_carries_each_students_comment_and_ulid(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one]] = $this->scenario();
            $this->saveComment($instrument, $one, 'Apanhado a copiar na Q2')->assertRedirect();

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}")
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('students.0.enrollment_ulid', $one->ulid)
                    ->where('students.0.comment', ['body' => 'Apanhado a copiar na Q2', 'lock_version' => 1])
                    ->where('students.1.comment', null));
        });
    }

    #[Test]
    public function the_comments_page_lists_every_student_with_their_comment_or_null(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $instrument, 'enrollments' => [$one, $two]] = $this->scenario();
            $this->saveComment($instrument, $two, 'Não teve tempo para a Q6')->assertRedirect();

            $this->actingAs($this->user)->get("/instruments/{$instrument->ulid}/comentarios")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('instruments/Comments')
                    ->has('students', 2)
                    ->where('students.0.enrollment_ulid', $one->ulid)
                    ->where('students.0.name', 'Aluno Um')
                    ->where('students.0.class_number', 1)
                    ->where('students.0.comment', null)
                    ->where('students.1.enrollment_ulid', $two->ulid)
                    ->where('students.1.comment', ['body' => 'Não teve tempo para a Q6', 'lock_version' => 1]));
        });
    }

    #[Test]
    public function the_student_page_shows_the_comments_of_this_class_newest_first_and_not_another_classes(): void
    {
        $this->inTenant(function (): void {
            ['instrument' => $older, 'class' => $class, 'enrollments' => [$one]] = $this->scenario();
            $newer = app(InstrumentBuilder::class)->create($class, [
                'academic_period_id' => $older->academic_period_id,
                'instrument_type_id' => $older->instrument_type_id,
                'title' => 'Teste final',
                'applied_on' => '2026-11-20',
                'status' => 'prepared',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                'total_points' => 100,
            ], [['code' => 'Q1', 'points_possible' => 100]]);

            $this->saveComment($older, $one, 'Primeiro comentário')->assertRedirect();
            $this->saveComment($newer, $one, 'Segundo comentário')->assertRedirect();

            // Another class, another student: never on this student's page.
            ['instrument' => $foreign, 'enrollments' => [$other]] = $this->scenario('prepared', '7.º B');
            $this->saveComment($foreign, $other, 'Comentário de outra turma')->assertRedirect();

            $this->actingAs($this->user)->get("/classes/{$class->ulid}/evolucao/{$one->ulid}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component('student-progress/Show')
                    ->has('instrumentComments', 2)
                    ->where('instrumentComments.0.instrument_title', 'Teste final')
                    ->where('instrumentComments.0.body', 'Segundo comentário')
                    ->where('instrumentComments.0.applied_on', '2026-11-20')
                    ->where('instrumentComments.0.url', route('instruments.comments', $newer->ulid))
                    ->where('instrumentComments.1.body', 'Primeiro comentário'));
        });
    }

    #[Test]
    public function the_student_page_has_no_comments_key_when_there_are_none(): void
    {
        $this->inTenant(function (): void {
            ['class' => $class, 'enrollments' => [$one]] = $this->scenario();

            $this->actingAs($this->user)->get("/classes/{$class->ulid}/evolucao/{$one->ulid}")
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page->missing('instrumentComments'));
        });
    }
}
