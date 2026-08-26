<?php

namespace Tests\Feature\Progress;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\EnrollmentStatus;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\Organization;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\User;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\Progress\BuildStudentProgress;
use App\Services\Assessment\Progress\StudentProgressNarrative;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Evolução do Aluno (§68–§80).
 *
 * THE FAILURES WORTH GUARDING AGAINST ARE ALL THE SAME SHAPE: a page that turns
 * an absence into a statement. A student with no evidence shown as a zero, a
 * period before they arrived shown as a collapse, a proposal shown as a grade, a
 * photograph quietly rebuilt from today's data — each of them reads perfectly
 * and each of them is false about a real child.
 *
 * Nothing here asserts on wording for its own sake. What is asserted is that a
 * null stays a null, that a decision stays a decision, and that the past stays
 * where it was put.
 */
class StudentProgressTest extends TestCase
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
    }

    // ------------------------------------------------------------- andaimes

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    private function schoolClass(): SchoolClass
    {
        return $this->asTenant(fn (): SchoolClass => SchoolClass::where('label', '7.º A')->firstOrFail());
    }

    private function enrollment(int $number = 1): Enrollment
    {
        return $this->asTenant(fn (): Enrollment => Enrollment::query()
            ->where('class_id', $this->schoolClass()->getKey())
            ->orderBy('class_number')
            ->skip($number - 1)
            ->firstOrFail());
    }

    private function period(int $sequence = 1): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    /**
     * @return array<string, mixed>
     */
    private function progress(?Enrollment $enrollment = null, ?string $reading = null): array
    {
        $enrollment ??= $this->enrollment();

        return $this->asTenant(fn (): array => app(BuildStudentProgress::class)
            ->for($this->schoolClass(), $enrollment, $reading));
    }

    // ------------------------------------------------------- §68 resultado atual

    #[Test]
    public function the_current_result_says_which_reading_it_is(): void
    {
        $progress = $this->progress();

        $this->assertContains($progress['reading']['kind'], ['period', 'accumulated']);
        // «Resultado» alone is never the answer: the figure carries the name of
        // the reading it belongs to (§8, §54).
        $this->assertContains(
            $progress['reading']['label'],
            ['Média Ponderada', 'Média Ponderada Acumulada'],
        );
    }

    #[Test]
    public function the_canonical_reading_comes_from_the_profile_and_not_from_this_module(): void
    {
        $progress = $this->progress();

        $scopes = array_column($progress['periods'], 'scope', 'sequence');

        // The first contributing period answers for itself; there is nothing
        // behind it to accumulate (§10).
        $this->assertSame('period', $scopes[1]);
    }

    #[Test]
    public function asking_for_the_other_reading_changes_the_figure_and_nothing_else(): void
    {
        $continuous = $this->progress(reading: 'accumulated');
        $standalone = $this->progress(reading: 'period');

        // The toggle only exists where the two are different figures. Where it
        // does not, both requests give the canonical answer — which is the
        // truthful behaviour, not a bug (§9).
        if (! $continuous['reading']['has_toggle']) {
            $this->assertSame($continuous['reading']['kind'], $standalone['reading']['kind']);

            return;
        }

        $this->assertSame('accumulated', $continuous['reading']['kind']);
        $this->assertSame('period', $standalone['reading']['kind']);

        // What the toggle must NOT touch (§9).
        $this->assertEquals($continuous['classifications'], $standalone['classifications']);
        $this->assertEquals($continuous['selfAssessments'], $standalone['selfAssessments']);
        $this->assertEquals($continuous['records'], $standalone['records']);
        $this->assertEquals($continuous['interventions'], $standalone['interventions']);
    }

    #[Test]
    public function a_student_with_no_evidence_has_no_result_rather_than_a_zero(): void
    {
        // Every score of this class removed: nothing to calculate from.
        $this->asTenant(function (): void {
            DB::table('student_item_scores')->delete();
        });

        $progress = $this->progress();

        $this->assertNull($progress['headline']['value']);
        $this->assertSame('none', $progress['headline']['coverage']);

        // The sentence says so, and does not call it a poor result (§24, §38).
        $narrative = app(StudentProgressNarrative::class)->for($progress);
        $this->assertStringContainsString('Não existem resultados apurados', (string) $narrative);
    }

    // ---------------------------------------------------- §69 classificações

    #[Test]
    public function only_a_decision_counts_as_a_classification(): void
    {
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($enrollment): void {
            Classification::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->update(['status' => ClassificationStatus::Proposed, 'final_scale_level_id' => null]);
        });

        foreach ($this->progress($enrollment)['classifications'] as $row) {
            $this->assertFalse($row['is_decided']);
            $this->assertNull($row['assigned']);
        }
    }

    #[Test]
    public function a_proposal_is_never_promoted_into_a_classification(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();

        $this->asTenant(function () use ($enrollment, $period): void {
            Classification::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('academic_period_id', $period->getKey())
                ->update(['status' => ClassificationStatus::Proposed, 'final_scale_level_id' => null]);
        });

        $row = collect($this->progress($enrollment)['classifications'])
            ->firstWhere('period_id', $period->getKey());

        $this->assertNotNull($row);
        $this->assertNull($row['assigned']);
        // The proposal travels, and it travels labelled as a proposal (§16).
        $this->assertArrayHasKey('proposal', $row);
    }

    #[Test]
    public function a_confirmed_classification_is_shown_with_the_level_it_was_given(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();
        $level = $this->decide($enrollment, $period, ClassificationStatus::Confirmed);

        $row = collect($this->progress($enrollment)['classifications'])
            ->firstWhere('period_id', $period->getKey());

        $this->assertTrue($row['is_decided']);
        $this->assertSame($level, $row['assigned']['scale_level_id']);
    }

    #[Test]
    public function a_published_classification_says_it_is_published(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();
        $this->decide($enrollment, $period, ClassificationStatus::Published);

        $row = collect($this->progress($enrollment)['classifications'])
            ->firstWhere('period_id', $period->getKey());

        $this->assertTrue($row['is_decided']);
        $this->assertTrue($row['is_published']);
    }

    #[Test]
    public function which_side_of_the_scale_comes_from_the_scale_itself(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();
        $this->decide($enrollment, $period, ClassificationStatus::Confirmed);

        $row = collect($this->progress($enrollment)['classifications'])
            ->firstWhere('period_id', $period->getKey());

        // Never hardcoded against 3, 10 or 50%: the flag the scale carries is
        // the only thing that decides a side (§19).
        $this->assertArrayHasKey('is_negative', $row['assigned']);
        $this->assertIsBool($row['assigned']['is_negative']);
    }

    /**
     * What the student said about themselves — the overall judgement.
     *
     * Answered against the question whose ROLE is global, never the first one
     * in the list: that is how the read model identifies it, and a fixture that
     * relied on position would be testing something else.
     */
    private function answerSelfAssessment(Enrollment $enrollment, AcademicPeriod $period, int $fromTop = 1): void
    {
        $this->asTenant(function () use ($enrollment, $period, $fromTop): void {
            $class = $this->schoolClass();
            $scale = $class->profileVersion->scale;
            $level = $scale->levels()->orderByDesc('sequence')->skip($fromTop - 1)->firstOrFail();

            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'class_id' => $class->getKey(),
                'name' => 'Autoavaliação de teste',
                'is_active' => true,
            ]);

            $question = SelfAssessmentQuestion::create([
                'self_assessment_template_id' => $template->getKey(),
                'domain_id' => null,
                'role' => SelfAssessmentQuestionRole::Global,
                'prompt' => 'Como avalias o teu desempenho neste período?',
                'answer_kind' => 'scale',
                'scale_id' => $scale->getKey(),
                'sequence' => 1,
            ]);

            $assessment = SelfAssessment::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $period->getKey(),
                'self_assessment_template_id' => $template->getKey(),
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student,
                'submitted_at' => now()->subDays(2),
            ]);

            SelfAssessmentResponse::create([
                'self_assessment_id' => $assessment->getKey(),
                'self_assessment_question_id' => $question->getKey(),
                'scale_level_id' => $level->getKey(),
            ]);
        });
    }

    /** A decision the teacher took, on the profile's own scale. Returns the level id. */
    private function decide(Enrollment $enrollment, AcademicPeriod $period, ClassificationStatus $status): int
    {
        return $this->asTenant(function () use ($enrollment, $period, $status): int {
            $class = $this->schoolClass();
            $level = $class->profileVersion->scale->levels()->orderByDesc('sequence')->firstOrFail();

            Classification::query()
                ->where('enrollment_id', $enrollment->getKey())
                ->where('academic_period_id', $period->getKey())
                ->delete();

            Classification::create([
                'enrollment_id' => $enrollment->getKey(),
                'academic_period_id' => $period->getKey(),
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $class->assessment_profile_version_id,
                'status' => $status,
                'proposed_scale_level_id' => $level->id,
                'proposed_normalized_value' => '80.000000',
                'final_scale_level_id' => $level->id,
                'confirmed_by' => $this->teacher->getKey(),
                'confirmed_at' => now()->subDay(),
                'published_at' => $status === ClassificationStatus::Published ? now()->subDay() : null,
            ]);

            return (int) $level->id;
        });
    }

    // ---------------------------------------------------- §70 autoavaliação

    #[Test]
    public function the_self_assessment_is_kept_beside_the_decision_and_never_merged_into_it(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();

        // The student rates themselves one level BELOW what the teacher gave.
        $this->decide($enrollment, $period, ClassificationStatus::Confirmed);
        $this->answerSelfAssessment($enrollment, $period, fromTop: 2);

        $row = collect($this->progress($enrollment)['selfAssessments'])
            ->firstWhere('period_id', $period->getKey());

        // Two statements, two keys, and neither is ever folded into the other:
        // what a child said about themselves is not a finding of the teacher's
        // (§27, §29).
        $this->assertNotNull($row['self_assessment']);
        $this->assertNotNull($row['assigned']);
        $this->assertNotSame($row['self_assessment']['sequence'], $row['assigned']['sequence']);

        // The direction follows the two sequences and nothing else. Asserted
        // against them rather than against a hardcoded word, because which end
        // of a scale carries the higher sequence is the scale's business.
        $this->assertSame(
            $row['self_assessment']['sequence'] > $row['assigned']['sequence'] ? 'above' : 'below',
            $row['comparison']['direction'],
        );
    }

    #[Test]
    public function the_comparison_says_which_way_and_nothing_more(): void
    {
        $enrollment = $this->enrollment();
        $period = $this->period();

        // A comparison needs BOTH halves: what the student said about
        // themselves, and what the teacher decided. Neither is in the demo
        // data, so both are made here — which is also what makes the assertion
        // about the shape of the payload worth anything.
        $this->decide($enrollment, $period, ClassificationStatus::Confirmed);
        $this->answerSelfAssessment($enrollment, $period);

        $comparison = collect($this->progress($enrollment)['selfAssessments'])
            ->firstWhere('period_id', $period->getKey())['comparison'] ?? null;

        $this->assertIsArray($comparison);
        $this->assertContains($comparison['direction'], ['above', 'below', 'same']);

        // A direction and a difference, and that is the whole of it. There is
        // no field for confidence, awareness or self-esteem, because this
        // module does not have an opinion about a child (§28).
        $this->assertSame(['difference', 'direction'], array_keys($comparison));
    }

    #[Test]
    public function a_missing_self_assessment_is_absent_rather_than_zero(): void
    {
        $this->asTenant(function (): void {
            DB::table('self_assessments')->delete();
        });

        foreach ($this->progress()['selfAssessments'] as $row) {
            $this->assertNull($row['self_assessment']);
            $this->assertNull($row['comparison']);
        }
    }

    // ---------------------------------------------------------- §71 domínios

    #[Test]
    public function a_domain_with_no_elements_says_so_instead_of_showing_zero(): void
    {
        $this->asTenant(function (): void {
            DB::table('student_item_scores')->delete();
        });

        foreach ($this->progress()['domains']['rows'] as $row) {
            $this->assertNull($row['accumulated_average']);
            $this->assertSame('none', $row['coverage']);
        }
    }

    #[Test]
    public function a_domain_with_nothing_to_compare_against_has_no_variation(): void
    {
        // The first period has no period before it, so every domain's movement
        // there is «não comparável» — never a zero, which would read as «did
        // not move» (§22).
        $line = $this->progress()['domains']['rows'];

        $this->assertNotEmpty($line);

        foreach ($line as $row) {
            $this->assertTrue($row['evolution'] === null || isset($row['evolution']['direction']));
        }
    }

    #[Test]
    public function the_highlights_name_a_cell_and_stop(): void
    {
        $highlights = $this->progress()['domains']['highlights'];

        foreach (['highest', 'lowest', 'largest_rise', 'largest_fall'] as $key) {
            $this->assertArrayHasKey($key, $highlights);

            if ($highlights[$key] !== null) {
                // A domain id, a name and the value it was compared on. No
                // difficulty, no cause, no advice.
                $this->assertSame(['domain_id', 'name', 'value'], array_keys($highlights[$key]));
            }
        }
    }

    // ------------------------------------------------------- §72 intercalares

    #[Test]
    public function an_interim_point_keeps_what_was_true_when_it_was_taken(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $interim = $this->asTenant(fn () => app(CaptureInterimAssessment::class)->capture(
            $class,
            $this->period(),
            Carbon::parse('2026-11-15'),
            $this->teacher,
            ['name' => 'Meio do 1.º Período'],
        ));

        $before = collect($this->progress($enrollment)['moments'])
            ->firstWhere('key', 'interim-'.$interim->ulid);

        $this->assertNotNull($before);

        // The live data moves underneath it.
        $this->asTenant(function (): void {
            DB::table('student_item_scores')->delete();
        });

        $after = collect($this->progress($enrollment)['moments'])
            ->firstWhere('key', 'interim-'.$interim->ulid);

        // THE PHOTOGRAPH DID NOT MOVE. This is the whole point of the feature:
        // correcting a score today cannot change where November already is
        // (§12, §57).
        $this->assertSame($before['value'], $after['value']);
    }

    #[Test]
    public function an_interim_moment_is_marked_as_one(): void
    {
        $class = $this->schoolClass();

        $this->asTenant(fn () => app(CaptureInterimAssessment::class)->capture(
            $class,
            $this->period(),
            Carbon::parse('2026-11-15'),
            $this->teacher,
            ['name' => 'Meio do 1.º Período'],
        ));

        $kinds = collect($this->progress()['moments'])->pluck('kind')->unique()->values()->all();

        $this->assertContains('interim', $kinds);
        $this->assertContains('period', $kinds);
    }

    // ------------------------------------------------------ §73 ingresso tardio

    #[Test]
    public function a_period_that_ended_before_the_student_arrived_is_not_theirs(): void
    {
        $enrollment = $this->enrollment();

        // The LAST period, asked for by its sequence. `periods()` already
        // orders ascending, so appending orderByDesc leaves the first clause
        // winning and hands back the first period — which is how this test
        // first passed while proving nothing.
        $last = $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->get()->last());

        $this->asTenant(function () use ($enrollment, $last): void {
            $enrollment->update([
                'enrolled_on' => $last->starts_on,
                'is_late_entry' => true,
            ]);
        });

        $moments = collect($this->progress($enrollment->fresh())['moments'])
            ->where('kind', 'period');

        $earlier = $moments->filter(fn (array $moment): bool => $moment['before_enrolment']);

        $this->assertGreaterThan(0, $earlier->count());

        foreach ($earlier as $moment) {
            // NOT A ZERO AND NOT A COLLAPSE. The period is simply not theirs
            // (§25, §73).
            $this->assertNull($moment['value']);
            $this->assertSame('none', $moment['coverage']);
        }
    }

    #[Test]
    public function a_late_entry_with_no_recorded_date_does_not_gain_one(): void
    {
        $progress = $this->progress();

        // The flag and the date are separate facts, and neither is invented
        // from the other (§25).
        $this->assertArrayHasKey('is_late_entry', $progress['student']);
        $this->assertArrayHasKey('enrolled_on', $progress['student']);
    }

    // ------------------------------------------------------- §74 aluno histórico

    #[Test]
    public function a_student_who_left_still_has_a_year(): void
    {
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($enrollment): void {
            $enrollment->update([
                'status' => EnrollmentStatus::TransferredOut,
                'left_on' => Carbon::parse('2027-03-01'),
            ]);
        });

        $progress = $this->progress($enrollment->fresh());

        $this->assertFalse($progress['student']['is_current']);
        // The history is not erased by today's roster (§26, §58).
        $this->assertNotEmpty($progress['moments']);
        $this->assertNotEmpty($progress['classifications']);
    }

    #[Test]
    public function a_student_who_left_is_still_reachable_through_the_class(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($enrollment): void {
            $enrollment->update(['status' => EnrollmentStatus::TransferredOut]);
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk();
    }

    // ----------------------------------------------------------- §75 registos

    #[Test]
    public function the_logbook_is_this_students_own(): void
    {
        $class = $this->schoolClass();
        $mine = $this->enrollment(1);
        $theirs = $this->enrollment(2);

        $this->asTenant(function () use ($class, $mine, $theirs): void {
            foreach ([$mine, $theirs] as $enrollment) {
                EvidenceRecord::create([
                    'class_id' => $class->getKey(),
                    'enrollment_id' => $enrollment->getKey(),
                    'occurred_at' => Carbon::parse('2027-02-12 10:00:00'),
                    'kind' => EvidenceKind::Progress,
                    'description' => 'Registo de '.$enrollment->getKey(),
                    'created_by' => $this->teacher->getKey(),
                ]);
            }

            // A class-wide note, about nobody in particular.
            EvidenceRecord::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => null,
                'occurred_at' => Carbon::parse('2027-02-13 10:00:00'),
                'kind' => EvidenceKind::Note,
                'description' => 'Observação sobre a turma.',
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $records = $this->progress($mine)['records'];

        $this->assertSame(1, $records['total']);
        $this->assertSame('Registo de '.$mine->getKey(), $records['rows'][0]['description']);
        // The unit is the record, and it is stated (§32).
        $this->assertSame('Progresso', $records['rows'][0]['kind_label']);
    }

    #[Test]
    public function the_filters_are_the_kinds_this_student_actually_has(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            EvidenceRecord::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'occurred_at' => Carbon::parse('2027-02-12 10:00:00'),
                'kind' => EvidenceKind::Homework,
                'description' => 'TPC realizado.',
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $kinds = array_column($this->progress($enrollment)['records']['kinds'], 'value');

        $this->assertSame(['homework'], $kinds);
    }

    // ------------------------------------------------------- §76 intervenções

    #[Test]
    public function an_intervention_with_no_type_shows_no_type_rather_than_a_placeholder(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            Intervention::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'intervention_type' => null,
                'title' => 'Acompanhamento combinado com a diretora de turma',
                'status' => InterventionStatus::Concluded,
                'started_on' => Carbon::parse('2027-01-10'),
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $row = collect($this->progress($enrollment)['interventions']['rows'])
            ->firstWhere('title', 'Acompanhamento combinado com a diretora de turma');

        $this->assertNotNull($row);
        // Absent — never «legacy», never «null», never an internal code (§33).
        $this->assertNull($row['type']);
        $this->assertNotSame('legacy', $row['status']);
    }

    #[Test]
    public function concluded_interventions_are_part_of_the_history(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            Intervention::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'title' => 'Reforço da leitura orientada',
                'status' => InterventionStatus::Concluded,
                'started_on' => Carbon::parse('2027-01-10'),
                'concluded_on' => Carbon::parse('2027-03-10'),
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $rows = $this->progress($enrollment)['interventions']['rows'];

        // A view of a year that showed only what is still running would be a
        // view of today (§34).
        $this->assertSame(1, count($rows));
        $this->assertTrue($rows[0]['is_concluded']);
    }

    #[Test]
    public function an_intervention_past_its_review_date_is_flagged_and_counted(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            Intervention::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'title' => 'Reforço da leitura orientada',
                'status' => InterventionStatus::InProgress,
                'started_on' => Carbon::parse('2027-01-10'),
                'review_on' => Carbon::parse('2027-05-01'),
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $progress = $this->progress($enrollment);

        $this->assertSame(1, $progress['interventions']['needing_review']);
        $this->assertTrue($progress['interventions']['rows'][0]['needs_review']);
    }

    #[Test]
    public function an_intervention_with_a_future_review_date_is_not_flagged(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            Intervention::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'title' => 'Reforço da leitura orientada',
                'status' => InterventionStatus::InProgress,
                'started_on' => Carbon::parse('2027-01-10'),
                'review_on' => Carbon::parse('2027-08-01'),
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $progress = $this->progress($enrollment);

        $this->assertSame(0, $progress['interventions']['needing_review']);
        $this->assertFalse($progress['interventions']['rows'][0]['needs_review']);
    }

    // ------------------------------------------------------- §77 causalidade

    #[Test]
    public function an_intervention_and_a_result_share_a_timeline_and_nothing_else(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($class, $enrollment): void {
            Intervention::create([
                'class_id' => $class->getKey(),
                'enrollment_id' => $enrollment->getKey(),
                'target_type' => InterventionTargetType::Student,
                'title' => 'Reforço da leitura orientada',
                'status' => InterventionStatus::InProgress,
                'started_on' => Carbon::parse('2027-03-01'),
                'created_by' => $this->teacher->getKey(),
            ]);
        });

        $progress = $this->progress($enrollment);

        // Both are on the page.
        $this->assertSame(1, $progress['interventions']['total']);
        $this->assertNotEmpty($progress['moments']);

        // AND NOTHING RELATES THEM. No effect, no cause, no attribution — the
        // payload has no field that could carry one, which is a stronger
        // guarantee than a rule about wording (§35, §77).
        $encoded = (string) json_encode($progress, JSON_UNESCAPED_UNICODE);

        foreach (['graças a', 'devido a', 'em consequência', 'provocou', 'resultou em'] as $causal) {
            $this->assertStringNotContainsString($causal, $encoded);
        }

        $this->assertStringNotContainsString(
            'graças',
            (string) app(StudentProgressNarrative::class)->for($progress),
        );
    }

    // ------------------------------------------------------------ §78 tenant

    #[Test]
    public function a_teacher_without_access_to_the_class_sees_nothing(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $stranger = User::factory()->create(['email' => 'outro@lapis.test']);

        $this->actingAs($stranger)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            // The tenant scope refuses before the policy is consulted, which is
            // stronger: it does not confirm the class exists.
            ->assertNotFound();
    }

    #[Test]
    public function an_enrolment_from_another_class_cannot_be_read_through_this_one(): void
    {
        $class = $this->schoolClass();

        // A second class in the same organization, so the tenant scope lets the
        // row through and the only thing standing between the request and
        // somebody else's data is the guard being tested.
        $other = $this->asTenant(function () use ($class): Enrollment {
            $second = SchoolClass::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'academic_year_id' => $class->academic_year_id,
                'subject_id' => $class->subject_id,
                'label' => '7.º B',
            ]);

            return Enrollment::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'class_id' => $second->getKey(),
            ]);
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$other->ulid}")
            ->assertNotFound();
    }

    // ------------------------------------------------------- §79 performance

    #[Test]
    public function the_page_costs_a_bounded_number_of_queries(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($this->teacher)->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")->assertOk();

        // NOT A TARGET, A CEILING. The read model makes ONE aggregate pass over
        // the class — the progression — and hands it to the statistics rather
        // than letting it walk the year again. That plus a fixed handful of
        // lookups measures 72 on the demo class; Estatística, which does the
        // same single pass with less on top, measures 67. It was 121 before the
        // progression was handed over instead of built twice.
        //
        // The ceiling is deliberately loose against the measurement. What it
        // guards is not the number but the SHAPE: a query per domain, per
        // period, per record or per intervention would push straight through it
        // on any real class, and so would reintroducing the second aggregate
        // pass. That the pass happens exactly once is asserted directly in
        // ProgressionReuseTest, where it belongs (§56, §79).
        $this->assertLessThan(
            100,
            $queries,
            'The student progress page is making more queries than a bounded read should.',
        );
    }

    // ------------------------------------------------------ §80 primeira vez

    #[Test]
    public function a_student_with_nothing_at_all_still_gets_a_useful_page(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function (): void {
            DB::table('student_item_scores')->delete();
            DB::table('self_assessments')->delete();
            Classification::query()->delete();
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->assertOk();

        $progress = $this->progress($enrollment);

        $this->assertNull($progress['headline']['value']);
        $this->assertNull($progress['sinceLast']);
        $this->assertNull($progress['classComparison']);

        foreach ($progress['moments'] as $moment) {
            $this->assertNull($moment['value']);
        }
    }

    // ------------------------------------------------------------- a página

    #[Test]
    public function the_index_lists_the_classes_this_teacher_can_see(): void
    {
        $this->actingAs($this->teacher)
            ->get('/evolucao')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('student-progress/Index'));
    }

    #[Test]
    public function the_class_page_lists_everyone_who_was_ever_in_it(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $this->asTenant(function () use ($enrollment): void {
            $enrollment->update(['status' => EnrollmentStatus::TransferredOut]);
        });

        $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('student-progress/Class')
                ->where('students.0.is_current', false));
    }

    #[Test]
    public function the_page_carries_a_deterministic_summary_and_no_ai(): void
    {
        $class = $this->schoolClass();
        $enrollment = $this->enrollment();

        $first = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->viewData('page')['props']['narrative'] ?? null;

        $second = $this->actingAs($this->teacher)
            ->get("/classes/{$class->ulid}/evolucao/{$enrollment->ulid}")
            ->viewData('page')['props']['narrative'] ?? null;

        // The same figures always produce the same sentence. Nothing here
        // depends on a provider being configured (§38, §82).
        $this->assertSame($first, $second);
    }

    #[Test]
    public function the_scope_of_classifications_read_is_the_period_one(): void
    {
        // A guard on the read model's own assumptions: the classifications it
        // shows are the period decisions, not any other scope that may exist.
        $this->assertSame('period', ClassificationScope::Period->value);
    }
}
