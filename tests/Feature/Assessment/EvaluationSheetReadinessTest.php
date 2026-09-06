<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AcademicYear;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\EvaluationSheetExport;
use App\Models\InstrumentType;
use App\Models\ResultState;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentStatus;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\EvaluationSheetReadiness;
use App\Services\Assessment\InstrumentBuilder;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\RecordScores;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Services\StudentEnrollmentService;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Preparar fecho» (Fatia 1): a readiness layer OVER the Pauta de Avaliação.
 *
 * The rules under test are deliberately the product's OWN, never invented
 * here: decided means the teacher wrote a final value or level (§3.3), the
 * closing/interim distinction is InovarLevelOption's approved reading of the
 * period's dates and status (§6), coverage comes from the engine's flags
 * (§13.4), self-assessment expectation is usage, and INOVAR is information —
 * nothing in this checklist blocks anything.
 */
class EvaluationSheetReadinessTest extends TestCase
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

    /** @return array{SchoolClass, AcademicPeriod} */
    private function context(): array
    {
        return $this->asTenant(function (): array {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$schoolClass, $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function readiness(SchoolClass $schoolClass, AcademicPeriod $academicPeriod, bool $canExportToInovar = true): array
    {
        return $this->asTenant(function () use ($schoolClass, $academicPeriod, $canExportToInovar): array {
            $sheet = app(BuildEvaluationSheet::class)->for($schoolClass, $academicPeriod);

            return app(EvaluationSheetReadiness::class)->for($schoolClass, $academicPeriod, $sheet, $canExportToInovar);
        });
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return array<string, mixed>
     */
    private function item(array $readiness, string $key): array
    {
        $item = collect($readiness['items'])->firstWhere('key', $key);
        $this->assertNotNull($item, "Item «{$key}» ausente da checklist.");

        return $item;
    }

    /** Looked up the way every screen does — by the display name on the identity. */
    private function enrollmentOf(SchoolClass $schoolClass, string $name): Enrollment
    {
        $enrollment = $schoolClass->enrollments()->with('student.identity')->get()
            ->first(fn ($candidate): bool => optional($candidate->student->identity)->display_name === $name);

        $this->assertNotNull($enrollment, "Sem matrícula para «{$name}».");

        return $enrollment;
    }

    /**
     * @param  array<string, mixed>  $readiness
     * @return list<string>
     */
    private function pendingLabelsFor(array $readiness, string $name): array
    {
        $row = collect($readiness['students'])->firstWhere('name', $name);

        return $row === null ? [] : array_column($row['pending'], 'label');
    }

    #[Test]
    public function the_pauta_route_ships_the_readiness_reading_beside_the_sheet(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass] = $this->context();

        $response = $this->actingAs($this->teacher)->get("/classes/{$schoolClass->ulid}/pauta-avaliacao");
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');
        $readiness = $page['props']['readiness'];

        $this->assertNotNull($readiness);
        $this->assertSame('Semestre', $readiness['moment']['kind_label']);
        $this->assertSame(6, $readiness['summary']['students_total']);
        $this->assertNotEmpty($readiness['items']);
    }

    #[Test]
    public function the_moment_speaks_the_periods_own_terminology_and_knows_it_is_still_running(): void
    {
        // Mid-period: the 1.º Semestre of the demo year runs to 2027-01-29.
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $this->assertSame('1.º Semestre', $readiness['moment']['period_label']);
        $this->assertSame('Semestre', $readiness['moment']['kind_label']);
        $this->assertFalse($readiness['moment']['is_closing']);
    }

    #[Test]
    public function while_the_period_runs_missing_decisions_are_neutral_never_attention(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        // Nobody decided anything yet — and nothing says they had to, because
        // the semester still runs: listed, but never flagged.
        $decisions = $this->item($readiness, 'decisions');
        $this->assertSame(EvaluationSheetReadiness::STATE_NEUTRAL, $decisions['state']);
        $this->assertSame('0 de 6 níveis atribuídos', $decisions['label']);
    }

    #[Test]
    public function once_the_period_has_ended_the_same_shortfall_becomes_attention(): void
    {
        // The day after ends_on (2027-01-29): the moment now closes the period
        // — read from the period's own dates, never from its name (§6).
        $this->travelTo('2027-01-30');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $this->assertTrue($readiness['moment']['is_closing']);
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $this->item($readiness, 'decisions')['state']);
    }

    #[Test]
    public function a_closed_period_is_a_closing_moment_whatever_the_calendar_says(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $this->asTenant(fn () => $academicPeriod->update(['status' => 'closed']));

        $readiness = $this->readiness($schoolClass, $academicPeriod->refresh());

        $this->assertTrue($readiness['moment']['is_closing']);
    }

    #[Test]
    public function decided_proposed_and_missing_classifications_are_counted_apart(): void
    {
        $this->travelTo('2027-01-30');

        [$schoolClass, $academicPeriod] = $this->context();

        $this->asTenant(function () use ($schoolClass, $academicPeriod): void {
            // Proposals for the four students with results; Diogo (absent from
            // everything) and Filipe (enrolled after the test) get none.
            app(ProposeClassifications::class)->forPeriod($schoolClass, $academicPeriod);

            $carolina = Classification::query()
                ->where('academic_period_id', $academicPeriod->id)
                ->get()
                ->first(fn (Classification $classification): bool => $classification->enrollment->student->identity->display_name === 'Carolina Nunes');
            $levelFour = $schoolClass->profileVersion->scale->levels()->where('code', '4')->firstOrFail();
            app(ConfirmClassification::class)->confirm($carolina, $this->teacher, $levelFour->id);
        });

        $readiness = $this->readiness($schoolClass, $academicPeriod);
        $decisions = $this->item($readiness, 'decisions');

        $this->assertSame('1 de 6 níveis atribuídos', $decisions['label']);
        $this->assertSame('3 propostas por decidir · 2 sem registo', $decisions['detail']);
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $decisions['state']);

        // The teacher who decided has no decision line; the one with only a
        // proposal is told the proposal awaits THEIR decision (§3.3).
        $this->assertNotContains('Proposta do Lapispro ainda não decidida', $this->pendingLabelsFor($readiness, 'Carolina Nunes'));
        $this->assertContains('Proposta do Lapispro ainda não decidida', $this->pendingLabelsFor($readiness, 'Ana Marques'));
        $this->assertContains('Nível ainda não atribuído', $this->pendingLabelsFor($readiness, 'Diogo Ferreira'));
    }

    #[Test]
    public function on_an_interval_scale_the_checklist_says_classificação_and_never_nível(): void
    {
        // The word for the decision belongs to the SCALE, not to this screen
        // (DecisionScale, shared with Resultados and Classificações): bands are
        // a «nível», an interval is a «classificação». A checklist that said
        // «nível» to a school grading 0–20 would be naming something that does
        // not exist there.
        $this->travelTo('2027-01-30');

        [$schoolClass, $academicPeriod] = $this->asTenant(function (): array {
            $year = AcademicYear::query()->firstOrFail();
            $subject = Subject::query()->firstOrFail();

            $profile = app(ProfileBuilder::class)->create(
                [
                    'academic_year_id' => $year->id,
                    'subject_id' => $subject->id,
                    'name' => 'Perfil de intervalo — 0 a 20',
                    'description' => 'Escala numérica, sem níveis.',
                ],
                Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 0 a 20')->firstOrFail()->id,
                [['name' => 'Compreensão', 'weight' => 100]],
                ['7.º'],
            );
            $version = app(ActivateProfileVersion::class)->activate($profile->draftVersion(), $this->teacher);

            $class = SchoolClass::create([
                'academic_year_id' => $year->id,
                'subject_id' => $subject->id,
                'label' => '7.º Z',
                'grade_level' => '7.º',
                'status' => 'active',
                'assessment_profile_version_id' => $version->id,
            ]);
            app(StudentEnrollmentService::class)->enrollNew($class, [
                'name' => 'Rita Bastos',
                'class_number' => 1,
                'enrolled_on' => '2026-09-14',
            ]);

            return [$class, $year->periods()->where('sequence', 1)->firstOrFail()];
        });

        $readiness = $this->readiness($schoolClass, $academicPeriod);
        $decisions = $this->item($readiness, 'decisions');

        $this->assertSame('0 de 1 classificações decididas', $decisions['label']);
        $this->assertContains(
            'Classificação ainda não decidida',
            $this->pendingLabelsFor($readiness, 'Rita Bastos'),
        );
    }

    #[Test]
    public function coverage_gaps_come_from_the_engines_flags_never_recomputed_here(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        // Oralidade and Educação Literária have no elements for ANYONE — the
        // class's gap, said once, never repeated under all six names.
        $domains = $this->item($readiness, 'domains');
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $domains['state']);
        $this->assertStringContainsString('Oralidade', $domains['label']);
        $this->assertStringContainsString('Educação Literária', $domains['label']);

        // Diogo was absent from everything; Filipe enrolled after the test —
        // both are «sem elementos», which is a lack of data, never a zero.
        $this->assertContains('Sem elementos avaliados neste momento', $this->pendingLabelsFor($readiness, 'Diogo Ferreira'));
        $this->assertContains('Sem elementos avaliados neste momento', $this->pendingLabelsFor($readiness, 'Filipe Andrade'));

        // Eva has Q3 still «por avaliar» — Gramática holds data for others, so
        // hers is a personal, named gap.
        $this->assertContains('Sem resultados no domínio Gramática', $this->pendingLabelsFor($readiness, 'Eva Salgado'));

        // Students fully marked carry the sheet's ⚠ only because of the
        // class-wide empty domains — that must NOT become a per-student line.
        $this->assertSame([], $this->pendingLabelsFor($readiness, 'Carolina Nunes'));

        $coverage = $this->item($readiness, 'coverage');
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $coverage['state']);
        $this->assertSame('3 alunos com lacunas de cobertura', $coverage['label']);
    }

    #[Test]
    public function a_domain_without_a_value_that_the_engine_does_not_flag_is_not_a_gap(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        // Oralidade has no elements at all in the demo. Give it ONE, and make it
        // a bonus item scored for everybody: bonus points add to the numerator
        // and never to the denominator (§4.2), so the domain ends with no value
        // — and the engine deliberately raises NO coverage warning, because
        // nothing is missing. The checklist must agree with the engine rather
        // than read «sem valor» as «sem resultados».
        $this->asTenant(function () use ($schoolClass, $academicPeriod): void {
            $oralidade = Domain::where('code', 'ORALIDADE')->firstOrFail();

            $instrument = app(InstrumentBuilder::class)->create($schoolClass, [
                'academic_period_id' => $academicPeriod->id,
                'instrument_type_id' => InstrumentType::where('code', 'TEST')->firstOrFail()->id,
                'title' => 'Participação oral (bónus)',
                'applied_on' => '2026-11-10',
                'status' => 'in_correction',
                'counts_toward_classification' => true,
                'purpose' => 'summative',
                // Zero, because the only item is a bonus one: the builder
                // checks the total against the NON-bonus points, and there are
                // none — which is the very shape this test needs.
                'total_points' => 0,
            ], [
                ['code' => 'B1', 'label' => 'Bónus de participação', 'points_possible' => 10, 'is_bonus' => true, 'domains' => [
                    ['domain_id' => $oralidade->id, 'allocation_percent' => 100],
                ]],
            ]);

            $item = $instrument->items()->firstOrFail();
            $cells = [];
            foreach ($schoolClass->enrollments()->get() as $enrollment) {
                $cells[] = [
                    'enrollment_id' => $enrollment->id,
                    'instrument_item_id' => $item->id,
                    'result_state' => ResultState::Assessed->value,
                    'points_earned' => 5,
                ];
            }

            app(RecordScores::class)->save($instrument, $cells, $this->teacher);
        });

        $readiness = $this->readiness($schoolClass, $academicPeriod);
        $domains = $this->item($readiness, 'domains');

        // Educação Literária really has nothing and stays reported; Oralidade
        // has no value for a reason the engine does not call a gap.
        $this->assertStringContainsString('Educação Literária', $domains['label']);
        $this->assertStringNotContainsString('Oralidade', $domains['label']);

        // And no student is told they are missing results in Oralidade.
        foreach ($readiness['students'] as $row) {
            foreach ($row['pending'] as $entry) {
                $this->assertStringNotContainsString('Oralidade', $entry['label']);
            }
        }
    }

    #[Test]
    public function an_element_under_review_is_flagged_because_it_holds_publication_back(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        $this->asTenant(function () use ($schoolClass): void {
            $bruno = $this->enrollmentOf($schoolClass, 'Bruno Teixeira');

            // NA FORMA QUE A APLICAÇÃO PRODUZ, e não numa que ela nunca produz.
            // `RecordScores` limpa o valor sempre que o estado deixa de o
            // carregar (`ResultState::carriesValue()`), e a base de dados diz o
            // mesmo com o CHECK `sis_empty_is_not_zero_check`: só um elemento
            // avaliado pode ter pontos. Trocar apenas o estado deixava para trás
            // um valor que aquele estado não pode ter — o SQLite aceitava em
            // silêncio, o MySQL recusa, e o registo que o teste montava nunca
            // existiria em produção.
            StudentItemScore::query()
                ->where('enrollment_id', $bruno->id)
                ->limit(1)
                ->update([
                    'result_state' => ResultState::UnderReview->value,
                    'points_earned' => null,
                    'scale_level_id' => null,
                ]);
        });

        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $underReview = $this->item($readiness, 'under-review');
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $underReview['state']);
        $this->assertSame('1 aluno com elemento em revisão', $underReview['label']);
        $this->assertContains(
            'Elemento em revisão — retém a publicação da classificação',
            $this->pendingLabelsFor($readiness, 'Bruno Teixeira'),
        );
    }

    #[Test]
    public function self_assessments_are_not_applicable_until_the_class_actually_uses_them(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        // Nothing was launched, so nothing is pending — «não aplicável», never
        // a problem to fix (the brief forbids inventing the obligation).
        $selfAssessments = $this->item($readiness, 'self-assessments');
        $this->assertSame(EvaluationSheetReadiness::STATE_NEUTRAL, $selfAssessments['state']);
        $this->assertSame('Autoavaliações não utilizadas neste momento', $selfAssessments['label']);
        $this->assertNotContains('Autoavaliação em falta', $this->pendingLabelsFor($readiness, 'Ana Marques'));
    }

    #[Test]
    public function once_one_self_assessment_exists_the_missing_ones_deserve_a_look(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        $this->asTenant(function () use ($schoolClass, $academicPeriod): void {
            $ana = $this->enrollmentOf($schoolClass, 'Ana Marques');
            $bruno = $this->enrollmentOf($schoolClass, 'Bruno Teixeira');
            $template = app(SelfAssessmentTemplateProvider::class)->forClass($schoolClass);

            SelfAssessment::create([
                'enrollment_id' => $ana->id,
                'academic_period_id' => $academicPeriod->id,
                'self_assessment_template_id' => $template->id,
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student,
                'submitted_at' => now(),
            ]);
            SelfAssessment::create([
                'enrollment_id' => $bruno->id,
                'academic_period_id' => $academicPeriod->id,
                'self_assessment_template_id' => $template->id,
                'status' => SelfAssessmentStatus::Draft,
                'filled_by' => SelfAssessmentFilledBy::Student,
            ]);
        });

        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $selfAssessments = $this->item($readiness, 'self-assessments');
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $selfAssessments['state']);
        $this->assertSame('1 de 6 autoavaliações submetidas', $selfAssessments['label']);

        $this->assertContains('Autoavaliação em rascunho, por submeter', $this->pendingLabelsFor($readiness, 'Bruno Teixeira'));
        $this->assertContains('Autoavaliação em falta', $this->pendingLabelsFor($readiness, 'Carolina Nunes'));
        $this->assertNotContains('Autoavaliação em falta', $this->pendingLabelsFor($readiness, 'Ana Marques'));
    }

    #[Test]
    public function inovar_is_information_never_an_obligation(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        // Never exported: a neutral line, not a pending item.
        $before = $this->readiness($schoolClass, $academicPeriod);
        $this->assertSame(EvaluationSheetReadiness::STATE_NEUTRAL, $this->item($before, 'inovar')['state']);
        $this->assertSame('Exportação Inovar ainda não realizada', $this->item($before, 'inovar')['label']);

        // Without the entitlement and without history there is no line at all —
        // hiding it is presentation; the export route has its own gate (§8.2).
        $ungated = $this->readiness($schoolClass, $academicPeriod, canExportToInovar: false);
        $this->assertNull(collect($ungated['items'])->firstWhere('key', 'inovar'));

        $this->asTenant(function () use ($schoolClass, $academicPeriod): void {
            $payload = ['version' => 1, 'students' => []];
            EvaluationSheetExport::create([
                'class_id' => $schoolClass->id,
                'academic_period_id' => $academicPeriod->id,
                'scope' => ClassificationScope::Period,
                'adapter' => 'inovar',
                'moment_label' => 'Semestre — 1.º Semestre',
                'effective_at' => '2026-11-15',
                'payload' => $payload,
                'payload_hash' => CanonicalPayload::hash($payload),
                'exported_with_warnings' => true,
                'warning_count' => 2,
                'file_disk' => 'local',
                'exported_by' => $this->teacher->id,
                'exported_at' => now()->subDay(),
            ]);
        });

        $after = $this->readiness($schoolClass, $academicPeriod, canExportToInovar: false);
        $inovar = $this->item($after, 'inovar');

        // A record already created stays the school's to see, plan or no plan.
        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $inovar['state']);
        $this->assertSame('Última exportação Inovar a 19/11/2026', $inovar['label']);
        $this->assertSame('Exportada com 2 avisos.', $inovar['detail']);
        $this->assertNull($inovar['action']);
    }

    #[Test]
    public function the_summary_counts_students_with_notes_and_attention_points(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $this->assertSame(6, $readiness['summary']['students_total']);
        // Diogo, Eva and Filipe carry coverage gaps; mid-period, the missing
        // decisions of the other three are neutral and keep them off the list.
        $this->assertSame(3, $readiness['summary']['students_with_notes']);
        $this->assertSame(3, $readiness['summary']['students_ready']);
        $this->assertSame(3, $readiness['summary']['attention_count']);
    }

    #[Test]
    public function nothing_in_the_checklist_is_an_error_or_a_block(): void
    {
        $this->travelTo('2027-01-30');

        [$schoolClass, $academicPeriod] = $this->context();
        $readiness = $this->readiness($schoolClass, $academicPeriod);

        $states = [EvaluationSheetReadiness::STATE_OK, EvaluationSheetReadiness::STATE_ATTENTION, EvaluationSheetReadiness::STATE_NEUTRAL];

        foreach ($readiness['items'] as $item) {
            $this->assertContains($item['state'], $states);
        }

        foreach ($readiness['students'] as $row) {
            foreach ($row['pending'] as $entry) {
                $this->assertContains($entry['state'], $states);
            }
        }
    }

    #[Test]
    public function another_organization_sees_none_of_this_organizations_readiness_inputs(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        // Data that exists in organization A: one self-assessment, one export.
        $this->asTenant(function () use ($schoolClass, $academicPeriod): void {
            $ana = $this->enrollmentOf($schoolClass, 'Ana Marques');
            SelfAssessment::create([
                'enrollment_id' => $ana->id,
                'academic_period_id' => $academicPeriod->id,
                'self_assessment_template_id' => app(SelfAssessmentTemplateProvider::class)->forClass($schoolClass)->id,
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::Student,
                'submitted_at' => now(),
            ]);

            $payload = ['version' => 1, 'students' => []];
            EvaluationSheetExport::create([
                'class_id' => $schoolClass->id,
                'academic_period_id' => $academicPeriod->id,
                'scope' => ClassificationScope::Period,
                'adapter' => 'inovar',
                'moment_label' => 'Semestre — 1.º Semestre',
                'effective_at' => '2026-11-15',
                'payload' => $payload,
                'payload_hash' => CanonicalPayload::hash($payload),
                'exported_with_warnings' => false,
                'warning_count' => 0,
                'file_disk' => 'local',
                'exported_by' => $this->teacher->id,
                'exported_at' => now(),
            ]);
        });

        // The same models read under organization B: every scoped query inside
        // the service must come back empty — the same discipline the
        // cross-tenant test applies to BuildEvaluationSheet itself.
        $stranger = User::factory()->create();
        $foreign = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            function () use ($schoolClass, $academicPeriod): array {
                $sheet = app(BuildEvaluationSheet::class)->for($schoolClass, $academicPeriod);

                return app(EvaluationSheetReadiness::class)->for($schoolClass, $academicPeriod, $sheet, true);
            },
        );

        $this->assertSame(EvaluationSheetReadiness::STATE_NEUTRAL, $this->item($foreign, 'self-assessments')['state']);
        $this->assertSame('Exportação Inovar ainda não realizada', $this->item($foreign, 'inovar')['label']);
    }

    #[Test]
    public function the_readiness_reading_costs_a_bounded_number_of_queries_never_one_per_student(): void
    {
        $this->travelTo('2026-11-20');

        [$schoolClass, $academicPeriod] = $this->context();

        $queries = $this->asTenant(function () use ($schoolClass, $academicPeriod): int {
            $sheet = app(BuildEvaluationSheet::class)->for($schoolClass, $academicPeriod);

            DB::flushQueryLog();
            DB::enableQueryLog();
            app(EvaluationSheetReadiness::class)->for($schoolClass, $academicPeriod, $sheet, true);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        });

        // Flat lookups only: enrollments, active enrollments, self-assessments,
        // instruments, under-review scores, the last export, the scale. A build
        // that grew a per-student query would blow well past this.
        $this->assertLessThanOrEqual(10, $queries);
    }
}
