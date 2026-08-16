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
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Deciding from Resultados, which is where the student can actually be seen.
 *
 * The domains, the weighted average, the proposal and what the student said
 * about themselves are all on this screen; the classification belongs beside
 * them, not one navigation away. What it must NOT be is a second way of writing
 * a grade: it posts to the very endpoint Classificações posts to, so there is
 * one service, one validation, one lock, one lifecycle and one trail.
 */
class ResultsDecisionTest extends TestCase
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

    /**
     * The class, its first period, and Carolina's proposal — the row every test
     * here works on.
     *
     * @return array{string, string, string}
     */
    private function proposeAndReturnContext(User $teacher): array
    {
        return $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            return [$class->ulid, $period->ulid, $this->carolina($period)->ulid];
        });
    }

    private function carolina($period): Classification
    {
        return Classification::query()
            ->where('academic_period_id', $period->id)
            ->where('scope', ClassificationScope::Period)
            ->get()
            ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');
    }

    private function levelId(User $teacher, string $code): int
    {
        return $this->asTenant($teacher, fn (): int => SchoolClass::where('label', '7.º A')->firstOrFail()
            ->profileVersion->scale->levels()->where('code', $code)->firstOrFail()->id);
    }

    /**
     * @return array<string, mixed>
     */
    private function pageOf(TestResponse $response): array
    {
        $response->assertOk();

        /** @var array<string, mixed> $page */
        $page = $response->viewData('page');

        return $page['props'];
    }

    /**
     * @return array<string, mixed>
     */
    private function rowOf(TestResponse $response, string $name = 'Carolina Nunes'): array
    {
        foreach ($this->pageOf($response)['rows'] as $row) {
            if ($row['name'] === $name) {
                return $row;
            }
        }

        $this->fail("{$name} não apareceu no ecrã.");
    }

    private function results(User $teacher, string $classUlid, string $periodUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/results/{$periodUlid}");
    }

    private function classifications(User $teacher, string $classUlid, string $periodUlid): TestResponse
    {
        return $this->actingAs($teacher)->get("/classes/{$classUlid}/classifications/{$periodUlid}");
    }

    // ------------------------------------------------- 1. one way of writing

    #[Test]
    public function the_screen_writes_through_the_canonical_endpoint_and_no_other(): void
    {
        $screen = (string) file_get_contents(resource_path('js/pages/results/Show.vue'));

        // The same URL Classificações posts to, and the ONLY write this screen
        // makes. A parallel endpoint would be a second lifecycle to keep in
        // agreement with this one (§3).
        $this->assertStringContainsString(
            '/classes/${props.schoolClass.ulid}/classifications/${selectedPeriod.value.ulid}/${row.enrollment_ulid}/decide',
            $screen,
        );
        $this->assertSame(1, substr_count($screen, 'router.post('));

        // Addressed by the student and the period, so a period with no
        // proposals yet is written exactly like any other.
        $this->assertStringNotContainsString('classification.ulid', $screen);
    }

    // -------------------------------------------------- 2. with no decision

    #[Test]
    public function the_cell_offers_the_scales_own_bands_and_nothing_is_preselected(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid] = $this->proposeAndReturnContext($teacher);

        $props = $this->pageOf($this->results($teacher, $classUlid, $periodUlid));

        // Read from the scale of the profile version, never from a year of
        // schooling or a hardcoded 1–5.
        $this->assertSame('Nível atribuído', $props['decision']['label']);
        $this->assertTrue($props['decision']['classifies_by_level']);
        $this->assertSame(
            ['1', '2', '3', '4', '5'],
            array_column($props['decision']['levels'], 'code'),
        );

        $row = $this->rowOf($this->results($teacher, $classUlid, $periodUlid));
        $this->assertTrue($row['classification']['can_change']);
        $this->assertTrue($row['classification']['can_confirm']);
        // Nothing decided until the teacher decides it (§5).
        $this->assertNull($row['classification']['final']);
    }

    #[Test]
    public function choosing_a_level_from_results_records_it_and_shows_on_both_screens(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $ulid] = $this->proposeAndReturnContext($teacher);
        $four = $this->levelId($teacher, '4');

        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $four): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame(ClassificationStatus::Confirmed, $carolina->status);
            $this->assertSame($four, $carolina->final_scale_level_id);
            $this->assertSame('4.000', $carolina->final_value);
        });

        $this->assertSame('4', $this->rowOf($this->results($teacher, $classUlid, $periodUlid))['classification']['final']['code']);
        $this->assertSame('4', $this->rowOf($this->classifications($teacher, $classUlid, $periodUlid))['classification']['decision']['code']);
    }

    #[Test]
    public function using_the_proposal_from_results_adopts_it_and_nothing_else(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $ulid] = $this->proposeAndReturnContext($teacher);

        $proposed = $this->asTenant($teacher, fn () => Classification::where('ulid', $ulid)->firstOrFail()->proposed_scale_level_id);

        // The same empty post the button sends: no decision of its own, which
        // the service reads as «the proposal is it».
        $this->actingAs($teacher)->post($this->decideUrl($ulid), [])->assertSessionHasNoErrors();

        $row = $this->rowOf($this->results($teacher, $classUlid, $periodUlid));
        $this->assertSame($row['proposal']['value'], $row['classification']['final']['code']);
        $this->assertFalse($row['classification']['differs_from_proposal']);

        $this->asTenant($teacher, function () use ($ulid, $proposed): void {
            $this->assertSame($proposed, Classification::where('ulid', $ulid)->firstOrFail()->final_scale_level_id);
        });
    }

    // --------------------------------------------- 3. changing one's mind

    #[Test]
    public function a_confirmed_decision_can_be_changed_from_results_before_publication(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $ulid] = $this->proposeAndReturnContext($teacher);
        $three = $this->levelId($teacher, '3');
        $four = $this->levelId($teacher, '4');

        $this->actingAs($teacher)->post($this->decideUrl($ulid), ['final_scale_level_id' => $three]);
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $three, $four): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            // The same row — never a parallel one.
            $this->assertSame(1, Classification::query()
                ->where('enrollment_id', $carolina->enrollment_id)
                ->where('academic_period_id', $carolina->academic_period_id)
                ->where('scope', ClassificationScope::Period)
                ->count());
            $this->assertSame($four, $carolina->final_scale_level_id);

            // …with the previous decision kept where the history lives.
            $event = AuditEvent::where('event', 'classification.redecided')->latest('id')->firstOrFail();
            $this->assertSame($three, $event->properties['previous_final_scale_level_id']);
        });

        $this->assertSame('4', $this->rowOf($this->classifications($teacher, $classUlid, $periodUlid))['classification']['decision']['code']);
    }

    // ------------------------------------------------------ 4. published

    #[Test]
    public function a_published_classification_shows_its_value_and_offers_no_way_in(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $ulid] = $this->proposeAndReturnContext($teacher);
        $three = $this->levelId($teacher, '3');
        $four = $this->levelId($teacher, '4');

        $this->actingAs($teacher)->post($this->decideUrl($ulid), ['final_scale_level_id' => $three]);

        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(PublishClassifications::class)->forPeriod($class, $period, ClassificationScope::Period);
        });

        $row = $this->rowOf($this->results($teacher, $classUlid, $periodUlid));

        // The value is still there to read…
        $this->assertSame('3', $row['classification']['final']['code']);
        // …and nothing on the screen opens it.
        $this->assertFalse($row['classification']['can_change']);
        $this->assertFalse($row['classification']['can_confirm']);
        $this->assertTrue($row['classification']['is_published']);

        // Refused on the server too, not only hidden in the browser.
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertSessionHasErrors('final_value');

        $this->asTenant($teacher, function () use ($ulid, $three): void {
            $this->assertSame($three, Classification::where('ulid', $ulid)->firstOrFail()->final_scale_level_id);
        });
    }

    // -------------------------------------------------------- 5. the scale

    #[Test]
    public function an_interval_scale_is_offered_as_an_interval_and_not_as_bands(): void
    {
        $teacher = $this->seedDemo();

        $this->asTenant($teacher, function (): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            DB::table('assessment_profile_versions')
                ->where('id', $class->assessment_profile_version_id)
                ->update(['scale_id' => Scale::where('name', 'Escala 0 a 20')->firstOrFail()->id]);
        });

        [$classUlid, $periodUlid, $ulid] = $this->proposeAndReturnContext($teacher);

        $props = $this->pageOf($this->results($teacher, $classUlid, $periodUlid));

        $this->assertSame('Classificação atribuída', $props['decision']['label']);
        $this->assertFalse($props['decision']['classifies_by_level']);
        $this->assertSame([], $props['decision']['levels']);
        // Its own limits, taken from the scale.
        $this->assertSame('0.000', $props['decision']['min_value']);
        $this->assertSame('20.000', $props['decision']['max_value']);

        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_value' => '15'])
            ->assertSessionHasNoErrors();

        // …and a value the scale does not admit is refused, by the same service.
        $this->actingAs($teacher)
            ->post($this->decideUrl($ulid), ['final_value' => '25'])
            ->assertSessionHasErrors('final_value');
    }

    // ------------------------- 5b. um período sem propostas nenhumas

    /**
     * The second period of the demo class, where nothing has been proposed.
     *
     * @return array{string, string, string, string} class ulid, period ulid, enrolment ulid, student name
     */
    private function untouchedSecondPeriod(User $teacher): array
    {
        return $this->asTenant($teacher, function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $second = $class->academicYear->periods()->where('sequence', 2)->firstOrFail();

            // Nothing proposed here, on purpose: this is the state a teacher
            // finds a period in before anyone presses «Gerar propostas».
            $this->assertSame(0, Classification::where('academic_period_id', $second->id)->count());

            $enrollment = $class->enrollments()->orderBy('class_number')->firstOrFail();

            return [$class->ulid, $second->ulid, $enrollment->ulid, $enrollment->student->identity->display_name];
        });
    }

    #[Test]
    public function a_period_with_no_proposals_at_all_is_still_classifiable(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid, $name] = $this->untouchedSecondPeriod($teacher);
        $four = $this->levelId($teacher, '4');

        // The cell offers the editor: what closes it is publication, never the
        // absence of a proposal.
        $row = $this->rowOf($this->results($teacher, $classUlid, $periodUlid), $name);
        $this->assertNull($row['classification']);

        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide", ['final_scale_level_id' => $four])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($four, $periodUlid): void {
            $period = AcademicPeriod::where('ulid', $periodUlid)->firstOrFail();
            $classification = Classification::where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->firstOrFail();

            // Opened and decided in one act, on the canonical row…
            $this->assertSame(ClassificationStatus::Confirmed, $classification->status);
            $this->assertSame($four, $classification->final_scale_level_id);
            $this->assertSame('4.000', $classification->final_value);

            // …with the proposal columns still empty, because nothing was
            // proposed, and no snapshot, because there was no proposal to freeze.
            $this->assertNull($classification->proposed_value);
            $this->assertNull($classification->proposed_scale_level_id);
            $this->assertNull($classification->calculation_snapshot_id);
            // And not recorded as differing from a proposal that never existed.
            $this->assertFalse($classification->wasOverridden());
        });

        // It shows on both screens, in the same token.
        $this->assertSame('4', $this->rowOf($this->results($teacher, $classUlid, $periodUlid), $name)['classification']['final']['code']);
        $this->assertSame(
            '4',
            $this->rowOf($this->classifications($teacher, $classUlid, $periodUlid), $name)['classification']['decision']['code'],
        );
    }

    #[Test]
    public function deciding_without_a_proposal_needs_the_decision_stated(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid, $name] = $this->untouchedSecondPeriod($teacher);

        // «Usar proposta» has no proposal to use here, and inventing one would
        // be the system classifying (§3.3).
        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide", [])
            ->assertSessionHasErrors('final_value');
    }

    #[Test]
    public function proposing_later_finds_the_row_the_teacher_already_opened(): void
    {
        $teacher = $this->seedDemo();
        [$classUlid, $periodUlid, $enrollmentUlid, $name] = $this->untouchedSecondPeriod($teacher);
        $four = $this->levelId($teacher, '4');

        $this->actingAs($teacher)
            ->post("/classes/{$classUlid}/classifications/{$periodUlid}/{$enrollmentUlid}/decide", ['final_scale_level_id' => $four]);

        $this->asTenant($teacher, function () use ($periodUlid, $enrollmentUlid): void {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = AcademicPeriod::where('ulid', $periodUlid)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);

            // One live row for this student and period — the proposal step found
            // the one already opened instead of racing it (the one-live unique
            // index would have refused a second anyway).
            $enrollment = $class->enrollments()->where('ulid', $enrollmentUlid)->firstOrFail();
            $rows = Classification::where('academic_period_id', $period->id)
                ->where('enrollment_id', $enrollment->id)
                ->where('scope', ClassificationScope::Period)
                ->get();

            $this->assertCount(1, $rows);
            // …and the decision the teacher took is untouched by it.
            $this->assertSame(ClassificationStatus::Confirmed, $rows->first()->status);
        });
    }

    // ------------------------------------------------------ 6. quem pode

    #[Test]
    public function a_teacher_from_another_organization_cannot_decide_from_results(): void
    {
        $teacher = $this->seedDemo();
        [, , $ulid] = $this->proposeAndReturnContext($teacher);
        $four = $this->levelId($teacher, '4');

        $this->actingAs(User::factory()->create())
            ->post($this->decideUrl($ulid), ['final_scale_level_id' => $four])
            ->assertNotFound();
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
}
