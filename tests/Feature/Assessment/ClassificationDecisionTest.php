<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
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
        $this->actingAs($teacher)->post("/classifications/{$ulid}/confirm", [])->assertSessionHasNoErrors();

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
            ->post("/classifications/{$ulid}/confirm", ['final_scale_level_id' => $otherLevelId])
            ->assertSessionHasNoErrors();

        $this->asTenant($teacher, function () use ($ulid, $otherLevelId): void {
            $carolina = Classification::where('ulid', $ulid)->firstOrFail();

            $this->assertSame($otherLevelId, $carolina->final_scale_level_id);
            $this->assertNull($carolina->override_reason);
            $this->assertTrue($carolina->wasOverridden());
        });
    }

    // --------------------------------------- 3. the two screens must agree

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
}
