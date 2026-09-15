<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Lesson;
use App\Models\LessonOutcome;
use App\Models\LessonStatus;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportType;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\TeacherAbsenceReason;
use App\Models\User;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\Export\SectionTables;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Aulas previstas e lecionadas» no relatório de turma (0.146.0).
 */
class ReportLessonsTest extends TestCase
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

        OrganizationSubscription::withoutGlobalScope('organization')->updateOrCreate(
            ['organization_id' => $this->organization->id],
            [
                'plan_id' => Plan::where('key', 'pro')->firstOrFail()->id,
                'status' => SubscriptionStatus::Active,
                'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
                'ends_at' => null,
            ],
        );
        app(Entitlements::class)->flush();
    }

    #[Test]
    public function the_section_is_a_count_that_is_never_rewritten(): void
    {
        $this->assertFalse(SectionCatalogue::isRewritable(SectionKey::ClassLessons));
        $this->assertNotContains(SectionKey::ClassLessons->value, SectionCatalogue::rewritableKeysFor(ReportType::SchoolClass));
    }

    #[Test]
    public function the_class_report_counts_each_kind_of_occurrence_with_a_chronological_detail(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $start = $this->period(1)->starts_on->copy();

            // A demo pode já trazer aulas: o teste conta só as suas.
            Lesson::query()->where('class_id', $class->id)->get()->each(function (Lesson $lesson): void {
                $lesson->attendances()->delete();
                $lesson->summary()->delete();
                $lesson->plan()->delete();
                $lesson->delete();
            });

            $this->lesson($class, $start->copy()->addDays(1), ['status' => LessonStatus::Taught, 'outcome' => LessonOutcome::Taught, 'lesson_number' => 1]);
            $this->lesson($class, $start->copy()->addDays(2), ['outcome' => LessonOutcome::TeacherAbsent, 'outcome_reason' => TeacherAbsenceReason::Training]);
            $this->lesson($class, $start->copy()->addDays(3), ['outcome' => LessonOutcome::ClassExternalActivity, 'outcome_note' => 'Visita de estudo', 'lesson_number' => 2]);
            // Legado: lecionada sem outcome.
            $this->lesson($class, $start->copy()->addDays(4), ['status' => LessonStatus::Taught, 'lesson_number' => 3]);
            $this->lesson($class, $start->copy()->addDays(5), ['lesson_number' => 4]);
        });

        $report = $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $this->period(1),
            sectionKeys: [SectionKey::ClassIdentification->value, SectionKey::ClassLessons->value],
        ));

        $section = $this->asTenant(fn () => $report->sections()->where('key', SectionKey::ClassLessons->value)->first());

        $this->assertNotNull($section);
        $this->assertSame([
            'planned' => 5,
            'counted_as_taught' => 3,
            'subject_development' => 2,
            'teacher_absent' => 1,
            'class_external_activity' => 1,
            'not_recorded' => 1,
        ], $section->data['totals']);
        $this->assertSame(
            ['taught', 'teacher_absent', 'class_external_activity', 'taught', 'not_recorded'],
            array_column($section->data['rows'], 'outcome'),
        );
        // O motivo de uma ausência do professor nunca chega ao relatório.
        $this->assertStringNotContainsString('training', (string) json_encode($section->data));
        $this->assertStringNotContainsString('Formação', (string) $section->body);

        $tables = SectionTables::for(SectionKey::ClassLessons->value, $section->data);
        $this->assertCount(2, $tables);
        $this->assertSame('Turma em outras atividades letivas — Visita de estudo', $tables[1]['rows'][2][3]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function lesson(SchoolClass $class, \DateTimeInterface $day, array $attributes): void
    {
        Lesson::create(array_merge([
            'class_id' => $class->id,
            'starts_at' => Carbon::instance($day)->setTime(9, 30),
            'ends_at' => Carbon::instance($day)->setTime(10, 20),
            'status' => LessonStatus::Prepared,
            'created_by' => $this->teacher->id,
        ], $attributes));
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
}
