<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\DecisionResolver;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Assessment\ClassificationDecisionException;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The validation of a decision, called directly: the same rules confirming,
 * changing and rectifying a classification all go through.
 */
class DecisionResolverTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{User, Classification} */
    private function proposedClassification(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        $classification = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();
            app(ProposeClassifications::class)->forPeriod($class, $period);

            return Classification::query()
                ->where('academic_period_id', $period->id)
                ->where('scope', ClassificationScope::Period)
                ->get()
                ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');
        });

        return [$teacher, $classification];
    }

    #[Test]
    public function a_level_of_the_classes_scale_resolves_to_that_level(): void
    {
        [$teacher, $classification] = $this->proposedClassification();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classification): void {
            $level = $classification->enrollment->schoolClass->profileVersion->scale->levels()
                ->where('id', '!=', $classification->proposed_scale_level_id)->firstOrFail();

            $decision = app(DecisionResolver::class)->resolve($classification, $level->id, null);

            $this->assertSame($level->id, $decision['scale_level_id']);
            $this->assertTrue($decision['is_override']);
            $this->assertSame("{$level->code} — {$level->label}", $decision['readable']);
        });
    }

    #[Test]
    public function a_level_of_another_scale_is_refused(): void
    {
        [$teacher, $classification] = $this->proposedClassification();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classification): void {
            $foreign = Scale::factory()->create()->levels()
                ->create(['code' => 'X', 'label' => 'De outra escala', 'sequence' => 1]);

            $this->expectException(ClassificationDecisionException::class);
            app(DecisionResolver::class)->resolve($classification, $foreign->id, null);
        });
    }

    #[Test]
    public function a_value_outside_an_interval_scale_is_refused(): void
    {
        [$teacher, $classification] = $this->proposedClassification();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classification): void {
            $class = $classification->enrollment->schoolClass;
            $numeric = Scale::where('name', 'Escala 0 a 20')->firstOrFail();
            DB::table('assessment_profile_versions')
                ->where('id', $class->assessment_profile_version_id)
                ->update(['scale_id' => $numeric->id]);

            $classification = Classification::findOrFail($classification->id);
            $resolver = app(DecisionResolver::class);

            $this->assertSame('15', $resolver->resolve($classification, null, '15')['value']);

            $this->expectException(ClassificationDecisionException::class);
            $resolver->resolve($classification, null, '25');
        });
    }
}
