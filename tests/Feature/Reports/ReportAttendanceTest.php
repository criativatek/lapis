<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\AttendanceStatus;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonAttendance;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\ReportCapabilities;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * As secções «Assiduidade» do módulo de relatórios — turma e individual (§ do
 * briefing de assiduidade).
 *
 * TRÊS ESTADOS, NUNCA DOIS: presença, falta e «não registada» são sempre
 * contados à parte, tal como no cartão de Evolução do Aluno e na página da
 * aula. E NÃO REESCREVÍVEL: a secção é uma contagem, e
 * `SectionCatalogue::isRewritable()` deve continuar a dizer que não.
 */
class ReportAttendanceTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->organization = $this->teacher->personalOrganization();
        $this->travelTo(Carbon::parse('2027-06-30 12:00:00'));
        $this->seed(EntitlementsSeeder::class);
        $this->givePlan('pro');
    }

    private function givePlan(string $key): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', $key)->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );

        app(Entitlements::class)->flush();
    }

    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function enrollment(int $classNumber = 1): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => $this->schoolClass()->enrollments()->where('class_number', $classNumber)->firstOrFail());
    }

    /**
     * Uma aula consolidada (1 presença, 1 falta) dentro do 1.º período, mais
     * uma lecionada sem registo — ambas dentro das datas do período.
     */
    private function seedAttendance(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $present = $this->enrollment(1);
            $absent = $this->enrollment(2);

            $recorded = Lesson::create([
                'class_id' => $class->id,
                'starts_at' => $this->period(1)->starts_on->copy()->addDays(5)->setTime(9, 30),
                'ends_at' => $this->period(1)->starts_on->copy()->addDays(5)->setTime(10, 20),
                'lesson_number' => 1,
                'status' => LessonStatus::Taught,
                'attendance_recorded_at' => now(),
                'attendance_recorded_by' => $this->teacher->id,
                'created_by' => $this->teacher->id,
            ]);

            LessonAttendance::create([
                'lesson_id' => $recorded->id,
                'enrollment_id' => $present->id,
                'student_id' => $present->student_id,
                'status' => AttendanceStatus::Present,
                'updated_by' => $this->teacher->id,
            ]);

            LessonAttendance::create([
                'lesson_id' => $recorded->id,
                'enrollment_id' => $absent->id,
                'student_id' => $absent->student_id,
                'status' => AttendanceStatus::Absent,
                'updated_by' => $this->teacher->id,
            ]);

            Lesson::create([
                'class_id' => $class->id,
                'starts_at' => $this->period(1)->starts_on->copy()->addDays(10)->setTime(9, 30),
                'ends_at' => $this->period(1)->starts_on->copy()->addDays(10)->setTime(10, 20),
                'lesson_number' => 2,
                'status' => LessonStatus::Taught,
                'attendance_recorded_at' => null,
                'created_by' => $this->teacher->id,
            ]);

            // Fora do período — nunca deve entrar na contagem escopada.
            Lesson::create([
                'class_id' => $class->id,
                'starts_at' => $this->period(1)->ends_on->copy()->addMonths(6)->setTime(9, 30),
                'ends_at' => $this->period(1)->ends_on->copy()->addMonths(6)->setTime(10, 20),
                'lesson_number' => 3,
                'status' => LessonStatus::Taught,
                'attendance_recorded_at' => null,
                'created_by' => $this->teacher->id,
            ]);
        });
    }

    private function sectionOf(Report $report, SectionKey $key): ?object
    {
        return $this->asTenant(fn () => $report->sections()->where('key', $key->value)->first());
    }

    // ----------------------------------------------------------- catálogo

    #[Test]
    public function neither_attendance_section_is_offered_to_the_writing_assistant(): void
    {
        $this->assertFalse(SectionCatalogue::isRewritable(SectionKey::ClassAttendance));
        $this->assertFalse(SectionCatalogue::isRewritable(SectionKey::StudentAttendance));

        $this->assertNotContains(
            SectionKey::ClassAttendance->value,
            SectionCatalogue::rewritableKeysFor(ReportType::SchoolClass),
        );
        $this->assertNotContains(
            SectionKey::StudentAttendance->value,
            SectionCatalogue::rewritableKeysFor(ReportType::Student),
        );
    }

    #[Test]
    public function a_base_plan_does_not_offer_either_attendance_section(): void
    {
        $this->givePlan('base');

        $classKeys = $this->asTenant(fn () => array_map(
            fn ($definition) => $definition->key,
            app(ReportCapabilities::class)->sectionsFor(ReportType::SchoolClass),
        ));
        $studentKeys = $this->asTenant(fn () => array_map(
            fn ($definition) => $definition->key,
            app(ReportCapabilities::class)->sectionsFor(ReportType::Student),
        ));

        $this->assertNotContains(SectionKey::ClassAttendance, $classKeys);
        $this->assertNotContains(SectionKey::StudentAttendance, $studentKeys);
    }

    // ----------------------------------------------------- relatório de turma

    #[Test]
    public function the_class_report_lists_present_absent_and_not_recorded_per_student(): void
    {
        $this->seedAttendance();

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(1),
            sectionKeys: [SectionKey::ClassIdentification->value, SectionKey::ClassAttendance->value],
        ));

        $section = $this->sectionOf($report, SectionKey::ClassAttendance);

        $this->assertNotNull($section);
        $this->assertNotNull($section->body);

        $rows = collect($section->data['rows']);
        $present = $rows->firstWhere('enrollment_id', $this->enrollment(1)->id);
        $absent = $rows->firstWhere('enrollment_id', $this->enrollment(2)->id);

        $this->assertSame(1, $present['present']);
        $this->assertSame(0, $present['absent']);
        // Não regista nada da aula #2 (sem consolidação): conta como
        // «não registada», nunca como presença.
        $this->assertSame(1, $present['not_recorded']);

        $this->assertSame(0, $absent['present']);
        $this->assertSame(1, $absent['absent']);
        $this->assertSame(1, $absent['not_recorded']);

        $this->assertNotContains(SectionKey::ClassAttendance->value, $section->sources ?? [], 'sources é uma coluna diferente');
    }

    #[Test]
    public function the_class_attendance_scope_never_includes_lessons_outside_the_period(): void
    {
        $this->seedAttendance();

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(1),
            sectionKeys: [SectionKey::ClassIdentification->value, SectionKey::ClassAttendance->value],
        ));

        $section = $this->sectionOf($report, SectionKey::ClassAttendance);
        $rows = collect($section->data['rows']);

        // Cada aluno elegível ganha exatamente UMA aula «não registada» — a
        // #2, dentro do período. A #3, seis meses depois do fim do período,
        // nunca conta para ninguém — daí o máximo de 1, nunca 2.
        $this->assertTrue($rows->every(fn (array $row) => $row['not_recorded'] <= 1));
        $this->assertGreaterThan(0, $rows->sum('not_recorded'));
    }

    // -------------------------------------------------- relatório individual

    #[Test]
    public function the_student_report_narrows_to_that_enrollments_own_attendance(): void
    {
        $this->seedAttendance();
        $absentEnrollment = $this->enrollment(2);

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forStudent(
            enrollment: $absentEnrollment,
            author: $this->teacher,
            period: $this->period(1),
            sectionKeys: [SectionKey::StudentIdentification->value, SectionKey::StudentAttendance->value],
        ));

        $section = $this->sectionOf($report, SectionKey::StudentAttendance);

        $this->assertNotNull($section);
        $this->assertSame(1, $section->data['totals']['recorded']);
        $this->assertSame(0, $section->data['totals']['present']);
        $this->assertSame(1, $section->data['totals']['absent']);
        $this->assertSame(1, $section->data['totals']['not_recorded']);
        $this->assertCount(2, $section->data['rows']);
    }

    #[Test]
    public function a_student_with_no_taught_lessons_gets_the_absence_sentence_not_a_zero(): void
    {
        // Um aluno de uma turma diferente, sem nenhuma aula lecionada.
        $otherClass = $this->asTenant(fn (): SchoolClass => SchoolClass::factory()
            ->recycle($this->organization)
            ->create(['academic_year_id' => $this->schoolClass()->academic_year_id]));

        $enrollment = $this->asTenant(function () use ($otherClass): Enrollment {
            $student = Student::factory()->recycle($this->organization)->create();
            StudentIdentity::create([
                'student_id' => $student->id,
                'organization_id' => $this->organization->id,
                'display_name' => 'Sem Aulas',
            ]);

            return Enrollment::create([
                'class_id' => $otherClass->id,
                'student_id' => $student->id,
                'enrolled_on' => '2026-09-01',
                'status' => 'active',
                'is_late_entry' => false,
            ]);
        });

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forStudent(
            enrollment: $enrollment,
            author: $this->teacher,
            period: null,
            sectionKeys: [SectionKey::StudentIdentification->value, SectionKey::StudentAttendance->value],
        ));

        $section = $this->sectionOf($report, SectionKey::StudentAttendance);

        $this->assertNotNull($section->body);
        $this->assertStringContainsString('Não existem aulas lecionadas', $section->body);
    }

    // --------------------------------------------------- isolamento entre organizações

    #[Test]
    public function attendance_from_another_organization_never_leaks_into_this_ones_report(): void
    {
        $this->seedAttendance();

        $otherTeacher = User::factory()->create(['email' => 'outra@lapis.test']);
        $otherOrganization = $otherTeacher->personalOrganization();

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $otherOrganization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );
        app(Entitlements::class)->flush();

        $otherClass = app(CurrentOrganization::class)->runFor($otherOrganization, function () use ($otherOrganization, $otherTeacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($otherOrganization)->create([
                'academic_year_id' => AcademicYear::factory()->recycle($otherOrganization)->create([
                    'starts_on' => '2026-09-01', 'ends_on' => '2027-06-30',
                ])->id,
            ]);
            $class->teachers()->attach($otherTeacher, ['role' => 'owner']);

            return $class;
        });

        $otherEnrollment = app(CurrentOrganization::class)->runFor($otherOrganization, function () use ($otherOrganization, $otherClass): Enrollment {
            $student = Student::factory()->recycle($otherOrganization)->create();
            StudentIdentity::create([
                'student_id' => $student->id,
                'organization_id' => $otherOrganization->id,
                'display_name' => 'De Outra Escola',
            ]);

            return Enrollment::create([
                'class_id' => $otherClass->id,
                'student_id' => $student->id,
                'enrolled_on' => '2026-09-01',
                'status' => 'active',
                'is_late_entry' => false,
            ]);
        });

        $report = app(CurrentOrganization::class)->runFor($otherOrganization, fn (): Report => app(CreateReport::class)->forStudent(
            enrollment: $otherEnrollment,
            author: $otherTeacher,
            period: null,
            sectionKeys: [SectionKey::StudentIdentification->value, SectionKey::StudentAttendance->value],
        ));

        $section = app(CurrentOrganization::class)->runFor($otherOrganization, fn () => $report->sections()->where('key', SectionKey::StudentAttendance->value)->first());

        $this->assertNotNull($section);
        // Nenhuma aula lecionada nesta turma nova — a mesma frase de
        // ausência de `StudentAttendanceComposer`, nunca um total emprestado
        // da organização de origem.
        $this->assertStringContainsString('Não existem aulas lecionadas', $section->body);
        $this->assertNull($section->data);
    }
}
