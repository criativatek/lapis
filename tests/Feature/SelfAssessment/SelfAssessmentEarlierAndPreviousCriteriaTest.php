<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\ClassProfileMigration;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportType;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentQuestion;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentTemplate;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\SelfAssessmentReading;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Services\Reporting\ReportCapabilities;
use App\Services\Reporting\ReportContext;
use App\Services\Reporting\Sections\ClassSelfAssessmentComposer;
use App\Services\Reporting\Sections\StudentSelfAssessmentComposer;
use App\Support\Assessment\SelfAssessmentRenewalException;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A self-assessment answered under criteria the class no longer uses, and a
 * new one that has no overall proposal yet.
 *
 * Two things must never happen in silence: a «70%» answered on a percentage
 * scale being read as a 70 on the class's current one, and the overall
 * judgement a student already gave disappearing because a newer, still empty
 * self-assessment took its place. Both are said out loud in the reading, and
 * every screen, report and export that reads it says them too.
 *
 * Setup as in SelfAssessmentInheritedCriteriaTest: the class starts on 1–5,
 * moves to another version, and the teacher starts a new self-assessment.
 */
class SelfAssessmentEarlierAndPreviousCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private const OLD_CRITERIA = 'Avaliação final · versão 1 · Escala 1 a 5';

    private User $teacher;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private string $enrollmentUlid;

    private AssessmentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $this->inTenant(function (): void {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $this->enrollmentUlid = (string) $this->class->enrollments()->orderBy('class_number')->firstOrFail()->ulid;

            $this->profile = app(ProfileBuilder::class)->create(
                ['academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id, 'name' => 'Avaliação final'],
                Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
                [['name' => 'Conhecimento', 'weight' => 60], ['name' => 'Comportamento', 'weight' => 40]],
            );
            $levels = app(ActivateProfileVersion::class)->activate($this->profile->draftVersion(), $this->teacher);

            $this->class->update(['assessment_profile_version_id' => $levels->id]);
        });
    }

    // ------------------------------------------------------------- helpers

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /**
     * @param  list<array{name: string, weight: int}>|null  $domains
     */
    private function activateVersion(string $scaleName, ?array $domains = null, int $rounding = 0): AssessmentProfileVersion
    {
        return $this->inTenant(function () use ($scaleName, $domains, $rounding): AssessmentProfileVersion {
            $profile = AssessmentProfile::whereKey($this->profile->id)->firstOrFail();

            app(ProfileBuilder::class)->update(
                $profile,
                ['name' => 'Avaliação final', 'academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id],
                Scale::where('name', $scaleName)->firstOrFail()->id,
                $domains ?? [['name' => 'Conhecimento', 'weight' => 60], ['name' => 'Comportamento', 'weight' => 40]],
            );

            $draft = AssessmentProfile::whereKey($this->profile->id)->firstOrFail()->draftVersion();
            $draft->forceFill(['rounding_scale' => $rounding])->save();

            return app(ActivateProfileVersion::class)->activate($draft, $this->teacher);
        });
    }

    private function migrate(AssessmentProfileVersion $to): ClassProfileMigration
    {
        return $this->inTenant(fn (): ClassProfileMigration => app(MigrateClassProfile::class)->migrate(
            SchoolClass::whereKey($this->class->id)->firstOrFail(),
            $to,
            'A turma passa a usar os novos critérios.',
            $this->teacher,
        ));
    }

    private function renew(): void
    {
        $this->inTenant(fn () => app(SelfAssessmentTemplateProvider::class)->renew(SchoolClass::whereKey($this->class->id)->firstOrFail(), $this->teacher));
    }

    private function formUrl(): string
    {
        return "/classes/{$this->class->ulid}/self-assessments/{$this->period->ulid}/{$this->enrollmentUlid}";
    }

    private function showUrl(): string
    {
        return "/classes/{$this->class->ulid}/self-assessments/{$this->period->ulid}";
    }

    /**
     * @return array<string, mixed>
     */
    private function propsOf(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private function formPage(): array
    {
        return $this->propsOf($this->actingAs($this->teacher)->get($this->formUrl()));
    }

    /**
     * @return array<string, mixed>
     */
    private function showPage(): array
    {
        return $this->propsOf($this->actingAs($this->teacher)->get($this->showUrl()));
    }

    /**
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function question(array $props, string $key, string $value): array
    {
        foreach ($props['questions'] as $question) {
            if (($question[$key] ?? null) === $value) {
                return $question;
            }
        }

        $this->fail("Não há pergunta com {$key} = {$value}.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function save(array $payload): TestResponse
    {
        return $this->actingAs($this->teacher)->from($this->formUrl())->post($this->formUrl(), $payload);
    }

    private function subscribeToPro(): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')
            ->where('organization_id', $this->teacher->personalOrganization()->getKey())
            ->delete();

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $this->teacher->personalOrganization()->getKey(),
            'plan_id' => Plan::where('key', 'pro')->firstOrFail()->getKey(),
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        app(Entitlements::class)->flush();
    }

    private function signedUrl(): string
    {
        return URL::temporarySignedRoute('self-assessments.public.edit', Carbon::now()->addDays(7), [
            'classUlid' => $this->class->ulid,
            'periodUlid' => $this->period->ulid,
            'enrollmentUlid' => $this->enrollmentUlid,
        ]);
    }

    /**
     * The student answers the overall question on 1–5 (a «4»).
     */
    private function answerOldGlobal(): void
    {
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');

        $this->save(['template' => $props['template'], 'answers' => [$global['id'] => $global['levels'][3]['id']]])->assertSessionHasNoErrors();
    }

    /**
     * The class has answered on 1–5 and moved to a percentage, WITHOUT a new
     * self-assessment being started.
     */
    private function movedToPercentageWithoutRenewing(): void
    {
        $this->answerOldGlobal();
        $this->migrate($this->activateVersion('Percentagem (0 a 100)'));
    }

    /**
     * …and the teacher has started the new one.
     */
    private function movedToPercentageAndRenewed(): void
    {
        $this->movedToPercentageWithoutRenewing();
        $this->renew();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentGlobal(): ?array
    {
        return $this->inTenant(function (): ?array {
            $reading = app(SelfAssessmentReading::class);

            return $reading->global($reading->forClassPeriod($this->class, $this->period)->first());
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentDomain(string $name): ?array
    {
        return $this->inTenant(function () use ($name): ?array {
            $reading = app(SelfAssessmentReading::class);
            $domainId = (int) DB::table('domains')->where('name', $name)->where('subject_id', $this->class->subject_id)->value('id');

            return $reading->forDomain($reading->forClassPeriod($this->class, $this->period)->first(), $domainId);
        });
    }

    /**
     * The Results payload of the first student for the period.
     *
     * @return array<string, mixed>|null
     */
    private function resultsRow(): ?array
    {
        $props = $this->propsOf($this->actingAs($this->teacher)->get("/classes/{$this->class->ulid}/results/{$this->period->ulid}"));

        foreach ($props['rows'] as $row) {
            if ($row['enrollment_ulid'] === $this->enrollmentUlid) {
                return $row['self_assessment'];
            }
        }

        $this->fail('O aluno não apareceu em Resultados.');
    }

    /**
     * The same student on the Pauta de Avaliação: the overall judgement, and
     * the answer to one domain.
     *
     * @return array{0: array<string, mixed>|null, 1: array<string, mixed>|null}
     */
    private function sheetRow(): array
    {
        $enrollmentId = (int) $this->inTenant(fn () => $this->class->enrollments()->where('ulid', $this->enrollmentUlid)->value('id'));
        $props = $this->propsOf($this->actingAs($this->teacher)->get("/classes/{$this->class->ulid}/pauta-avaliacao/{$this->period->ulid}"));

        foreach ($props['sheet']['students'] as $student) {
            if ((int) $student['enrollment_id'] === $enrollmentId) {
                $domain = collect($student['domains'])->firstWhere('name', 'Conhecimento');

                return [$student['self_assessment'], $domain['self_assessment'] ?? null];
            }
        }

        $this->fail('O aluno não apareceu na pauta.');
    }

    // ============================== 1–2. critérios anteriores

    #[Test]
    public function an_answer_given_under_other_criteria_is_marked_everywhere_it_is_read(): void
    {
        $this->movedToPercentageWithoutRenewing();

        $read = $this->currentGlobal();

        $this->assertNotNull($read);
        $this->assertSame('4', $read['code']);
        $this->assertSame('4', $read['display']);
        $this->assertTrue($read['previous_criteria']);
        $this->assertFalse($read['earlier']);
        $this->assertSame(self::OLD_CRITERIA, $read['criteria']);

        // Resultados…
        $results = $this->resultsRow();
        $this->assertTrue($results['previous_criteria']);
        $this->assertSame(self::OLD_CRITERIA, $results['criteria']);

        // …and the Pauta, where the class's own scale is now another one.
        [$global] = $this->sheetRow();
        $this->assertTrue($global['previous_criteria']);
        $this->assertSame(self::OLD_CRITERIA, $global['criteria']);
        $this->assertSame('4', $global['display']);
    }

    #[Test]
    public function only_the_weights_changing_is_enough_to_be_previous_criteria(): void
    {
        $this->answerOldGlobal();
        $this->assertFalse($this->currentGlobal()['previous_criteria'], 'enquanto a turma não muda, são os critérios atuais');

        // Same scale, same domains, different weights: a new version all the same.
        $this->migrate($this->activateVersion('Escala 1 a 5', [['name' => 'Conhecimento', 'weight' => 70], ['name' => 'Comportamento', 'weight' => 30]]));

        $read = $this->currentGlobal();

        $this->assertTrue($read['previous_criteria']);
        $this->assertSame(self::OLD_CRITERIA, $read['criteria']);
        $this->assertSame('4', $read['display']);
    }

    #[Test]
    public function a_percentage_is_displayed_with_its_sign_and_labelled_in_full(): void
    {
        $this->movedToPercentageAndRenewed();
        $global = $this->question($this->formPage(), 'role', 'global');

        $this->save(['numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        $read = $this->currentGlobal();

        $this->assertSame('70', $read['code']);
        $this->assertSame('70%', $read['display']);
        $this->assertSame('70%', $read['label']);
    }

    // ============================== 3–6. a nova autoavaliação

    #[Test]
    public function a_new_empty_self_assessment_does_not_make_the_earlier_overall_judgement_disappear(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();

        // Saved in blank: it exists, and it has no answers.
        $this->save(['template' => $props['template']])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->inTenant(fn () => SelfAssessment::count()));

        $read = $this->currentGlobal();

        $this->assertSame('4', $read['code']);
        $this->assertTrue($read['earlier']);
        $this->assertTrue($read['previous_criteria']);
        $this->assertSame(self::OLD_CRITERIA, $read['criteria']);

        // Nothing is borrowed per domain from the other self-assessment.
        $this->assertNull($this->currentDomain('Conhecimento'));
        $this->assertNull($this->currentDomain('Comportamento'));

        $this->assertTrue($this->resultsRow()['earlier']);
        $this->assertTrue($this->sheetRow()[0]['earlier']);
    }

    #[Test]
    public function a_new_self_assessment_with_only_a_reflection_keeps_the_earlier_one_as_reference(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $rationale = $this->question($props, 'role', 'rationale');

        $this->save(['template' => $props['template'], 'texts' => [$rationale['id'] => 'Estudei mais.']])->assertSessionHasNoErrors();

        $read = $this->currentGlobal();

        $this->assertSame('4', $read['code']);
        $this->assertTrue($read['earlier']);
        $this->assertNull($this->currentDomain('Conhecimento'));
    }

    #[Test]
    public function a_partial_new_self_assessment_reads_its_domains_and_the_earlier_overall_judgement(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $knowledge = $this->question($props, 'domain', 'Conhecimento');

        $this->save(['template' => $props['template'], 'numbers' => [$knowledge['id'] => '85']])->assertSessionHasNoErrors();

        $domain = $this->currentDomain('Conhecimento');
        $this->assertSame('85%', $domain['display']);
        $this->assertFalse($domain['previous_criteria']);
        $this->assertArrayNotHasKey('earlier', $domain);

        $global = $this->currentGlobal();
        $this->assertSame('4', $global['code']);
        $this->assertTrue($global['earlier']);

        [$sheetGlobal, $sheetDomain] = $this->sheetRow();
        $this->assertTrue($sheetGlobal['earlier']);
        $this->assertSame('85%', $sheetDomain['display']);
    }

    #[Test]
    public function a_new_overall_judgement_replaces_the_earlier_one_which_stays_with_the_teacher(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');

        $this->save(['template' => $props['template'], 'numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        $read = $this->currentGlobal();

        $this->assertSame('70%', $read['display']);
        $this->assertFalse($read['earlier']);
        $this->assertFalse($read['previous_criteria']);
        $this->assertSame('Avaliação final · versão 2 · Percentagem (0 a 100)', $read['criteria']);

        // The reading carries the earlier one with it, newest first…
        $this->inTenant(function (): void {
            $reading = app(SelfAssessmentReading::class);
            $current = $reading->forClassPeriod($this->class, $this->period)->first();

            $this->assertCount(1, $current->getRelation('earlierRounds'));
        });

        // …and the teacher's form still lists it, read-only.
        $earlier = $this->formPage()['earlierRounds'];
        $this->assertCount(1, $earlier);
        $this->assertSame(self::OLD_CRITERIA, $earlier[0]['criteria']);
    }

    #[Test]
    public function the_newest_template_is_chosen_whatever_the_order_the_rows_come_in(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $this->save(['template' => $props['template'], 'numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $reading = app(SelfAssessmentReading::class);
            $all = SelfAssessment::with(SelfAssessmentReading::relations())->get();

            foreach ([$all, $all->reverse()->values()] as $ordered) {
                $current = $reading->newestPerStudentPeriod($ordered);

                $this->assertCount(1, $current);
                $this->assertSame('70%', $reading->global($current->first())['display']);
            }
        });
    }

    #[Test]
    public function the_active_template_stays_current_when_a_retired_one_has_the_higher_id(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $this->save(['template' => $props['template'], 'numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        // What a restored or cloned backup can produce: the retired round
        // recreated AFTER the active one, with the higher id.
        $this->inTenant(function (): void {
            $retired = SelfAssessmentTemplate::where('class_id', $this->class->id)->where('is_active', false)->sole();
            $later = $retired->replicate(['ulid']);
            $later->save();

            SelfAssessment::where('self_assessment_template_id', $retired->id)->update(['self_assessment_template_id' => $later->id]);

            $active = SelfAssessmentTemplate::where('class_id', $this->class->id)->where('is_active', true)->sole();
            $this->assertGreaterThan($active->id, $later->id);
        });

        $current = $this->currentGlobal();
        $this->assertNotNull($current);
        $this->assertSame('70%', $current['display']);
        $this->assertFalse($current['earlier']);
        $this->assertSame('70%', $this->resultsRow()['display'] ?? null);
    }

    // ============================== 7. relatórios

    #[Test]
    public function the_student_report_says_which_criteria_an_answer_was_given_under(): void
    {
        $this->movedToPercentageWithoutRenewing();

        $composer = app(StudentSelfAssessmentComposer::class);

        $previous = $this->composeStudent($composer, $this->currentGlobal());
        $this->assertStringContainsString(
            'Na sua autoavaliação, respondida com critérios anteriores (Avaliação final · versão 1 · Escala 1 a 5), o aluno apreciou o desempenho global como',
            $previous,
        );
        $this->assertStringNotContainsString('autoavaliação anterior', $previous);
    }

    #[Test]
    public function the_student_report_names_an_earlier_proposal_as_such_and_never_compares_it(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $this->save(['template' => $props['template']])->assertSessionHasNoErrors();

        $composer = app(StudentSelfAssessmentComposer::class);

        $body = $this->composeStudent($composer, $this->currentGlobal(), ['sequence' => 3, 'code' => '3', 'scale_id' => 1]);

        $this->assertStringContainsString('A nova autoavaliação não inclui uma proposta global.', $body);
        $this->assertStringContainsString('Na autoavaliação anterior (critérios: Avaliação final · versão 1 · Escala 1 a 5), o aluno apreciou o desempenho global como', $body);
        $this->assertStringNotContainsString('classificação atribuída', $body);
    }

    #[Test]
    public function the_student_report_keeps_its_wording_for_an_answer_on_the_current_criteria(): void
    {
        $this->answerOldGlobal();

        $body = $this->composeStudent(app(StudentSelfAssessmentComposer::class), $this->currentGlobal());

        $this->assertStringContainsString('Na sua autoavaliação, o aluno apreciou o desempenho global como', $body);
        $this->assertStringNotContainsString('critérios anteriores', $body);
    }

    #[Test]
    public function the_class_report_counts_numeric_answers_and_states_the_criteria_facts(): void
    {
        $this->movedToPercentageAndRenewed();
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $this->save(['template' => $props['template'], 'numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        $composer = app(ClassSelfAssessmentComposer::class);
        $reading = $this->currentGlobal();

        // A numeric answer on the current criteria is an answer (it used to be
        // left out for having no band to compare).
        $body = $this->composeClass($composer, [$reading]);
        $this->assertStringContainsString('O único aluno da turma realizou a sua autoavaliação.', $body);
        $this->assertStringNotContainsString('critérios anteriores', $body);

        // Answers under earlier criteria, counted and said as a fact.
        $previous = [...$reading, 'previous_criteria' => true];
        $this->assertStringContainsString(
            'Dois alunos responderam com critérios anteriores aos atuais da turma.',
            $this->composeClass($composer, [$previous, $previous]),
        );
        $this->assertStringContainsString(
            'Um aluno respondeu com critérios anteriores aos atuais da turma.',
            $this->composeClass($composer, [$previous]),
        );

        // An earlier proposal is not an answer of the new round.
        $earlier = [...$reading, 'earlier' => true, 'previous_criteria' => true];
        $body = $this->composeClass($composer, [$earlier, $reading]);
        $this->assertStringContainsString('Um aluno ainda não tem proposta global na nova autoavaliação; tinha uma na anterior.', $body);
        $this->assertStringContainsString('Dos dois alunos da turma, um realizou a sua autoavaliação.', $body);

        $body = $this->composeClass($composer, [$earlier, $earlier]);
        $this->assertStringContainsString('Dois alunos ainda não têm proposta global na nova autoavaliação; tinham uma na anterior.', $body);
    }

    /**
     * @param  array<string, mixed>|null  $reading
     * @param  array<string, mixed>|null  $assigned
     */
    private function composeStudent(object $composer, ?array $reading, ?array $assigned = null): string
    {
        return (string) $composer->compose($this->context(['student' => ['self_assessment' => $reading, 'assigned' => $assigned]]))->body;
    }

    /**
     * @param  list<array<string, mixed>|null>  $readings
     */
    private function composeClass(object $composer, array $readings): string
    {
        $students = array_map(fn (?array $reading): array => ['self_assessment' => $reading, 'assigned' => null], $readings);

        return (string) $composer->compose($this->context(['students' => $students]))->body;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function context(array $facts): ReportContext
    {
        $report = new Report;
        $report->type = ReportType::SchoolClass;
        $report->scope_kind = ReportScopeKind::Period;
        $report->scope_label = '1.º Período';

        return new ReportContext(
            report: $report,
            facts: $facts,
            identity: [],
            capabilities: $this->createStub(ReportCapabilities::class),
            generatedOn: Carbon::parse('2026-12-20 12:00:00'),
        );
    }

    // ============================== 8. exportação

    #[Test]
    public function the_sheet_csv_says_where_a_self_assessment_cell_comes_from(): void
    {
        $this->movedToPercentageWithoutRenewing();
        $url = "/classes/{$this->class->ulid}/pauta-avaliacao/csv/{$this->period->ulid}";

        $previous = (string) $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('4 (critérios anteriores: '.self::OLD_CRITERIA.')', $previous);

        // A new, empty self-assessment: the earlier proposal stays as a reference.
        $this->renew();
        $props = $this->formPage();
        $this->save(['template' => $props['template']])->assertSessionHasNoErrors();

        $earlier = (string) $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('anterior: 4 (critérios anteriores: '.self::OLD_CRITERIA.')', $earlier);

        // And once the new overall judgement exists, the cell is just its value.
        $global = $this->question($props, 'role', 'global');
        $this->save(['template' => $props['template'], 'numbers' => [$global['id'] => '70']])->assertSessionHasNoErrors();

        $current = (string) $this->actingAs($this->teacher)->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('70%', $current);
        $this->assertStringNotContainsString('anterior: 4', $current);
    }

    #[Test]
    public function the_export_text_is_one_helper_for_every_case(): void
    {
        $this->assertSame('', SelfAssessmentReading::exportText(null));
        $this->assertSame('4', SelfAssessmentReading::exportText(['code' => '4', 'display' => '4', 'previous_criteria' => false, 'earlier' => false]));
        // A reading kept in a saved sheet from before `display` existed.
        $this->assertSame('4', SelfAssessmentReading::exportText(['code' => '4', 'label' => 'Bom']));
        $this->assertSame('70% (critérios anteriores: X)', SelfAssessmentReading::exportText(['code' => '70', 'display' => '70%', 'previous_criteria' => true, 'criteria' => 'X']));
        $this->assertSame('anterior: 70% (critérios anteriores: X)', SelfAssessmentReading::exportText(['code' => '70', 'display' => '70%', 'previous_criteria' => true, 'earlier' => true, 'criteria' => 'X']));
        $this->assertSame('anterior: 4 (critérios: X)', SelfAssessmentReading::exportText(['code' => '4', 'display' => '4', 'previous_criteria' => false, 'earlier' => true, 'criteria' => 'X']));
    }

    // ============================== 9. o aluno

    #[Test]
    public function the_student_is_told_whether_the_criteria_are_the_ones_the_self_assessment_began_with(): void
    {
        $this->subscribeToPro();
        $link = $this->signedUrl();

        /** @var array<string, mixed> $first */
        $first = $this->get($link)->assertOk()->viewData('page')['props'];
        $this->assertSame('current', $first['criteriaState']);
        $this->assertSame('current', $this->formPage()['criteriaState']);

        $global = $this->question($first, 'role', 'global');
        $this->post($link, ['template' => $first['template'], 'answers' => [$global['id'] => $global['levels'][3]['id']]])->assertSessionHasNoErrors();

        // The class moves on and nobody restarts: the form keeps the criteria it began with.
        $this->migrate($this->activateVersion('Percentagem (0 a 100)'));

        /** @var array<string, mixed> $second */
        $second = $this->get($link)->assertOk()->viewData('page')['props'];
        $this->assertSame('previous', $second['criteriaState']);
        $this->assertSame('Escala 1 a 5', $second['criteria']['scale']);
        $this->assertSame('previous', $this->formPage()['criteriaState']);

        // Started anew: the criteria are the class's again.
        $this->renew();
        /** @var array<string, mixed> $third */
        $third = $this->get($link)->assertOk()->viewData('page')['props'];
        $this->assertSame('current', $third['criteriaState']);
        $this->assertSame('Percentagem (0 a 100)', $third['criteria']['scale']);
    }

    // ============================== 10. modo só de leitura

    #[Test]
    public function in_read_only_mode_the_teacher_and_the_student_cannot_save_anything(): void
    {
        $this->subscribeToPro();
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $payload = ['template' => $props['template'], 'answers' => [$global['id'] => $global['levels'][3]['id']]];

        config(['lapis.self_assessment.read_only' => true]);
        $message = 'A autoavaliação está temporariamente só de leitura. As respostas guardadas continuam visíveis, mas por agora não é possível guardar alterações.';

        $this->save($payload)->assertSessionHasErrors(['answers' => $message]);
        $this->post($this->signedUrl(), $payload)->assertSessionHasErrors(['answers' => $message]);

        $this->assertSame(0, $this->inTenant(fn () => SelfAssessment::count()));
        $this->assertSame(0, $this->inTenant(fn () => SelfAssessmentResponse::count()));

        // Switched off, the same form is saved.
        config(['lapis.self_assessment.read_only' => false]);
        $this->save($payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $this->inTenant(fn () => SelfAssessment::count()));
    }

    #[Test]
    public function in_read_only_mode_nothing_is_started_and_nothing_is_reworded(): void
    {
        $this->movedToPercentageWithoutRenewing();
        $reflection = $this->showPage()['reflectionQuestions'][0];

        config(['lapis.self_assessment.read_only' => true]);
        $message = 'A autoavaliação está temporariamente só de leitura. As respostas guardadas continuam visíveis, mas por agora não é possível guardar alterações.';

        $configuration = $this->showPage()['configuration'];
        $this->assertTrue($configuration['read_only']);
        $this->assertFalse($configuration['can_renew']);
        $this->assertFalse($configuration['can_edit']);
        $this->assertSame('previous', $configuration['state']);

        $templates = $this->inTenant(fn () => SelfAssessmentTemplate::count());

        $this->actingAs($this->teacher)
            ->post("/classes/{$this->class->ulid}/self-assessments/renew")
            ->assertSessionHasErrors(['renew' => $message]);

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$reflection['id'] => 'Uma pergunta nova.']])
            ->assertSessionHasErrors(['prompts' => $message]);

        try {
            $this->renew();
            $this->fail('A renovação devia ter sido recusada.');
        } catch (SelfAssessmentRenewalException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }

        $this->assertSame($templates, $this->inTenant(fn () => SelfAssessmentTemplate::count()));
        $this->assertSame($reflection['prompt'], $this->inTenant(fn () => SelfAssessmentQuestion::whereKey($reflection['id'])->value('prompt')));
    }

    #[Test]
    public function opening_the_form_in_read_only_mode_derives_and_retires_nothing(): void
    {
        // No template at all: a form is rendered from one that is not saved.
        config(['lapis.self_assessment.read_only' => true]);

        $props = $this->formPage();

        $this->assertTrue($props['readOnly']);
        $this->assertSame([], $props['questions']);
        $this->assertSame(0, $this->inTenant(fn () => SelfAssessmentTemplate::count()));
        $this->assertSame(0, $this->inTenant(fn () => SelfAssessment::count()));

        $this->assertTrue($this->showPage()['configuration']['read_only']);
        $this->assertSame(0, $this->inTenant(fn () => SelfAssessmentTemplate::count()));
    }

    #[Test]
    public function a_template_nobody_answered_is_neither_followed_nor_retired_while_read_only_and_follows_afterwards(): void
    {
        $this->formPage();
        $this->assertSame(1, $this->inTenant(fn () => SelfAssessmentTemplate::count()));

        config(['lapis.self_assessment.read_only' => true]);

        // The class moves on: the migration goes through, the template does not follow it.
        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $this->assertSame(1, $this->inTenant(fn () => SelfAssessmentTemplate::count()));

        // …and a visit follows nothing either.
        $this->formPage();
        $this->assertSame(1, $this->inTenant(fn () => SelfAssessmentTemplate::count()));
        $this->assertSame(1, $this->inTenant(fn () => SelfAssessmentTemplate::where('is_active', true)->count()));

        // The mode is switched off: the next visit follows the class.
        config(['lapis.self_assessment.read_only' => false]);
        $props = $this->formPage();

        $this->assertSame(2, $this->inTenant(fn () => SelfAssessmentTemplate::count()));
        $this->assertSame('Escala 0 a 20', $props['criteria']['scale']);
        $this->assertSame('current', $props['criteriaState']);
    }

    // ============================== 11. persistência

    /**
     * @return iterable<string, array{string, int, list<array{string, string}>, string}>
     */
    public static function limits(): iterable
    {
        yield '0 to 20' => ['Escala 0 a 20', 0, [['0', '0.000'], ['20', '20.000']], '20'];
        yield 'percentage' => ['Percentagem (0 a 100)', 0, [['0', '0.000'], ['100', '100.000']], '100%'];
        yield 'own scale with a negative minimum' => ['Intervalo de −10 a 10', 2, [['-5.25', '-5.250'], ['-10', '-10.000'], ['10', '10.000']], '10'];
        yield 'own scale at the top of the column' => ['Intervalo de 0 a 999,999', 3, [['0', '0.000'], ['999.999', '999.999']], '999,999'];
    }

    /**
     * @param  list<array{string, string}>  $values
     */
    #[Test]
    #[DataProvider('limits')]
    public function limit_values_are_stored_and_read_back_exactly(string $scaleName, int $decimals, array $values, string $lastDisplay): void
    {
        $this->inTenant(function () use ($scaleName): void {
            if (Scale::where('name', $scaleName)->doesntExist()) {
                [$min, $max] = str_contains($scaleName, '999') ? ['0', '999.999'] : ['-10', '10'];
                Scale::create(['name' => $scaleName, 'kind' => 'custom', 'min_value' => $min, 'max_value' => $max]);
            }
        });

        $this->migrate($this->activateVersion($scaleName, null, $decimals));
        $global = $this->question($this->formPage(), 'role', 'global');

        foreach ($values as [$typed, $stored]) {
            $this->save(['numbers' => [$global['id'] => $typed]])->assertSessionHasNoErrors();

            $this->inTenant(function () use ($global, $stored): void {
                $response = SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole();

                // The column is DECIMAL(6,3): MySQL hands back the string
                // «-5.250», SQLite a number. The value is what is compared,
                // to the thousandth, and never through a float of its own.
                $raw = $response->getRawOriginal('numeric_value');
                $this->assertSame(0, bccomp((string) $raw, $stored, 3), "guardado {$raw}, esperado {$stored}");
                $this->assertSame($stored, number_format((float) $raw, 3, '.', ''));
                $this->assertNull($response->scale_level_id);
            });
        }

        // The last one is read back as written, with the comma.
        $this->assertSame($lastDisplay, $this->currentGlobal()['display']);
    }

    #[Test]
    public function a_value_one_step_past_a_limit_is_refused_and_nothing_is_stored(): void
    {
        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $global = $this->question($this->formPage(), 'role', 'global');

        foreach (['20.5', '21', '-0.5'] as $typed) {
            $this->save(['numbers' => [$global['id'] => $typed]])->assertSessionHasErrors("numbers.{$global['id']}");
        }

        $this->assertSame(0, $this->inTenant(fn () => SelfAssessmentResponse::count()));
    }
}
