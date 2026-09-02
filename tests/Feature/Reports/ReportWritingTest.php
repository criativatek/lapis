<?php

namespace Tests\Feature\Reports;

use App\Domain\Reporting\SectionKey;
use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Intervention;
use App\Models\InterventionDescriptionSource;
use App\Models\InterventionDomainRelation;
use App\Models\InterventionStatus;
use App\Models\InterventionTargetType;
use App\Models\InterventionType;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportSection;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentQuestionRole;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\CreateReport;
use App\Services\Reporting\Export\SectionTables;
use App\Services\Reporting\FinalizeReport;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Database\Seeders\EntitlementsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * How the report READS.
 *
 * Every other test file in this module asks whether the numbers are right and
 * whether the claims are honest. This one asks whether a teacher would sign it.
 *
 * The failures it guards against all produce documents that are factually
 * correct and unmistakably machine-made: «nenhum aluno mantiveram», a scale
 * column reading «Bom» where the teacher wrote 4, a class report whose opening
 * line says «reporta-se a Ano letivo até ao momento», an intervention category
 * called «Legado sem dominio», and the parenthetical dash that explains the
 * method in the middle of the sentence making the claim.
 */
class ReportWritingTest extends TestCase
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

    private function period(int $sequence = 1): AcademicPeriod
    {
        return $this->asTenant(fn (): AcademicPeriod => $this->schoolClass()
            ->academicYear->periods()->where('sequence', $sequence)->firstOrFail());
    }

    private function report(?int $sequence = 1): Report
    {
        return $this->asTenant(fn (): Report => app(CreateReport::class)->forClass(
            class: $this->schoolClass(),
            author: $this->teacher,
            period: $sequence === null ? null : $this->period($sequence),
        ));
    }

    private function bodyOf(Report $report, SectionKey $key): string
    {
        return (string) $this->asTenant(
            fn (): ?string => $report->sections()->where('key', $key->value)->first()?->body,
        );
    }

    private function wholeText(Report $report): string
    {
        return $this->asTenant(fn (): string => $report->sections()
            ->get()
            ->map(fn (ReportSection $section) => (string) $section->body)
            ->implode("\n"));
    }

    /** Give the class real, varied classifications on its own 1–5 scale. */
    private function classify(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();
            $version = $class->profileVersion;
            $scale = $version->scale;
            $period = $this->period();

            $levels = $scale->levels()->orderBy('sequence')->get()->keyBy('code');

            // §23: 2, 4, 4, 4, 3, 4 → one 2, one 3, four 4.
            $codes = ['2', '4', '4', '4', '3', '4'];

            foreach ($class->enrollments()->orderBy('class_number')->get() as $index => $enrollment) {
                $level = $levels[$codes[$index] ?? '4'] ?? null;

                if ($level === null) {
                    continue;
                }

                Classification::create([
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'scope' => ClassificationScope::Period,
                    'assessment_profile_version_id' => $version->id,
                    'status' => ClassificationStatus::Confirmed,
                    'final_value' => (string) $level->code,
                    'final_scale_level_id' => $level->id,
                    'confirmed_by' => $this->teacher->id,
                    'confirmed_at' => now(),
                ]);
            }
        });
    }

    // ------------------------------------------------------- §22 forbidden

    #[Test]
    public function the_phrases_that_gave_this_away_as_generated_never_come_back(): void
    {
        $this->classify();

        $text = $this->wholeText($this->report());

        foreach ([
            'nenhum aluno mantiveram',
            'nenhum aluno mantiveram-se',
            'nenhum aluno progrediram',
            'nenhum aluno regrediram',
            'resultado que respondia',
            'reporta-se a Ano letivo',
            'no que respeita a Ano letivo',
            'Legado sem dominio',
            'Legado sem domínio',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "«{$forbidden}» voltou ao relatório.");
        }
    }

    #[Test]
    public function no_sentence_explains_itself_with_a_parenthetical_dash(): void
    {
        $this->classify();

        foreach ($this->asTenant(fn () => $this->report()->sections()->get()) as $section) {
            foreach (explode("\n", (string) $section->body) as $line) {
                // A line that OPENS with a dash is a list item, which is
                // editorial and fine. A dash inside a sentence is the habit
                // §1 and §20 are about.
                if (str_starts_with(trim($line), '—')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    ' — ',
                    $line,
                    "A secção «{$section->heading}» usa um travessão explicativo: {$line}",
                );
            }
        }
    }

    // ------------------------------------------------------------- §26 time

    #[Test]
    public function an_open_ended_year_is_stated_as_an_interval_with_a_real_date(): void
    {
        // No period: «ano letivo até ao momento».
        $report = $this->report(null);

        $body = $this->bodyOf($report, SectionKey::ClassIdentification);

        $this->assertSame(ReportScopeKind::Year, $report->scope_kind);
        $this->assertStringContainsString(
            'reporta-se ao período compreendido entre o início do ano letivo e 30 de junho de 2027',
            $body,
        );
        $this->assertStringNotContainsString('a Ano letivo', $body);
    }

    #[Test]
    public function a_period_scope_carries_its_article(): void
    {
        $body = $this->bodyOf($this->report(), SectionKey::ClassIdentification);

        $this->assertStringContainsString('reporta-se ao 1.º Semestre', $body);
    }

    #[Test]
    public function the_text_keeps_the_day_it_was_written_and_the_document_records_the_day_it_was_signed(): void
    {
        $report = $this->report(null);

        $this->travelTo(Carbon::parse('2027-07-15 09:00:00'));

        $this->asTenant(fn () => app(FinalizeReport::class)->finalize($report, $this->teacher));

        $document = $report->fresh()->document;

        $body = (string) collect($document['sections'])
            ->firstWhere('key', SectionKey::ClassIdentification->value)['body'];

        // The sentence describes what the FIGURES cover, and they were read on
        // the day the text was generated. Finalizing freezes; it does not
        // rewrite (§37) — so the body keeps that date rather than acquiring a
        // new one that no number in it corresponds to.
        $this->assertStringContainsString('30 de junho de 2027', $body);

        // When it was signed is recorded on the document itself, and is what
        // the signature block of the PDF and the Word file print.
        $this->assertStringStartsWith('2027-07-15', (string) $document['finalized_at']);
    }

    #[Test]
    public function regenerating_moves_the_open_end_forward(): void
    {
        $report = $this->report(null);

        $this->assertStringContainsString(
            '30 de junho de 2027',
            $this->bodyOf($report, SectionKey::ClassIdentification),
        );

        $this->travelTo(Carbon::parse('2027-07-15 09:00:00'));

        $this->asTenant(fn () => app(ComposeReport::class)->generate($report));

        // «até ao momento» means until this moment, so asking again legitimately
        // moves the end (§5).
        $this->assertStringContainsString(
            '15 de julho de 2027',
            $this->bodyOf($report, SectionKey::ClassIdentification),
        );
    }

    // -------------------------------------------------------- §23 grades

    #[Test]
    public function a_numbered_scale_reports_the_number_the_teacher_wrote(): void
    {
        $this->classify();

        $report = $this->report();
        $body = $this->bodyOf($report, SectionKey::ClassDistribution);

        // §23: one 2, one 3, four 4.
        $this->assertStringContainsString('um aluno obteve nível 2', mb_strtolower($body));
        $this->assertStringContainsString('nível 3', $body);
        $this->assertStringContainsString('quatro nível 4', mb_strtolower($body));

        // The mention is not what leads.
        $this->assertStringNotContainsString('obteve Insuficiente', $body);
        $this->assertStringNotContainsString('obteve Bom', $body);
        // And levels nobody was given stay out of the prose (§8).
        $this->assertStringNotContainsString('nível 1', $body);
        $this->assertStringNotContainsString('nível 5', $body);
    }

    #[Test]
    public function the_distribution_table_leads_with_the_classification_and_keeps_the_mention_beside_it(): void
    {
        $this->classify();

        $data = $this->asTenant(fn () => $this->report()->sections()
            ->where('key', SectionKey::ClassDistribution->value)->firstOrFail()->data);

        $tables = SectionTables::for(
            SectionKey::ClassDistribution->value,
            $data,
        );

        $this->assertNotEmpty($tables);

        $cells = array_map(fn (array $row) => $row[0], $tables[0]['rows']);

        // «4 · Bom», never «Bom».
        $this->assertContains('4 · Bom', $cells);
        $this->assertNotContains('Bom', $cells);
        // Zero rows survive in the TABLE, where a zero is informative.
        $this->assertContains('1 · Fraco', $cells);
    }

    #[Test]
    public function the_success_sentence_names_the_threshold_the_scale_states(): void
    {
        $this->classify();

        $body = $this->bodyOf($this->report(), SectionKey::OverallAssessment);

        // «iguais ou superiores a 3», derived from is_negative — the 3 appears
        // nowhere in the composer.
        $this->assertMatchesRegularExpression('/iguais? ou superiores? a 3/u', $body);
    }

    // ---------------------------------------------------- §24 legacy labels

    #[Test]
    public function an_untyped_intervention_never_lends_its_title_to_a_category(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();

            // The row shape that produced «Legado sem dominio»: a record from
            // before `intervention_type` existed, whose title is a technical
            // placeholder.
            Intervention::create([
                'class_id' => $class->id,
                'enrollment_id' => null,
                'academic_period_id' => $this->period()->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => null,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Legado sem dominio',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::InProgress,
                'started_on' => $this->period()->starts_on,
                'include_in_report' => false,
                'available_for_reports' => true,
                'created_by' => $this->teacher->id,
            ]);
        });

        $body = $this->bodyOf($this->report(), SectionKey::InterventionsSummary);

        $this->assertStringNotContainsString('Legado', $body);
        $this->assertStringNotContainsString('dominio', $body);
        // Accounted for honestly rather than renamed into a pedagogical action.
        $this->assertStringContainsString('não tem tipo registado', $body);
    }

    /**
     * §12: the count agrees with its verb in the singular too.
     *
     * «Foram registadas uma intervenção pedagógica» is the shape a plural verb
     * welded to a counted noun produces, and it is the same class of failure as
     * «nenhum aluno mantiveram» — visible in the first line of the section.
     */
    #[Test]
    public function a_single_intervention_takes_a_singular_verb(): void
    {
        $this->interventions(1);

        $body = $this->bodyOf($this->report(), SectionKey::InterventionsSummary);

        $this->assertStringContainsString('foi registada uma intervenção pedagógica', $body);
        $this->assertStringNotContainsString('foram registadas uma', $body);
        // And the follow-up sentence does not call one thing «todas».
        $this->assertStringNotContainsString('Todas se dirigiram', $body);
    }

    #[Test]
    public function several_interventions_keep_the_plural(): void
    {
        $this->interventions(3);

        $body = $this->bodyOf($this->report(), SectionKey::InterventionsSummary);

        $this->assertStringContainsString('foram registadas três intervenções pedagógicas', $body);
        $this->assertStringContainsString('Todas se dirigiram', $body);
    }

    /** Class-wide interventions the teacher asked to show. */
    private function interventions(int $count): void
    {
        $this->asTenant(function () use ($count): void {
            $class = $this->schoolClass();
            $period = $this->period();

            $titles = [
                'Apoio à planificação textual',
                'Reforço da leitura em voz alta',
                'Tutoria entre pares na gramática',
            ];

            for ($index = 0; $index < $count; $index++) {
                Intervention::create([
                    'class_id' => $class->id,
                    'enrollment_id' => null,
                    'academic_period_id' => $period->id,
                    'target_type' => InterventionTargetType::SchoolClass,
                    'intervention_type' => InterventionType::LearningReinforcement,
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => $titles[$index],
                    'description_source' => InterventionDescriptionSource::Manual,
                    'status' => InterventionStatus::InProgress,
                    'started_on' => $period->starts_on,
                    'include_in_report' => true,
                    'available_for_reports' => true,
                    'created_by' => $this->teacher->id,
                ]);
            }
        });
    }

    /**
     * THE DOOR THE TYPE FILTER LEFT OPEN.
     *
     * Excluding untyped rows kept the placeholder out of a document only for as
     * long as nobody typed one. Assigning a type to an imported intervention is
     * an ordinary thing to do in the UI, and the moment somebody does it the row
     * becomes quotable and prints the title an old process wrote to fill a NOT
     * NULL column. The filter was never the guard; reading the title through the
     * pedagogical accessor is.
     */
    #[Test]
    public function a_legacy_row_typed_afterwards_still_never_prints_its_placeholder(): void
    {
        $this->asTenant(function (): void {
            $class = $this->schoolClass();

            $legacy = Intervention::create([
                'class_id' => $class->id,
                'enrollment_id' => null,
                'academic_period_id' => $this->period()->id,
                'target_type' => InterventionTargetType::SchoolClass,
                'intervention_type' => null,
                'domain_relation' => InterventionDomainRelation::None,
                'title' => 'Legado sem dominio',
                'description_source' => InterventionDescriptionSource::Manual,
                'status' => InterventionStatus::InProgress,
                'started_on' => $this->period()->starts_on,
                'include_in_report' => false,
                'available_for_reports' => true,
                'created_by' => $this->teacher->id,
            ]);

            // What a teacher legitimately does next: classifies the imported row
            // and asks for it in the document.
            $legacy->forceFill([
                'intervention_type' => InterventionType::LearningReinforcement,
                'include_in_report' => true,
            ])->save();
        });

        $text = $this->wholeText($this->report());

        $this->assertStringNotContainsString('Legado sem dominio', $text);
        $this->assertStringNotContainsString('Legado sem domínio', $text);
        $this->assertStringNotContainsString('Legado', $text);
    }

    // ------------------------------------------------------ §12 interventions

    #[Test]
    public function the_intervention_paragraph_is_a_sentence_and_not_a_fragment(): void
    {
        $enrollments = $this->asTenant(fn () => $this->schoolClass()
            ->enrollments()->orderBy('class_number')->take(3)->get());

        $this->asTenant(function () use ($enrollments): void {
            $class = $this->schoolClass();

            foreach ($enrollments as $index => $enrollment) {
                Intervention::create([
                    'class_id' => $class->id,
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $this->period()->id,
                    'target_type' => InterventionTargetType::Student,
                    'intervention_type' => InterventionType::PedagogicalDifferentiation,
                    'domain_relation' => InterventionDomainRelation::None,
                    'title' => 'Diferenciação pedagógica',
                    'description_source' => InterventionDescriptionSource::Template,
                    'status' => InterventionStatus::InProgress,
                    'started_on' => $this->period()->starts_on->copy()->addDays($index),
                    'include_in_report' => false,
                    'available_for_reports' => true,
                    'created_by' => $this->teacher->id,
                ]);
            }
        });

        $body = $this->bodyOf($this->report(), SectionKey::InterventionsSummary);

        $this->assertStringContainsString('três intervenções pedagógicas', $body);
        $this->assertStringContainsString('três alunos', $body);
        // The fragment that used to appear when nothing was class-wide.
        $this->assertStringNotContainsString('as restantes', $body);
        // Purpose before paperwork (§13).
        $this->assertStringContainsString('diferenciação pedagógica', mb_strtolower($body));
        $this->assertStringNotContainsString('em curso', mb_strtolower($body));
    }

    // ----------------------------------------------------- §25 self-assessment

    /**
     * @param  list<string>  $said  What each student put themselves at, in
     *                              class-number order. The teacher assigned
     *                              2, 4, 4, 4, 3, 4 — see classify().
     */
    private function selfAssess(array $said): void
    {
        $this->asTenant(function () use ($said): void {
            $class = $this->schoolClass();
            $period = $this->period();
            $version = $class->profileVersion;
            $scale = $version->scale;

            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $version->id,
                'class_id' => $class->id,
                'name' => 'Autoavaliação do 1.º Semestre',
                'is_active' => true,
            ]);

            $question = SelfAssessmentQuestion::create([
                'self_assessment_template_id' => $template->id,
                'domain_id' => null,
                'role' => SelfAssessmentQuestionRole::Global,
                'prompt' => 'Como avalias o teu desempenho global?',
                'answer_kind' => 'scale',
                'scale_id' => $scale->id,
                'sequence' => 1,
            ]);

            $levels = $scale->levels()->orderBy('sequence')->get()->keyBy('code');

            foreach ($class->enrollments()->orderBy('class_number')->get() as $index => $enrollment) {
                $level = $levels[$said[$index] ?? '3'] ?? null;

                if ($level === null) {
                    continue;
                }

                $selfAssessment = SelfAssessment::create([
                    'enrollment_id' => $enrollment->id,
                    'academic_period_id' => $period->id,
                    'self_assessment_template_id' => $template->id,
                    'status' => SelfAssessmentStatus::Submitted,
                    'filled_by' => SelfAssessmentFilledBy::Student,
                    'submitted_at' => now(),
                ]);

                SelfAssessmentResponse::create([
                    'self_assessment_id' => $selfAssessment->id,
                    'self_assessment_question_id' => $question->id,
                    'scale_level_id' => $level->id,
                ]);
            }
        });
    }

    #[Test]
    public function everyone_having_self_assessed_is_said_as_a_person_would_say_it(): void
    {
        $this->classify();
        // §25 exactly: one above, three below, two coinciding.
        $this->selfAssess(['3', '4', '4', '3', '2', '3']);

        $body = $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment);

        $this->assertStringContainsString(
            'Os seis alunos que constituem a turma realizaram a sua autoavaliação',
            $body,
        );

        // THE REFERENT COMES FIRST (§11). «acima» and «abaixo» mean nothing
        // until the reader has been told what they are above and below, so the
        // coincidences open the sentence and name the comparison in full; the
        // deviations then point back at it.
        $this->assertStringContainsString(
            'Em dois casos, a autoavaliação coincidiu com a classificação atribuída; '
            .'um aluno autoavaliou-se acima dela e três abaixo',
            $body,
        );

        $this->assertStringNotContainsString('Registaram autoavaliação', $body);
        // §3: no methodological note in the body.
        $this->assertStringNotContainsString('não entra no cálculo', $body);
        // §7: the report says what was decided, not what the system calls it.
        $this->assertStringNotContainsString('decisão do professor', $body);
    }

    #[Test]
    public function a_comparison_with_no_coincidences_states_the_referent_in_full(): void
    {
        $this->classify();
        // Assigned 2,4,4,4,3,4 — every student off by one, nobody level.
        $this->selfAssess(['3', '5', '5', '5', '2', '5']);

        $body = $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment);

        // With nothing to point back at, the first group carries the whole
        // comparison rather than a pronoun. Asserted on the comparison's own
        // verb: the tendency sentence further down names the classification in
        // its own lead-in and may legitimately say «acima dela» after it.
        $this->assertStringContainsString(
            'autoavaliaram-se acima da classificação atribuída',
            $body,
        );
        $this->assertStringNotContainsString('autoavaliaram-se acima dela', $body);
        $this->assertStringNotContainsString('autoavaliou-se acima dela', $body);
    }

    #[Test]
    public function a_class_that_agrees_with_every_grade_says_only_that(): void
    {
        $this->classify();
        // Exactly the assigned codes: six coincidences, no deviations at all.
        $this->selfAssess(['2', '4', '4', '4', '3', '4']);

        $body = $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment);

        $this->assertStringContainsString(
            'Em seis casos, a autoavaliação coincidiu com a classificação atribuída',
            $body,
        );
        $this->assertStringNotContainsString('acima', $body);
        $this->assertStringNotContainsString('abaixo', $body);
    }

    #[Test]
    public function a_single_coincidence_is_said_in_the_singular(): void
    {
        $this->classify();
        // One level (the fifth student), the rest above.
        $this->selfAssess(['3', '5', '5', '5', '3', '5']);

        $body = $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment);

        $this->assertStringContainsString('Num caso, a autoavaliação coincidiu', $body);
        $this->assertStringNotContainsString('Em um caso', $body);
    }

    #[Test]
    public function a_lean_is_reported_as_a_tendency_and_a_spread_is_not(): void
    {
        $this->classify();
        // Half below and only one above: a real lean (§17).
        $this->selfAssess(['3', '4', '4', '3', '2', '3']);

        $this->assertStringContainsString(
            'tendência para uma autoavaliação mais baixa',
            $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment),
        );
    }

    #[Test]
    public function an_even_spread_is_never_called_a_tendency(): void
    {
        $this->classify();
        // Two above, two below, two coinciding: no lean at all.
        $this->selfAssess(['3', '4', '4', '3', '2', '5']);

        $body = $this->bodyOf($this->report(), SectionKey::ClassSelfAssessment);

        $this->assertStringNotContainsString('tendência', $body);

        // And nothing anywhere claims what a self-assessment says about the
        // student themselves (§78).
        foreach (['confiança', 'insegurança', 'autoestima', 'motivação'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, mb_strtolower($body));
        }
    }

    // ---------------------------------------------------------- §18 agreement

    #[Test]
    public function a_group_of_zero_students_takes_a_singular_verb(): void
    {
        // Second period: nobody has a result there in the demo class, so every
        // movement bucket is zero except «sem comparação».
        $body = $this->bodyOf($this->report(2), SectionKey::ClassEvolution);

        if ($body === '') {
            $this->markTestSkipped('Sem período anterior comparável.');
        }

        foreach (['mantiveram', 'progrediram', 'regrediram'] as $plural) {
            $this->assertStringNotContainsString('nenhum aluno '.$plural, $body);
        }
    }

    // -------------------------------------------------------------- §19 prose

    #[Test]
    public function small_numbers_are_written_out_in_prose_and_left_as_digits_in_figures(): void
    {
        $this->classify();

        $identification = $this->bodyOf($this->report(), SectionKey::ClassIdentification);

        $this->assertStringContainsString('seis alunos', $identification);
        $this->assertStringNotContainsString('6 alunos', $identification);

        // Percentages stay numeric wherever they appear.
        $overall = $this->bodyOf($this->report(), SectionKey::OverallAssessment);
        $this->assertMatchesRegularExpression('/\d+(,\d+)?%/u', $overall);
    }
}
