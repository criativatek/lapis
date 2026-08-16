<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\Scale;
use App\Models\ScaleLevel;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The «Autoavaliação» column of Resultados, end to end.
 *
 * It shows one thing and one thing only: the answer the student gave to the
 * question that asked for an overall judgement. Not the average of what they
 * said about each domain, not the first scale answer that happens to be
 * findable, not the last — an overall judgement they did not make is one nobody
 * may make for them (§8, §11).
 */
class GlobalSelfAssessmentInResultsTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function period(int $sequence): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * @return list<Enrollment>
     */
    private function enrollments(): array
    {
        return $this->asTenant(fn (): array => $this->schoolClass()->enrollments()->orderBy('class_number')->get()->all());
    }

    private function levelWithCode(string $code): ScaleLevel
    {
        return $this->asTenant(fn (): ScaleLevel => Scale::where('name', 'Escala 1 a 5')->firstOrFail()
            ->levels()->where('code', $code)->firstOrFail());
    }

    /**
     * Fills in the form the way the student does: the overall judgement, and
     * optionally the same level against every domain.
     */
    private function submit(Enrollment $enrollment, AcademicPeriod $period, ?int $globalLevelId, ?int $domainLevelId = null): void
    {
        $class = $this->schoolClass();
        $questions = $this->asTenant(fn () => app(SelfAssessmentTemplateProvider::class)->forClass($class)->questions);

        $payload = [$questions->firstWhere('role', SelfAssessmentQuestionRole::Global)->id => $globalLevelId];

        if ($domainLevelId !== null) {
            foreach ($questions->whereNotNull('domain_id') as $question) {
                $payload[$question->id] = $domainLevelId;
            }
        }

        $this->actingAs($this->teacher)
            ->post("/classes/{$class->ulid}/self-assessments/{$period->ulid}/{$enrollment->ulid}", ['answers' => $payload])
            ->assertRedirect();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function columnFor(Enrollment $enrollment, AcademicPeriod $period): ?array
    {
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));

        foreach ($progression['students'] as $student) {
            if ($student['enrollment_id'] !== $enrollment->id) {
                continue;
            }

            foreach ($student['periods'] as $row) {
                if ($row['period_id'] === $period->id) {
                    /** @var array<string, mixed>|null $selfAssessment */
                    $selfAssessment = $row['self_assessment'];

                    return $selfAssessment;
                }
            }
        }

        $this->fail('O aluno ou o período não apareceram em Resultados.');
    }

    #[Test]
    public function the_level_the_student_proposed_is_the_one_the_column_shows(): void
    {
        [$student] = $this->enrollments();
        $period = $this->period(1);
        $level = $this->levelWithCode('4');

        $this->submit($student, $period, $level->id);

        $column = $this->columnFor($student, $period);

        $this->assertNotNull($column);
        // The VALUE the student proposed — a 4. The qualitative mention travels
        // with it, for the colour and the tooltip, and never in its place (§1).
        $this->assertSame('4', $column['code']);
        $this->assertSame($level->label, $column['label']);
        $this->assertSame($level->sequence, $column['sequence']);
    }

    #[Test]
    public function the_column_shows_the_number_and_not_the_qualitative_mention(): void
    {
        $screen = (string) file_get_contents(resource_path('js/pages/results/Show.vue'));

        // The cell's content is the value on the scale…
        $this->assertStringContainsString('{{ row.self_assessment.code }}', $screen);
        // …and «Bom» is not what stands in the column.
        $this->assertStringNotContainsString('>{{ row.self_assessment.label }}<', $screen);
        // The mention is still there, as support.
        $this->assertStringContainsString('${row.self_assessment.label}', $screen);
    }

    #[Test]
    public function every_domain_answered_and_the_overall_one_left_blank_shows_nothing(): void
    {
        [$student] = $this->enrollments();
        $period = $this->period(1);

        // Five domain answers and no overall judgement. The column is «—»: the
        // average of the five would be a sentence the student never said.
        $this->submit($student, $period, null, $this->levelWithCode('5')->id);

        $this->assertNull($this->columnFor($student, $period));

        // …and the domain answers are still there, kept for the reading that
        // does use them.
        $progression = $this->asTenant(fn (): array => app(BuildResultsProgression::class)->for($this->schoolClass()));
        $row = collect($progression['students'])->firstWhere('enrollment_id', $student->id);
        $answered = collect($row['periods'][0]['domains'])->filter(fn (array $domain): bool => $domain['self_assessment'] !== null);

        $this->assertGreaterThan(0, $answered->count());
    }

    #[Test]
    public function an_answer_given_in_another_period_does_not_appear_in_this_one(): void
    {
        [$student] = $this->enrollments();
        $first = $this->period(1);
        $second = $this->period(2);

        $this->submit($student, $second, $this->levelWithCode('3')->id);

        $this->assertNull($this->columnFor($student, $first));
        $this->assertSame($this->levelWithCode('3')->label, $this->columnFor($student, $second)['label']);
    }

    #[Test]
    public function another_students_answer_does_not_appear(): void
    {
        [$first, $second] = $this->enrollments();
        $period = $this->period(1);

        $this->submit($first, $period, $this->levelWithCode('2')->id);

        $this->assertSame($this->levelWithCode('2')->label, $this->columnFor($first, $period)['label']);
        $this->assertNull($this->columnFor($second, $period));
    }

    #[Test]
    public function a_self_assessment_whose_template_never_asked_for_an_overall_judgement_shows_nothing(): void
    {
        [$student] = $this->enrollments();
        $period = $this->period(1);
        $level = $this->levelWithCode('5');

        $this->asTenant(function () use ($student, $period, $level): void {
            $class = $this->schoolClass();
            $domain = Domain::firstOrFail();

            // A template as it was authored before roles existed: one question,
            // about a domain, and nothing that asked for an overall judgement.
            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->id,
                'name' => 'Autoavaliação anterior',
                'is_active' => true,
            ]);
            $question = $template->questions()->create([
                'domain_id' => $domain->id,
                'prompt' => "Como te avalias em {$domain->name}?",
                'answer_kind' => 'scale',
                'scale_id' => $level->scale_id,
                'sequence' => 1,
            ]);

            $selfAssessment = SelfAssessment::create([
                'enrollment_id' => $student->id,
                'academic_period_id' => $period->id,
                'self_assessment_template_id' => $template->id,
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student,
                'submitted_at' => now(),
            ]);
            $selfAssessment->responses()->create([
                'self_assessment_question_id' => $question->id,
                'scale_level_id' => $level->id,
            ]);
        });

        // A scale answer exists, and it is not an overall judgement. Nothing is
        // promoted into the column for want of a better candidate (§11).
        $this->assertNull($this->columnFor($student, $period));
    }
}
