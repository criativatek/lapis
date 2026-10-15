<?php

namespace Tests\Feature\SelfAssessment;

use App\Models\AcademicPeriod;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\ClassProfileMigration;
use App\Models\Domain;
use App\Models\Enrollment;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\SelfAssessment;
use App\Models\SelfAssessmentFilledBy;
use App\Models\SelfAssessmentResponse;
use App\Models\SelfAssessmentStatus;
use App\Models\SelfAssessmentTemplate;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Services\Assessment\MigrateClassProfile;
use App\Services\Assessment\ProfileBuilder;
use App\Services\Assessment\SelfAssessmentReading;
use App\Services\Assessment\SelfAssessmentTemplateProvider;
use App\Services\StudentEnrollmentService;
use App\Support\Assessment\SelfAssessmentRenewalException;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The self-assessment INHERITS the class's criteria (0.167.0): the domains, the
 * weights and the scale of the profile version the class is assessed by —
 * never the profile's newest version, never a configuration of its own.
 *
 * The teacher does not edit the criteria here; the reflection questions are the
 * only part of the form he words himself. When the class moves to another
 * version, a self-assessment nobody answered follows it at once; one that was
 * answered stays with the criteria it was filled in with, and a new one can be
 * started, side by side with the old, which is neither converted nor erased.
 */
class SelfAssessmentInheritedCriteriaTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private string $enrollmentUlid;

    private AssessmentProfile $profile;

    private AssessmentProfileVersion $levels;

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
            $this->levels = app(ActivateProfileVersion::class)->activate($this->profile->draftVersion(), $this->teacher);

            // Associated while the class has no decisions — the plain swap.
            $this->class->update(['assessment_profile_version_id' => $this->levels->id]);
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
     * A new ACTIVE version of the profile on the given scale and domains.
     *
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

            // The precision is a property of the version, and a version is frozen
            // once active: it is set on the draft.
            $draft = AssessmentProfile::whereKey($this->profile->id)->firstOrFail()->draftVersion();
            $draft->forceFill(['rounding_scale' => $rounding])->save();

            return app(ActivateProfileVersion::class)->activate($draft, $this->teacher);
        });
    }

    private function migrate(AssessmentProfileVersion $to, ?User $actor = null, ?User $onBehalfOf = null): ClassProfileMigration
    {
        return $this->inTenant(fn (): ClassProfileMigration => app(MigrateClassProfile::class)->migrate(
            SchoolClass::whereKey($this->class->id)->firstOrFail(),
            $to,
            'A turma passa a usar os novos critérios.',
            $actor ?? $this->teacher,
            $onBehalfOf,
        ));
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true, 'is_support_technician' => true])->save();

        return $admin;
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
    private function formPage(?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->teacher)->get($this->formUrl());
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
    private function showPage(): array
    {
        $response = $this->actingAs($this->teacher)->get($this->showUrl());
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        /** @var array<string, mixed> $props */
        $props = $page['props'];

        return $props;
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<array<string, mixed>>
     */
    private function scaleQuestions(array $props): array
    {
        /** @var list<array<string, mixed>> $questions */
        $questions = $props['questions'];

        return array_values(array_filter($questions, fn (array $question): bool => $question['answer_kind'] === 'scale'));
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
    private function save(array $payload, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->teacher)->from($this->formUrl())->post($this->formUrl(), $payload);
    }

    private function responseCount(): int
    {
        return $this->inTenant(fn (): int => SelfAssessmentResponse::count());
    }

    private function selfAssessmentCount(): int
    {
        return $this->inTenant(fn (): int => SelfAssessment::count());
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
     * The class has answered once on 1–5 and then moved to 0–20: the template
     * stays with the answers, and "a nova autoavaliação" is the way forward.
     *
     * @return array{AssessmentProfileVersion, int}
     */
    private function classMovedToZeroTwentyWithAnswers(): array
    {
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $domain = $this->question($props, 'domain', 'Conhecimento');
        $rationale = $this->question($props, 'role', 'rationale');

        $this->save([
            'template' => $props['template'],
            'answers' => [$global['id'] => $global['levels'][3]['id'], $domain['id'] => $domain['levels'][2]['id']],
            'texts' => [$rationale['id'] => 'Estudei mais.'],
        ])->assertSessionHasNoErrors();

        $zeroToTwenty = $this->activateVersion('Escala 0 a 20');
        $this->migrate($zeroToTwenty);

        return [$zeroToTwenty, (int) $this->inTenant(fn () => SelfAssessmentTemplate::where('class_id', $this->class->id)->where('is_active', true)->value('id'))];
    }

    // ============================== 1. escala de níveis: o professor

    #[Test]
    public function the_teacher_sees_the_criteria_the_questions_are_built_from_and_answers_by_level(): void
    {
        $props = $this->formPage();

        $configuration = $props['configuration'];
        $this->assertSame('Avaliação final', $configuration['profile']);
        $this->assertSame(1, $configuration['version']);
        $this->assertSame('Escala 1 a 5', $configuration['scale']);
        $this->assertSame([['name' => 'Conhecimento', 'weight' => '60'], ['name' => 'Comportamento', 'weight' => '40']], $configuration['domains']);
        $this->assertSame('current', $configuration['state']);
        $this->assertSame(['profile' => 'Avaliação final', 'version' => 1, 'scale' => 'Escala 1 a 5'], $configuration['class_criteria']);
        $this->assertTrue($configuration['can_edit']);
        $this->assertFalse($configuration['can_renew']);
        $this->assertSame("/classes/{$this->class->ulid}", $configuration['class_url']);
        $this->assertSame('levels', $configuration['answer']['mode']);

        // …and the criteria the student's form states are the template's own.
        $this->assertSame(['scale' => 'Escala 1 a 5', 'domains' => $configuration['domains']], $props['criteria']);
        $this->assertNotEmpty($props['template']);
        $this->assertSame([], $props['earlierRounds']);

        foreach ($this->scaleQuestions($props) as $question) {
            $this->assertSame('levels', $question['answer']['mode']);
            $this->assertSame('Escolhe um nível de 1 (Fraco) a 5 (Muito Bom).', $question['answer']['instruction']);
            $this->assertCount(5, $question['levels']);
            $this->assertSame($question['levels'], $question['answer']['levels']);
            $this->assertNull($question['answer_number']);
        }
    }

    #[Test]
    public function levels_are_stored_and_a_level_of_another_scale_refuses_the_whole_form(): void
    {
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $domain = $this->question($props, 'domain', 'Conhecimento');

        $foreign = $this->inTenant(fn (): int => Scale::create(['name' => 'Escala de outra turma', 'kind' => 'level', 'min_value' => '1', 'max_value' => '3'])
            ->levels()->create(['code' => 'X', 'label' => 'De outra escala', 'sequence' => 1])->id);

        // A valid answer next to an invalid one: neither is stored.
        $this->save(['answers' => [$domain['id'] => $domain['levels'][1]['id'], $global['id'] => $foreign]])
            ->assertSessionHasErrors("answers.{$global['id']}");

        $this->assertSame(0, $this->selfAssessmentCount());
        $this->assertSame(0, $this->responseCount());

        $this->save(['answers' => [$global['id'] => $global['levels'][3]['id'], $domain['id'] => $domain['levels'][1]['id']]])
            ->assertSessionHasNoErrors();

        $reloaded = array_column($this->scaleQuestions($this->formPage()), null, 'id');
        $this->assertSame($global['levels'][3]['id'], $reloaded[$global['id']]['answer_level_id']);
        $this->assertSame($domain['levels'][1]['id'], $reloaded[$domain['id']]['answer_level_id']);
        $this->assertNull($reloaded[$global['id']]['answer_number']);

        // A number typed on a question answered by level is refused too.
        $this->save(['numbers' => [$domain['id'] => '3']])->assertSessionHasErrors("numbers.{$domain['id']}");
    }

    // ============================== 2. 0 a 20

    #[Test]
    public function zero_to_twenty_is_answered_with_a_whole_number_and_nothing_invalid_is_stored(): void
    {
        $this->migrate($this->activateVersion('Escala 0 a 20'));

        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $domain = $this->question($props, 'domain', 'Conhecimento');

        foreach ($this->scaleQuestions($props) as $question) {
            $this->assertSame('number', $question['answer']['mode']);
            $this->assertSame([], $question['levels']);
            $this->assertSame('0', $question['answer']['min']);
            $this->assertSame('20', $question['answer']['max']);
            $this->assertSame('1', $question['answer']['step']);
            $this->assertSame(0, $question['answer']['decimals']);
            $this->assertNull($question['answer']['unit']);
            $this->assertSame('Escreve um valor de 0 a 20, sem casas decimais.', $question['answer']['instruction']);
        }

        foreach (['21', '-1', '15.5', 'abc'] as $invalid) {
            $this->save(['numbers' => [$domain['id'] => '10', $global['id'] => $invalid]])
                ->assertSessionHasErrors("numbers.{$global['id']}");
        }

        $this->assertSame(0, $this->selfAssessmentCount(), 'nada foi gravado, nem a autoavaliação');
        $this->assertSame(0, $this->responseCount());

        $this->save(['numbers' => [$global['id'] => '16', $domain['id'] => '0']])->assertSessionHasNoErrors();

        $this->inTenant(function () use ($global, $domain): void {
            $this->assertSame('16.000', SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->numeric_value);
            $this->assertNull(SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->scale_level_id);
            // Zero is a grade; it is not the same as an empty answer.
            $this->assertSame('0.000', SelfAssessmentResponse::where('self_assessment_question_id', $domain['id'])->sole()->numeric_value);
        });

        $reloaded = array_column($this->scaleQuestions($this->formPage()), null, 'id');
        $this->assertSame('16', $reloaded[$global['id']]['answer_number']);
        $this->assertSame('0', $reloaded[$domain['id']]['answer_number']);

        // A level on a question answered by number is refused.
        $this->save(['answers' => [$global['id'] => 1]])->assertSessionHasErrors("answers.{$global['id']}");

        // Empty is not zero: clearing the answer leaves it without a value.
        $this->save(['numbers' => [$global['id'] => '']])->assertSessionHasNoErrors();
        $this->inTenant(fn () => $this->assertNull(SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->numeric_value));
    }

    #[Test]
    public function the_precision_comes_from_the_version_and_a_comma_is_accepted(): void
    {
        $this->migrate($this->activateVersion('Escala 0 a 20', null, 1));

        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $this->assertSame(1, $global['answer']['decimals']);
        $this->assertSame('0.1', $global['answer']['step']);
        $this->assertSame('Escreve um valor de 0 a 20, com até 1 casa decimal.', $global['answer']['instruction']);

        $this->save(['numbers' => [$global['id'] => '15.55']])->assertSessionHasErrors("numbers.{$global['id']}");
        $this->assertSame(0, $this->responseCount());

        $this->save(['numbers' => [$global['id'] => '15,5']])->assertSessionHasNoErrors();

        $this->inTenant(fn () => $this->assertSame('15.500', SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->numeric_value));
        $this->assertSame('15.5', $this->question($this->formPage(), 'role', 'global')['answer_number']);
    }

    // ============================== 3. percentagem

    #[Test]
    public function a_percentage_is_answered_with_a_number_by_the_teacher_and_by_the_students_link(): void
    {
        $this->migrate($this->activateVersion('Percentagem (0 a 100)'));
        $this->subscribeToPro();

        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');

        $this->assertSame('number', $global['answer']['mode']);
        $this->assertSame('%', $global['answer']['unit']);
        $this->assertSame('100', $global['answer']['max']);
        $this->assertSame('Escreve uma percentagem de 0 a 100, sem casas decimais.', $global['answer']['instruction']);

        // The teacher…
        $this->save(['numbers' => [$global['id'] => '100.5']])->assertSessionHasErrors("numbers.{$global['id']}");
        $this->save(['numbers' => [$global['id'] => '80']])->assertSessionHasNoErrors();
        $this->inTenant(fn () => $this->assertSame('80.000', SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->numeric_value));

        // …and the student, through the signed link, on exactly the same terms.
        $link = $this->signedUrl();
        $this->post($link, ['numbers' => [$global['id'] => '100.5']])->assertSessionHasErrors("numbers.{$global['id']}");
        $this->post($link, ['numbers' => [$global['id'] => '95']])->assertSessionHasNoErrors();

        $this->inTenant(fn () => $this->assertSame('95.000', SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->numeric_value));

        /** @var array<string, mixed> $public */
        $public = $this->get($link)->assertOk()->viewData('page')['props'];
        $this->assertSame('95', $this->question($public, 'role', 'global')['answer_number']);
        $this->assertSame('number', $this->question($public, 'role', 'global')['answer']['mode']);
    }

    // ============================== 4. configuração inválida

    #[Test]
    public function a_scale_without_levels_or_limits_cannot_be_answered_and_says_why(): void
    {
        $empty = $this->inTenant(fn (): int => Scale::create(['name' => 'Níveis por definir', 'kind' => 'level', 'min_value' => '1', 'max_value' => '3'])->id);

        // The profile version points at a scale of levels that has none.
        DB::table('assessment_profile_versions')->where('id', $this->levels->id)->update(['scale_id' => $empty]);

        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');

        $this->assertSame('unavailable', $global['answer']['mode']);
        $this->assertSame('A escala «Níveis por definir» é de níveis, mas não tem nenhum nível definido.', $global['answer']['problem']);
        $this->assertSame('Esta pergunta não pode ser respondida por agora. Avisa o teu professor.', $global['answer']['instruction']);
        $this->assertSame($global['answer']['problem'], $props['configuration']['answer']['problem']);

        $this->save(['numbers' => [$global['id'] => '3']])->assertSessionHasErrors("numbers.{$global['id']}");
        $this->save(['answers' => [$global['id'] => 1]])->assertSessionHasErrors("answers.{$global['id']}");
        $this->assertSame(0, $this->selfAssessmentCount());

        // Written questions do not depend on the scale, and are saved normally.
        $rationale = $this->question($props, 'role', 'rationale');
        $this->save(['texts' => [$rationale['id'] => 'Escrevi na mesma.']])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->selfAssessmentCount());
    }

    // ============================== 5. a global é uma resposta

    #[Test]
    public function the_overall_judgement_is_the_students_answer_and_never_an_average(): void
    {
        $props = $this->formPage();
        $domain = $this->question($props, 'domain', 'Conhecimento');
        $other = $this->question($props, 'domain', 'Comportamento');

        $this->save(['answers' => [$domain['id'] => $domain['levels'][4]['id'], $other['id'] => $other['levels'][4]['id']]])->assertSessionHasNoErrors();

        $reading = app(SelfAssessmentReading::class);

        $this->inTenant(function () use ($reading, $domain): void {
            $selfAssessment = $reading->forClassPeriod($this->class, $this->period)->first();

            $this->assertNotNull($selfAssessment);
            $this->assertNull($reading->global($selfAssessment), 'quatro domínios respondidos não são um juízo global');
            $this->assertSame('5', $reading->forDomain($selfAssessment, (int) DB::table('self_assessment_questions')->where('id', $domain['id'])->value('domain_id'))['code']);
        });
    }

    #[Test]
    public function a_numeric_overall_judgement_is_read_with_its_scale_and_without_a_band(): void
    {
        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $global = $this->question($this->formPage(), 'role', 'global');

        $this->save(['numbers' => [$global['id'] => '16']])->assertSessionHasNoErrors();

        $this->inTenant(function (): void {
            $reading = app(SelfAssessmentReading::class);
            $read = $reading->global($reading->forClassPeriod($this->class, $this->period)->first());

            $this->assertSame('16', $read['code']);
            $this->assertSame('16 numa escala de 0 a 20', $read['label']);
            $this->assertNull($read['sequence']);
            $this->assertFalse($read['is_negative']);
            $this->assertSame('Escala 0 a 20', $read['scale']);
        });
    }

    // ============================== 6. a migração acompanha

    #[Test]
    public function migrating_a_class_whose_self_assessment_nobody_answered_derives_the_new_one_at_once(): void
    {
        $this->formPage();
        $zeroToTwenty = $this->activateVersion('Escala 0 a 20');

        $this->migrate($zeroToTwenty);

        // No visit in between: the migration did it.
        $this->inTenant(function () use ($zeroToTwenty): void {
            $templates = SelfAssessmentTemplate::where('class_id', $this->class->id)->orderBy('id')->get();

            $this->assertCount(2, $templates);
            $this->assertFalse($templates[0]->is_active);
            $this->assertTrue($templates[1]->is_active);
            $this->assertSame($zeroToTwenty->id, $templates[1]->assessment_profile_version_id);

            $event = AuditEvent::where('event', 'self_assessment.template_retired')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame($templates[0]->id, $event->subject_id);
            $this->assertArrayNotHasKey('acting_as_user_id', $event->properties);
        });
    }

    #[Test]
    public function in_technical_access_the_follow_up_names_the_administrator_on_behalf_of_the_teacher(): void
    {
        $this->formPage();
        $admin = $this->admin();

        $this->migrate($this->activateVersion('Escala 0 a 20'), $admin, $this->teacher);

        $this->inTenant(function () use ($admin): void {
            $event = AuditEvent::where('event', 'self_assessment.template_retired')->sole();

            $this->assertSame($admin->id, $event->causer_id);
            $this->assertSame($this->teacher->id, $event->properties['acting_as_user_id']);
            $this->assertTrue($event->properties['technical_access']);
        });
    }

    #[Test]
    public function a_class_that_never_opened_its_self_assessment_has_nothing_to_follow(): void
    {
        $this->migrate($this->activateVersion('Escala 0 a 20'));

        $this->inTenant(fn () => $this->assertSame(0, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
        $this->inTenant(fn () => $this->assertSame(0, AuditEvent::where('event', 'self_assessment.template_retired')->count()));

        // The first visit derives it from the class's version.
        $this->assertSame('Escala 0 a 20', $this->formPage()['criteria']['scale']);
    }

    // ============================== 7. migração com respostas

    #[Test]
    public function the_preview_says_what_will_happen_to_the_self_assessment(): void
    {
        $zeroToTwenty = $this->activateVersion('Escala 0 a 20');
        $preview = fn (AssessmentProfileVersion $to): array => $this->inTenant(
            fn (): array => app(MigrateClassProfile::class)->preview(SchoolClass::whereKey($this->class->id)->firstOrFail(), $to)['self_assessment'],
        );

        $this->assertSame(['state' => 'not_started', 'saved_count' => 0, 'student_count' => 0, 'periods' => []], $preview($zeroToTwenty));

        $props = $this->formPage();
        $this->assertSame('follows', $preview($zeroToTwenty)['state']);
        $this->assertSame('unchanged', $preview($this->levels)['state']);

        $global = $this->question($props, 'role', 'global');
        $this->save(['answers' => [$global['id'] => $global['levels'][3]['id']]])->assertSessionHasNoErrors();

        $this->assertSame([
            'state' => 'kept',
            'saved_count' => 1,
            'student_count' => 1,
            'periods' => [$this->period->label],
        ], $preview($zeroToTwenty));

        // What the teacher confirmed is on the record, with the section.
        $migration = $this->migrate($zeroToTwenty);
        $this->assertSame('kept', $migration->impact_preview['self_assessment']['state']);
    }

    #[Test]
    public function migrating_a_class_with_answers_keeps_the_self_assessment_where_it_was(): void
    {
        [$zeroToTwenty, $activeTemplateId] = $this->classMovedToZeroTwentyWithAnswers();

        $this->inTenant(function () use ($activeTemplateId): void {
            $this->assertSame(1, SelfAssessmentTemplate::where('class_id', $this->class->id)->count());
            $this->assertSame($this->levels->id, SelfAssessmentTemplate::findOrFail($activeTemplateId)->assessment_profile_version_id);
        });

        $props = $this->formPage();
        $configuration = $props['configuration'];

        $this->assertSame('previous', $configuration['state']);
        $this->assertSame('Escala 1 a 5', $configuration['scale']);
        $this->assertSame(1, $configuration['version']);
        $this->assertSame(['profile' => 'Avaliação final', 'version' => 2, 'scale' => 'Escala 0 a 20'], $configuration['class_criteria']);
        $this->assertSame(1, $configuration['saved_count']);
        $this->assertTrue($configuration['can_renew']);
        $this->assertSame('levels', $this->question($props, 'role', 'global')['answer']['mode'], 'as perguntas continuam a ser as das respostas');

        // The list says the same, and offers to start a new one.
        $show = $this->showPage();
        $this->assertSame('previous', $show['configuration']['state']);
        $this->assertTrue($show['configuration']['can_renew']);
        $this->assertNotEmpty($show['reflectionQuestions']);
    }

    // ============================== 8. nova autoavaliação

    #[Test]
    public function starting_a_new_self_assessment_keeps_everything_that_was_answered(): void
    {
        [$zeroToTwenty, $oldTemplateId] = $this->classMovedToZeroTwentyWithAnswers();

        $before = $this->inTenant(fn (): array => [
            'self_assessments' => SelfAssessment::orderBy('id')->get(['id', 'self_assessment_template_id', 'status', 'submitted_at'])->toArray(),
            'responses' => SelfAssessmentResponse::orderBy('id')->get(['id', 'self_assessment_id', 'self_assessment_question_id', 'scale_level_id', 'numeric_value', 'text_value'])->toArray(),
            'questions' => DB::table('self_assessment_questions')->where('self_assessment_template_id', $oldTemplateId)->orderBy('id')->get()->toArray(),
        ]);

        $this->actingAs($this->teacher)
            ->post("/classes/{$this->class->ulid}/self-assessments/renew", ['period' => $this->period->ulid])
            ->assertRedirect($this->showUrl())
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($before, $oldTemplateId, $zeroToTwenty): void {
            $templates = SelfAssessmentTemplate::where('class_id', $this->class->id)->orderBy('id')->get();

            $this->assertCount(2, $templates);
            $this->assertSame($oldTemplateId, $templates[0]->id);
            $this->assertFalse($templates[0]->is_active);
            $this->assertTrue($templates[1]->is_active);
            $this->assertSame($zeroToTwenty->id, $templates[1]->assessment_profile_version_id);

            // The old template, its questions, self-assessments and responses:
            // the same rows with the same values.
            $this->assertEquals($before['self_assessments'], SelfAssessment::orderBy('id')->get(['id', 'self_assessment_template_id', 'status', 'submitted_at'])->toArray());
            $this->assertEquals($before['responses'], SelfAssessmentResponse::orderBy('id')->get(['id', 'self_assessment_id', 'self_assessment_question_id', 'scale_level_id', 'numeric_value', 'text_value'])->toArray());
            $this->assertEquals($before['questions'], DB::table('self_assessment_questions')->where('self_assessment_template_id', $oldTemplateId)->orderBy('id')->get()->toArray());

            $event = AuditEvent::where('event', 'self_assessment.renewed')->sole();
            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame($templates[1]->id, $event->subject_id);
            $this->assertSame(1, $event->properties['saved_count']);
            $this->assertSame($this->levels->id, $event->properties['from_profile_version_id']);
            $this->assertSame($zeroToTwenty->id, $event->properties['to_profile_version_id']);
            $this->assertSame($oldTemplateId, $event->properties['previous_template_id']);
            $this->assertStringContainsString('(versão 2)', (string) $event->summary);

            $retired = AuditEvent::where('event', 'self_assessment.template_retired')->sole();
            $this->assertSame('renewed', $retired->properties['reason']);
        });

        // The form now asks the class's current criteria.
        $props = $this->formPage();
        $this->assertSame('current', $props['configuration']['state']);
        $this->assertSame('Escala 0 a 20', $props['configuration']['scale']);
        $this->assertSame('number', $this->question($props, 'role', 'global')['answer']['mode']);
    }

    #[Test]
    public function a_second_renewal_and_a_renewal_with_nothing_behind_are_refused_with_their_reason(): void
    {
        $this->classMovedToZeroTwentyWithAnswers();

        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")
            ->assertSessionHasErrors(['renew' => SelfAssessmentRenewalException::alreadyCurrent()->getMessage()]);

        $this->inTenant(fn () => $this->assertSame(2, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
    }

    #[Test]
    public function a_template_without_answers_has_nothing_to_renew(): void
    {
        $this->formPage();

        // The class moves outside the migration flow: the template, with no
        // answers, is behind the class but there is nothing to keep.
        $this->inTenant(fn () => SchoolClass::whereKey($this->class->id)->firstOrFail()->update(['assessment_profile_version_id' => $this->activateVersion('Escala 0 a 20')->id]));

        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")
            ->assertSessionHasErrors(['renew' => SelfAssessmentRenewalException::nothingToRenew()->getMessage()]);

        $this->inTenant(fn () => $this->assertSame(1, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
    }

    #[Test]
    public function a_class_without_a_profile_cannot_start_a_new_self_assessment(): void
    {
        $this->inTenant(fn () => SchoolClass::whereKey($this->class->id)->firstOrFail()->update(['assessment_profile_version_id' => null]));

        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")
            ->assertSessionHasErrors(['renew' => SelfAssessmentRenewalException::withoutProfile()->getMessage()]);
    }

    #[Test]
    public function in_technical_access_the_renewal_names_the_administrator_and_the_teacher(): void
    {
        $this->classMovedToZeroTwentyWithAnswers();
        $admin = $this->admin();

        $this->actingAs($this->teacher)
            ->withSession(['impersonator_id' => $admin->id, 'support_access_id' => 'sa-test-1'])
            ->post("/classes/{$this->class->ulid}/self-assessments/renew")
            ->assertSessionHasNoErrors();

        $this->inTenant(function () use ($admin): void {
            $event = AuditEvent::where('event', 'self_assessment.renewed')->sole();

            $this->assertSame($admin->id, $event->causer_id);
            $this->assertSame($this->teacher->id, $event->properties['acting_as_user_id']);
        });
    }

    // ============================== 9. formulário desatualizado

    #[Test]
    public function a_form_opened_before_the_renewal_is_refused_whole(): void
    {
        $open = $this->formPage();
        $global = $this->question($open, 'role', 'global');
        $rationale = $this->question($open, 'role', 'rationale');

        $this->save(['template' => $open['template'], 'answers' => [$global['id'] => $global['levels'][3]['id']]])->assertSessionHasNoErrors();

        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();

        $this->save(['template' => $open['template'], 'answers' => [$global['id'] => $global['levels'][1]['id']], 'texts' => [$rationale['id'] => 'Já mudou.']])
            ->assertSessionHasErrors('answers');

        $this->assertSame(1, $this->selfAssessmentCount(), 'nada novo foi gravado');
        $this->inTenant(fn () => $this->assertSame($global['levels'][3]['id'], SelfAssessmentResponse::where('self_assessment_question_id', $global['id'])->sole()->scale_level_id));
    }

    #[Test]
    public function a_form_that_names_another_template_is_refused_even_with_valid_ids(): void
    {
        $open = $this->formPage();
        $global = $this->question($open, 'role', 'global');

        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $props = $this->formPage();
        $this->assertNotSame($open['template'], $props['template']);

        $newGlobal = $this->question($props, 'role', 'global');

        // Right ids, wrong template: the form says it was built from the old one.
        $this->save(['template' => $open['template'], 'numbers' => [$newGlobal['id'] => '12']])->assertSessionHasErrors('answers');
        $this->assertSame(0, $this->responseCount());

        $this->save(['template' => $props['template'], 'numbers' => [$newGlobal['id'] => '12']])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->responseCount());
        $this->assertNotNull($global);
    }

    // ============================== 10. a ligação do aluno depois de nova autoavaliação

    #[Test]
    public function the_students_old_link_opens_the_new_self_assessment_and_the_earlier_one_stays(): void
    {
        $this->subscribeToPro();
        $link = $this->signedUrl();

        /** @var array<string, mixed> $first */
        $first = $this->get($link)->assertOk()->viewData('page')['props'];
        $this->assertFalse($first['hasEarlierRound']);
        $oldGlobal = $this->question($first, 'role', 'global');

        $this->post($link, ['template' => $first['template'], 'answers' => [$oldGlobal['id'] => $oldGlobal['levels'][3]['id']]])->assertSessionHasNoErrors();

        $reading = app(SelfAssessmentReading::class);
        $this->inTenant(fn () => $this->assertSame('4', $reading->global($reading->forClassPeriod($this->class, $this->period)->first())['code']));

        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();
        auth()->logout();

        // Until the student answers again, the earlier answer is what is read.
        $this->inTenant(fn () => $this->assertSame('4', $reading->global($reading->forClassPeriod($this->class, $this->period)->first())['code']));

        /** @var array<string, mixed> $second */
        $second = $this->get($link)->assertOk()->viewData('page')['props'];

        $this->assertTrue($second['hasEarlierRound']);
        $this->assertArrayNotHasKey('earlierRounds', $second);
        $this->assertArrayNotHasKey('configuration', $second);
        $this->assertNotSame($first['template'], $second['template']);
        $this->assertSame('Escala 0 a 20', $second['criteria']['scale']);

        $newGlobal = $this->question($second, 'role', 'global');
        $this->assertSame('number', $newGlobal['answer']['mode']);

        $this->post($link, ['template' => $second['template'], 'numbers' => [$newGlobal['id'] => '16']])->assertSessionHasNoErrors();

        $this->inTenant(function () use ($reading, $oldGlobal): void {
            $this->assertSame(2, SelfAssessment::count(), 'uma autoavaliação por modelo');

            $read = $reading->global($reading->forClassPeriod($this->class, $this->period)->first());
            $this->assertSame('16', $read['code']);
            $this->assertNull($read['sequence']);

            // The earlier one still holds what the student said.
            $this->assertSame(
                $oldGlobal['levels'][3]['id'],
                SelfAssessmentResponse::where('self_assessment_question_id', $oldGlobal['id'])->sole()->scale_level_id,
            );
        });

        // The teacher reads the earlier round, read-only, with the criteria it
        // was filled in with.
        $props = $this->formPage();
        $this->assertCount(1, $props['earlierRounds']);
        $this->assertSame('Avaliação final · versão 1 · Escala 1 a 5', $props['earlierRounds'][0]['criteria']);
        $this->assertNotNull($props['earlierRounds'][0]['submitted_at']);

        $values = array_column($props['earlierRounds'][0]['answers'], 'value', 'prompt');
        $this->assertContains('4 — Bom', $values);

        // …and the list marks the student.
        $row = $this->showPage()['rows'][0];
        $this->assertTrue($row['has_earlier_round']);
        $this->assertSame(SelfAssessmentStatus::Submitted->value, $row['status']);
    }

    // ============================== 11. alterações de domínios e pesos

    #[Test]
    public function new_domains_and_weights_are_asked_and_old_answers_are_never_redistributed(): void
    {
        $props = $this->formPage();
        $old = $this->question($props, 'domain', 'Comportamento');
        $knowledge = $this->question($props, 'domain', 'Conhecimento');

        $this->save(['answers' => [$old['id'] => $old['levels'][1]['id'], $knowledge['id'] => $knowledge['levels'][3]['id']]])->assertSessionHasNoErrors();

        $version = $this->activateVersion('Escala 0 a 20', [['name' => 'Conhecimento', 'weight' => 70], ['name' => 'Participação', 'weight' => 30]]);
        $this->migrate($version);
        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();

        $fresh = $this->formPage();
        $domains = array_values(array_filter(array_column($fresh['questions'], 'domain')));
        sort($domains);

        $this->assertSame(['Conhecimento', 'Participação'], $domains);
        $this->assertSame([['name' => 'Conhecimento', 'weight' => '70'], ['name' => 'Participação', 'weight' => '30']], $fresh['criteria']['domains']);
        $this->assertSame($fresh['criteria']['domains'], $fresh['configuration']['domains']);

        // The answers to the domain that left stay as they were, on the old template.
        $this->inTenant(function () use ($old, $knowledge): void {
            $this->assertSame(2, SelfAssessmentResponse::count());
            $this->assertNotNull(SelfAssessmentResponse::where('self_assessment_question_id', $old['id'])->sole()->scale_level_id);
            $this->assertNotNull(SelfAssessmentResponse::where('self_assessment_question_id', $knowledge['id'])->sole()->scale_level_id);
        });
    }

    // ============================== 12. perguntas de reflexão

    #[Test]
    public function an_unanswered_reflection_can_be_reworded_and_it_is_audited(): void
    {
        $props = $this->formPage();
        $liked = $this->question($props, 'role', 'liked');

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$liked['id'] => '  Que atividade te marcou mais?  ']])
            ->assertSessionHasNoErrors();

        $this->assertSame('Que atividade te marcou mais?', $this->question($this->formPage(), 'role', 'liked')['prompt']);

        $this->inTenant(function () use ($liked): void {
            $event = AuditEvent::where('event', 'self_assessment.questions_reworded')->sole();

            $this->assertSame($this->teacher->id, $event->causer_id);
            $this->assertSame([[
                'question_id' => $liked['id'],
                'role' => 'liked',
                'from' => 'Atividade de que mais gostei',
                'to' => 'Que atividade te marcou mais?',
            ]], $event->properties['changes']);
        });

        $show = $this->showPage();
        $byRole = array_column($show['reflectionQuestions'], null, 'role');
        $this->assertSame('Que atividade te marcou mais?', $byRole['liked']['prompt']);
        $this->assertFalse($byRole['liked']['answered']);
        $this->assertSame(['rationale', 'improvement', 'liked', 'struggled'], array_column($show['reflectionQuestions'], 'role'));
    }

    #[Test]
    public function an_answered_reflection_keeps_its_wording_and_nothing_else_is_saved_with_it(): void
    {
        $props = $this->formPage();
        $rationale = $this->question($props, 'role', 'rationale');
        $liked = $this->question($props, 'role', 'liked');

        $this->save(['texts' => [$rationale['id'] => 'Porque trabalhei.']])->assertSessionHasNoErrors();

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [
                $rationale['id'] => 'Outra pergunta qualquer?',
                $liked['id'] => 'Uma pergunta válida?',
            ]])
            ->assertSessionHasErrors("prompts.{$rationale['id']}");

        $after = $this->formPage();
        $this->assertSame($rationale['prompt'], $this->question($after, 'role', 'rationale')['prompt']);
        $this->assertSame($liked['prompt'], $this->question($after, 'role', 'liked')['prompt'], 'validado tudo antes de gravar');
        $this->inTenant(fn () => $this->assertSame(0, AuditEvent::where('event', 'self_assessment.questions_reworded')->count()));

        $byRole = array_column($this->showPage()['reflectionQuestions'], null, 'role');
        $this->assertTrue($byRole['rationale']['answered']);
    }

    #[Test]
    public function only_the_written_questions_can_be_reworded(): void
    {
        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $domain = $this->question($props, 'domain', 'Conhecimento');

        foreach ([$global, $domain] as $question) {
            $this->actingAs($this->teacher)
                ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$question['id'] => 'Redação nova.']])
                ->assertSessionHasErrors('prompts');
        }

        $after = $this->formPage();
        $this->assertSame($global['prompt'], $this->question($after, 'role', 'global')['prompt']);
        $this->assertSame($domain['prompt'], $this->question($after, 'domain', 'Conhecimento')['prompt']);

        // A question that is not in the template at all.
        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [999999 => 'Redação nova.']])
            ->assertSessionHasErrors('prompts');

        // And the wording has its limits.
        $liked = $this->question($props, 'role', 'liked');
        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$liked['id'] => 'ab']])
            ->assertSessionHasErrors("prompts.{$liked['id']}");
    }

    #[Test]
    public function a_wording_the_teacher_wrote_is_inherited_and_the_generated_ones_follow_the_new_scale(): void
    {
        $props = $this->formPage();
        $improvement = $this->question($props, 'role', 'improvement');
        $liked = $this->question($props, 'role', 'liked');
        $global = $this->question($props, 'role', 'global');

        $this->assertSame('Porque proponho este nível?', $this->question($props, 'role', 'rationale')['prompt']);
        $this->assertSame('Nível que proponho para a minha avaliação neste período', $global['prompt']);

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [
                $improvement['id'] => 'O que vais fazer para melhorar?',
                $liked['id'] => 'Que atividade te marcou mais?',
            ]])
            ->assertSessionHasNoErrors();

        $this->save(['answers' => [$global['id'] => $global['levels'][2]['id']]])->assertSessionHasNoErrors();

        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();

        $fresh = $this->formPage();

        // The teacher's own wording is carried over…
        $this->assertSame('O que vais fazer para melhorar?', $this->question($fresh, 'role', 'improvement')['prompt']);
        $this->assertSame('Que atividade te marcou mais?', $this->question($fresh, 'role', 'liked')['prompt']);
        // …what the app wrote is written again for the new scale.
        $this->assertSame('Porque proponho esta classificação?', $this->question($fresh, 'role', 'rationale')['prompt']);
        $this->assertSame('Atividade em que senti mais dificuldades', $this->question($fresh, 'role', 'struggled')['prompt']);
        $this->assertSame('Classificação que proponho para a minha avaliação neste período', $this->question($fresh, 'role', 'global')['prompt']);

        // Nothing is inherited by a question that already had answers: the
        // inherited wording is a new question, with no answers yet.
        $byRole = array_column($this->showPage()['reflectionQuestions'], null, 'role');
        $this->assertFalse($byRole['improvement']['answered']);
    }

    #[Test]
    public function the_template_that_follows_a_migration_inherits_the_teachers_wording_too(): void
    {
        $props = $this->formPage();
        $liked = $this->question($props, 'role', 'liked');

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$liked['id'] => 'Que atividade te marcou mais?']])
            ->assertSessionHasNoErrors();

        $this->migrate($this->activateVersion('Escala 0 a 20'));

        $this->assertSame('Que atividade te marcou mais?', $this->question($this->formPage(), 'role', 'liked')['prompt']);
    }

    // ============================== 13. isolamento

    #[Test]
    public function another_organization_reaches_none_of_it(): void
    {
        $this->classMovedToZeroTwentyWithAnswers();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get($this->showUrl())->assertNotFound();
        $this->actingAs($stranger)->get($this->formUrl())->assertNotFound();
        $this->actingAs($stranger)->post($this->formUrl(), ['numbers' => [1 => '10']])->assertNotFound();
        $this->actingAs($stranger)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertNotFound();
        $this->actingAs($stranger)->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [1 => 'Uma pergunta?']])->assertNotFound();

        $this->inTenant(fn () => $this->assertSame(1, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
    }

    // ============================== 14. a decisão do professor não se mexe

    #[Test]
    public function a_published_classification_is_byte_for_byte_the_same_after_all_of_it(): void
    {
        $snapshot = fn (): array => $this->inTenant(fn (): array => Classification::query()->orderBy('id')
            ->get(['id', 'final_value', 'final_scale_level_id', 'status'])
            ->map(fn (Classification $classification): array => [
                'id' => $classification->id,
                'final_value' => $classification->getRawOriginal('final_value'),
                'final_scale_level_id' => $classification->final_scale_level_id,
                'status' => $classification->status->value,
            ])->all());

        // A decision already taken (published) and an open proposal, written as
        // facts: what matters here is that nothing below touches them.
        $this->inTenant(function (): void {
            $four = Scale::where('name', 'Escala 1 a 5')->firstOrFail()->levels()->where('code', '4')->firstOrFail();
            $enrollments = $this->class->enrollments()->orderBy('class_number')->take(2)->get();

            $decided = Classification::create([
                'enrollment_id' => $enrollments[0]->id,
                'academic_period_id' => $this->period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $this->levels->id,
                'status' => ClassificationStatus::Proposed,
            ]);
            $decided->forceFill([
                'status' => ClassificationStatus::Published,
                'final_scale_level_id' => $four->id,
                'confirmed_by' => $this->teacher->id,
                'confirmed_at' => now(),
                'published_at' => now(),
            ])->save();

            Classification::create([
                'enrollment_id' => $enrollments[1]->id,
                'academic_period_id' => $this->period->id,
                'scope' => ClassificationScope::Period,
                'assessment_profile_version_id' => $this->levels->id,
                'status' => ClassificationStatus::Proposed,
            ]);
        });

        $before = $snapshot();
        $this->assertContains('published', array_column($before, 'status'));

        $props = $this->formPage();
        $global = $this->question($props, 'role', 'global');
        $this->save(['answers' => [$global['id'] => $global['levels'][4]['id']]])->assertSessionHasNoErrors();
        $this->assertSame($before, $snapshot(), 'gravar uma autoavaliação não toca na classificação');

        $this->migrate($this->activateVersion('Escala 0 a 20'));
        $published = array_values(array_filter($snapshot(), fn (array $row): bool => $row['status'] === 'published'));
        $this->assertSame(
            array_values(array_filter($before, fn (array $row): bool => $row['status'] === 'published')),
            $published,
            'a decisão publicada não muda com a migração',
        );

        $this->actingAs($this->teacher)->post("/classes/{$this->class->ulid}/self-assessments/renew")->assertSessionHasNoErrors();
        $this->assertSame($published, array_values(array_filter($snapshot(), fn (array $row): bool => $row['status'] === 'published')), 'nem com a nova autoavaliação');

        $newGlobal = $this->question($this->formPage(), 'role', 'global');
        $this->save(['numbers' => [$newGlobal['id'] => '20']])->assertSessionHasNoErrors();
        $this->assertSame($published, array_values(array_filter($snapshot(), fn (array $row): bool => $row['status'] === 'published')));
    }

    #[Test]
    public function the_service_refuses_what_the_screen_would_have_refused(): void
    {
        $provider = app(SelfAssessmentTemplateProvider::class);

        $this->expectException(SelfAssessmentRenewalException::class);

        $this->inTenant(fn () => $provider->renew(SchoolClass::whereKey($this->class->id)->firstOrFail(), $this->teacher));
    }

    // ============================== 15. revisão adversarial

    #[Test]
    public function a_student_of_another_class_is_not_reached_through_this_one(): void
    {
        $stranger = $this->inTenant(function (): Enrollment {
            $other = SchoolClass::create([
                'academic_year_id' => $this->class->academic_year_id, 'subject_id' => $this->class->subject_id,
                'label' => '7.º B', 'grade_level' => '7.º', 'status' => 'active',
                'assessment_profile_version_id' => $this->levels->id,
            ]);
            $other->teachers()->attach($this->teacher, ['role' => 'owner']);

            return app(StudentEnrollmentService::class)->enrollNew($other, ['name' => 'Aluno de Outra Turma', 'class_number' => 1, 'enrolled_on' => '2026-09-14']);
        });

        // The same teacher, the same organization — but the student is not one
        // of THIS class's, and neither the form nor its save may reach them.
        $url = "/classes/{$this->class->ulid}/self-assessments/{$this->period->ulid}/{$stranger->ulid}";

        $this->actingAs($this->teacher)->get($url)->assertNotFound();
        $this->actingAs($this->teacher)->post($url, ['texts' => []])->assertNotFound();
        $this->assertSame(0, $this->selfAssessmentCount());
    }

    #[Test]
    public function a_template_from_before_the_roles_is_completed_on_its_own_scale_after_the_class_moved(): void
    {
        // A template authored before roles existed, answered on 1–5…
        $this->inTenant(function (): void {
            $template = SelfAssessmentTemplate::create([
                'assessment_profile_version_id' => $this->levels->id,
                'class_id' => $this->class->id,
                'name' => 'Autoavaliação anterior',
                'is_active' => true,
            ]);
            $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();
            $question = $template->questions()->create([
                'domain_id' => Domain::where('name', 'Conhecimento')->firstOrFail()->id,
                'prompt' => 'Como te avalias em Conhecimento?',
                'answer_kind' => 'scale',
                'scale_id' => $scale->id,
                'sequence' => 1,
            ]);
            $selfAssessment = SelfAssessment::create([
                'enrollment_id' => $this->class->enrollments()->orderBy('class_number')->firstOrFail()->id,
                'academic_period_id' => $this->period->id,
                'self_assessment_template_id' => $template->id,
                'status' => SelfAssessmentStatus::Submitted,
                'filled_by' => SelfAssessmentFilledBy::TeacherInterview,
                'submitted_at' => now(),
            ]);
            SelfAssessmentResponse::create([
                'self_assessment_id' => $selfAssessment->id,
                'self_assessment_question_id' => $question->id,
                'scale_level_id' => $scale->levels()->where('code', '4')->firstOrFail()->id,
            ]);
        });

        // …and the class then moved to 0–20 before anyone opened it again.
        $this->migrate($this->activateVersion('Escala 0 a 20'));

        $global = $this->question($this->formPage(), 'role', 'global');

        // Completed with the overall judgement on the template's OWN scale —
        // never on the class's new one: one form, one scale.
        $this->assertSame('levels', $global['answer']['mode']);
        $this->assertSame('Escala 1 a 5', $global['scale']);
        $this->inTenant(fn () => $this->assertSame(1, SelfAssessmentTemplate::where('class_id', $this->class->id)->count()));
    }

    #[Test]
    public function an_answer_written_to_a_reflection_reworded_since_the_form_opened_is_refused(): void
    {
        $props = $this->formPage();
        $liked = $this->question($props, 'role', 'liked');

        $this->actingAs($this->teacher)
            ->put("/classes/{$this->class->ulid}/self-assessments/questions", ['prompts' => [$liked['id'] => 'Que atividade te marcou mais?']])
            ->assertSessionHasNoErrors();

        // The open form still shows the old sentence: what it carries is an
        // answer to THAT sentence, and it is not stored under the new one.
        $this->save([
            'template' => $props['template'],
            'revision' => $props['revision'],
            'texts' => [$liked['id'] => 'A visita de estudo.'],
        ])->assertSessionHasErrors('answers');

        $this->assertSame(0, $this->selfAssessmentCount());

        // The form, opened again, carries the new wording — and saves.
        $fresh = $this->formPage();
        $this->assertNotSame($props['revision'], $fresh['revision']);

        $this->save([
            'template' => $fresh['template'],
            'revision' => $fresh['revision'],
            'texts' => [$liked['id'] => 'A visita de estudo.'],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->selfAssessmentCount());
    }
}
