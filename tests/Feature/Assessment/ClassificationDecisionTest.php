<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\AuditEvent;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The decision, on the scale the class is actually classified on.
 *
 * The engine's weighted average is a percentage, and it stays one. What the
 * teacher assigns is a classification: a LEVEL where the scale is a closed list
 * of them, a NUMBER where the scale is an interval. Showing 66,0 in a column
 * headed with the grade — beside a proposal reading 3 — was reading the
 * technical figure as if it were the decision.
 */
class ClassificationDecisionTest extends TestCase
{
    use RefreshDatabase;

    private function seedDemo(): User
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return $teacher;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(User $teacher, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), $callback);
    }

    private function classificationFor($period, string $name): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $classification) => $classification->enrollment->student->identity->display_name === $name);
    }

    // ------------------------------------------------ 1. an interval scale

    /**
     * Moves the demo class onto the system 0–20 scale, which has no levels of
     * its own — the interval is the definition of what is valid on it.
     *
     * @return array{SchoolClass, AcademicPeriod}
     */
    private function onNumericScale(User $teacher): array
    {
        return $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            $numeric = Scale::where('name', 'Escala 0 a 20')->firstOrFail();

            DB::table('assessment_profile_versions')
                ->where('id', $class->assessment_profile_version_id)
                ->update(['scale_id' => $numeric->id]);

            return [$class->fresh(), $period];
        });
    }

    #[Test]
    public function on_an_interval_scale_the_decision_is_a_number_on_that_interval(): void
    {
        $teacher = $this->seedDemo();
        [$class, $period] = $this->onNumericScale($teacher);

        $this->asTenant($teacher, function () use ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            app(ConfirmClassification::class)->confirm($carolina, $teacher, null, '15');
            $carolina->refresh();

            // 15 on a 0–20 — the classification. Not the 91% behind it, and no
            // level, because this scale has none.
            $this->assertSame('15.000', $carolina->final_value);
            $this->assertNull($carolina->final_scale_level_id);
            $this->assertSame('91.000', $carolina->proposed_value);
        });
    }

    #[Test]
    public function a_value_outside_the_scale_is_refused_by_the_scales_own_limits(): void
    {
        $teacher = $this->seedDemo();
        [$class, $period] = $this->onNumericScale($teacher);

        $this->asTenant($teacher, function () use ($class, $period, $teacher): void {
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            // 25 is not a classification on a 0–20. The limits come from the
            // scale, never from a range written into the code.
            $this->expectException(ClassificationDecisionException::class);
            app(ConfirmClassification::class)->confirm($carolina, $teacher, null, '25');
        });
    }

    // ------------------------------------------------------ 2. over HTTP

    #[Test]
    public function using_the_proposal_is_an_explicit_act_that_records_the_proposal_as_the_decision(): void
    {
        $teacher = $this->seedDemo();

        [$ulid, $proposedLevelId] = $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');

            return [$carolina->ulid, $carolina->proposed_scale_level_id];
        });

        // Nothing is decided until this post happens — a proposal sitting in the
        // table is not a grade (§6).
        $this->actingAs($teacher)->post($this->decideUrl($ulid), [])->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $proposedLevelId): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($proposedLevelId, $carolina->final_scale_level_id);
            $this->assertFalse($carolina->wasOverridden());
        });
    }

    #[Test]
    public function assigning_another_level_over_http_needs_no_justification(): void
    {
        $teacher = $this->seedDemo();

        [$ulid, $otherLevelId] = $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $other = $class->profileVersion->scale->levels()
                ->where('id', '!=', $carolina->proposed_scale_level_id)->firstOrFail();

            return [$carolina->ulid, $other->id];
        });

        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $otherLevelId])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $otherLevelId): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame($otherLevelId, $carolina->final_scale_level_id);
            $this->assertNull($carolina->override_reason);
            $this->assertTrue($carolina->wasOverridden());
        });
    }

    // ------------------------------- 3. confirmed is decided, not closed

    #[Test]
    public function a_confirmed_classification_can_still_be_changed_before_it_is_published(): void
    {
        $teacher = $this->seedDemo();

        [$ulid, $three, $four, $firstVersion] = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $levels = $class->profileVersion->scale->levels()->get();
            $three = $levels->firstWhere('code', '3');
            $four = $levels->firstWhere('code', '4');

            app(ConfirmClassification::class)->confirm($carolina, $teacher, $three->id);

            return [$carolina->ulid, $three->id, $four->id, $carolina->refresh()->lock_version];
        });

        // Confirmed, and still the teacher's to revise: nothing has been
        // communicated yet.
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $three, $four, $firstVersion, $teacher): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            // The SAME row, rewritten — never a second one for the same
            // (enrolment, period, scope).
            $this->assertSame(1, Classification::query()
                ->where('enrollment_id', $carolina->enrollment_id)
                ->where('academic_period_id', $carolina->academic_period_id)
                ->where('scope', ClassificationScope::Period)
                ->count());
            $this->assertNull($carolina->superseded_by_id);

            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($four, $carolina->final_scale_level_id);
            $this->assertSame('4.000', $carolina->final_value);
            $this->assertSame($teacher->id, $carolina->confirmed_by);
            $this->assertGreaterThan($firstVersion, $carolina->lock_version);

            // The proposal is still untouched, as it has been all along.
            $this->assertSame('91.000', $carolina->proposed_value);

            // …and the previous decision is preserved where the history lives.
            $event = AuditEvent::where('event', 'classification.redecided')->latest('id')->firstOrFail();
            $this->assertSame($three, $event->properties['previous_final_scale_level_id']);
            $this->assertSame('3.000', $event->properties['previous_final_value']);
            $this->assertSame($four, $event->properties['final_scale_level_id']);
            $this->assertSame($teacher->id, $event->causer_id);
        });
    }

    #[Test]
    public function changing_a_confirmed_classification_shows_through_to_results(): void
    {
        $teacher = $this->seedDemo();

        [$classUlid, $periodUlid, $ulid, $four] = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $levels = $class->profileVersion->scale->levels()->get();
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $levels->firstWhere('code', '3')->id);

            return [$class->ulid, $period->ulid, $carolina->ulid, $levels->firstWhere('code', '4')->id];
        });

        $this->actingAs($teacher)->post($this->decideUrl($ulid), ['final_scale_level_id' => $four]);

        $onClassifications = $this->rowFrom($this->actingAs($teacher)->get("/classes/{$classUlid}/classifications/{$periodUlid}"));
        $onResults = $this->rowFrom($this->actingAs($teacher)->get("/classes/{$classUlid}/results/{$periodUlid}"));

        $this->assertSame('4', $onClassifications['classification']['decision']['code']);
        $this->assertSame('4', $onResults['classification']['final']['code']);

        // And the proposal is untouched by any of it — the same on both screens,
        // and not the level the teacher ended up assigning.
        $proposal = $onClassifications['classification']['proposal']['value'];
        $this->assertSame($onResults['proposal']['value'], $proposal);
        $this->assertNotSame('4', $proposal);
    }

    #[Test]
    public function a_published_classification_is_not_editable_and_says_why(): void
    {
        $teacher = $this->seedDemo();

        [$classUlid, $periodUlid, $ulid, $four] = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $levels = $class->profileVersion->scale->levels()->get();
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $levels->firstWhere('code', '3')->id);
            app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);

            return [$class->ulid, $period->ulid, $carolina->ulid, $levels->firstWhere('code', '4')->id];
        });

        // Refused, and told why — never edited quietly behind everyone it was
        // communicated to, and never through a second parallel row.
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertSessionHasErrors('final_value');

        $this->asTenant($teacher, function () use ($ulid, $four): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame(ClassificationStatus::Published, $carolina->status);
            $this->assertNotSame($four, $carolina->final_scale_level_id);
            $this->assertSame('3.000', $carolina->final_value);
        });

        // …and the screen offers no way in.
        $row = $this->rowFrom($this->actingAs($teacher)->get("/classes/{$classUlid}/classifications/{$periodUlid}"));
        $this->assertFalse($row['classification']['can_change']);
        $this->assertFalse($row['classification']['can_confirm']);
        $this->assertTrue($row['classification']['is_published']);
    }

    #[Test]
    public function changing_a_confirmed_classification_still_needs_a_decision(): void
    {
        $teacher = $this->seedDemo();

        $ulid = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            return $carolina->ulid;
        });

        // «Usar proposta» is how a FIRST decision is made. Revising one means
        // saying what it becomes.
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), [])
            ->assertSessionHasErrors('final_value');
    }

    #[Test]
    public function a_teacher_from_another_organization_cannot_change_a_confirmed_classification(): void
    {
        $teacher = $this->seedDemo();

        $ulid = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            return $carolina->ulid;
        });

        // The tenant scope hides the row entirely — its existence is not even
        // revealed, exactly as when it was still a proposal.
        $this->actingAs(User::factory()->create())
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => 1])
            ->assertNotFound();
    }

    // --------------------------------------- 4. the two screens must agree

    #[Test]
    public function results_and_classifications_show_the_same_decision_for_the_same_student(): void
    {
        $teacher = $this->seedDemo();

        [$classUlid, $periodUlid, $chosenCode] = $this->asTenant($teacher, function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            $carolina = $this->classificationFor($period, 'Carolina Nunes');
            $chosen = $class->profileVersion->scale->levels()->where('code', '3')->firstOrFail();
            app(ConfirmClassification::class)->confirm($carolina, $teacher, $chosen->id);

            return [$class->ulid, $period->ulid, $chosen->code];
        });

        $onResults = $this->rowFrom(
            $this->actingAs($teacher)->get("/classes/{$classUlid}/results/{$periodUlid}"),
        );
        $onClassifications = $this->rowFrom(
            $this->actingAs($teacher)->get("/classes/{$classUlid}/classifications/{$periodUlid}"),
        );

        // The same judgement, read in the same token, on both screens (§10).
        $this->assertSame($chosenCode, $onResults['classification']['final']['code']);
        $this->assertSame($chosenCode, $onClassifications['classification']['decision']['code']);

        // …and the proposal stays what it always was, on both.
        $this->assertSame(
            $onResults['proposal']['value'],
            $onClassifications['classification']['proposal']['value'],
        );

        // The self-assessment is a third, independent column and neither screen
        // derives it from the other two.
        $this->assertSame($onResults['self_assessment'], $onClassifications['self_assessment']);
    }

    /**
     * Carolina's row out of an Inertia page, whichever screen it came from.
     *
     * @return array<string, mixed>
     */
    private function rowFrom(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        foreach ($page['props']['rows'] as $row) {
            if ($row['name'] === 'Carolina Nunes') {
                return $row;
            }
        }

        $this->fail('Carolina Nunes não apareceu no ecrã.');
    }

    /**
     * The one endpoint a decision is written through, addressed by whose
     * decision it is: this student, this period. Resolved here from a stored
     * classification only because these tests already have one — the route
     * itself needs none, which is the point of it.
     */
    private function decideUrl(string $classificationUlid): string
    {
        $owner = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        return app(CurrentOrganization::class)->runFor($owner->personalOrganization(), function () use ($classificationUlid): string {
            $classification = Classification::where('ulid', $classificationUlid)->firstOrFail();
            $class = $classification->enrollment->schoolClass;

            return "/classes/{$class->ulid}/classifications/{$classification->academicPeriod->ulid}/{$classification->enrollment->ulid}/decide";
        });
    }

    // ------------------------------------ o âmbito acumulado saiu deste ecrã

    /**
     * NÃO HÁ SEGUNDO ÂMBITO DE DECISÃO NESTE ECRÃ.
     *
     * O seletor «período / acumulado» prometia um que nunca existiu: «propor» e
     * «publicar» escreviam em linhas de âmbito acumulado, mas confirmar escreve
     * sempre na do período — o professor podia gerar propostas que não havia
     * como decidir, e ficar convencido de que estava a classificar o ano. Com
     * uma classificação global por ano, a da última unidade formal, deixou de
     * haver a pergunta a que aquele botão respondia.
     */
    #[Test]
    public function the_screen_no_longer_offers_accumulated_as_a_scope_of_decision(): void
    {
        $screen = (string) preg_replace(
            '/\s+/u',
            ' ',
            (string) file_get_contents(base_path('resources/js/pages/classifications/Show.vue')),
        );

        // Nem o seletor, nem a prop que o alimentava, nem o âmbito a viajar nos
        // pedidos: o ecrã deixou de ter como falar de outro âmbito.
        $this->assertStringNotContainsString('selectScope', $screen);
        $this->assertStringNotContainsString('scope: props.scope', $screen);
        $this->assertStringNotContainsString("scope === 'accumulated'", $screen);

        // E a leitura acumulada não se perdeu — mudou para onde é analítica e
        // traz a decomposição consigo.
        $this->assertStringContainsString('results/quadro-sintese', $screen);
    }

    /**
     * E A DECISÃO CONTINUA A ESCREVER-SE ONDE SEMPRE SE ESCREVEU.
     *
     * A limpeza acima é de interface. O endpoint é o mesmo, o serviço é o mesmo,
     * e a linha que fica é a de âmbito PERÍODO — nenhuma linha acumulada nasce
     * ao lado dela.
     */
    #[Test]
    public function the_decision_still_writes_the_period_row_through_the_canonical_endpoint(): void
    {
        $teacher = $this->seedDemo();

        [$ulid, $levelId] = $this->asTenant($teacher, function (): array {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods->first();

            app(ProposeClassifications::class)->forPeriod($class, $period);

            $classification = $this->classificationFor($period, 'Carolina Nunes');

            return [
                (string) $classification->ulid,
                (int) $class->profileVersion->scale->levels->firstWhere('code', '4')->id,
            ];
        });

        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), [
                'final_scale_level_id' => $levelId,
                'final_value' => null,
                'override_reason' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $levelId): void {
            $written = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame(ClassificationScope::Period, $written->scope);
            $this->assertSame($levelId, (int) $written->final_scale_level_id);

            $this->assertSame(
                0,
                Classification::where('scope', ClassificationScope::Accumulated)->count(),
                'Decidir aqui não abre nenhuma classificação de âmbito acumulado.',
            );
        });
    }
}
