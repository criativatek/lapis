<?php

namespace Tests\Feature\Interventions;

use App\Models\Intervention;
use App\Models\InterventionStatus;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Support interventions (§14): a lifecycle (new → in progress → concluded) with
 * effectiveness reviews, tied to a student of the class, never in the calculation.
 */
class InterventionTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, int} class ulid, enrollment id */
    private function seedClass(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$class->ulid, $class->enrollments()->orderBy('class_number')->first()->id];
        });
    }

    private function create(string $classUlid, User $teacher, int $enrollmentId): void
    {
        $this->actingAs($teacher)->post("/classes/{$classUlid}/interventions", [
            'enrollment_id' => $enrollmentId,
            'title' => 'Apoio à leitura em pequeno grupo',
            'description' => 'Sessões semanais de leitura orientada.',
            'started_on' => '2026-10-01',
            'expected_end_on' => '2026-12-15',
            'include_in_report' => true,
        ])->assertRedirect();
    }

    #[Test]
    public function creating_an_intervention_starts_it_as_new(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->create($classUlid, $teacher, $enrollmentId);

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentId): void {
            $intervention = Intervention::firstOrFail();
            $this->assertSame(InterventionStatus::New, $intervention->status);
            $this->assertSame($enrollmentId, $intervention->enrollment_id);
            $this->assertTrue($intervention->include_in_report);
            $this->assertNull($intervention->concluded_on);
        });
    }

    #[Test]
    public function a_student_from_another_class_is_rejected(): void
    {
        [$classUlid] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->post("/classes/{$classUlid}/interventions", [
            'enrollment_id' => 999999,
            'title' => 'Inválida',
            'started_on' => '2026-10-01',
        ])->assertSessionHasErrors('enrollment_id');
    }

    #[Test]
    public function concluding_stamps_the_conclusion_date(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->create($classUlid, $teacher, $enrollmentId);

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Intervention::firstOrFail()->ulid);

        $this->actingAs($teacher)->patch("/interventions/{$ulid}", ['status' => 'concluded'])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $intervention = Intervention::firstOrFail();
            $this->assertSame(InterventionStatus::Concluded, $intervention->status);
            $this->assertNotNull($intervention->concluded_on);
        });
    }

    #[Test]
    public function a_review_records_the_effectiveness_appraisal(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->create($classUlid, $teacher, $enrollmentId);

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Intervention::firstOrFail()->ulid);

        $this->actingAs($teacher)->post("/interventions/{$ulid}/reviews", [
            'reviewed_on' => '2026-11-15',
            'effectiveness' => 'partially_effective',
            'notes' => 'Melhoria na fluência, ainda com dificuldades de interpretação.',
        ])->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $review = Intervention::firstOrFail()->reviews()->firstOrFail();
            $this->assertSame('partially_effective', $review->effectiveness->value);
            $this->assertSame('Melhoria na fluência, ainda com dificuldades de interpretação.', $review->notes);
        });
    }

    #[Test]
    public function removing_an_intervention_soft_deletes_it(): void
    {
        [$classUlid, $enrollmentId] = $this->seedClass();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();
        $this->create($classUlid, $teacher, $enrollmentId);

        $ulid = app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), fn () => Intervention::firstOrFail()->ulid);
        $this->actingAs($teacher)->delete("/interventions/{$ulid}")->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function (): void {
            $this->assertSame(0, Intervention::count());
            $this->assertSame(1, Intervention::withTrashed()->count());
        });
    }

    #[Test]
    public function another_organization_cannot_open_the_interventions(): void
    {
        [$classUlid] = $this->seedClass();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/interventions")->assertNotFound();
    }
}
