<?php

namespace Tests\Feature\Assessment;

use App\Models\Classification;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The classification sheet (pauta): only decided grades appear, and the CSV export
 * carries the same figures. A report never shows a proposal as a grade.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{string, string} class ulid, org id resolved in tenant */
    private function seedWithOneConfirmedGrade(): array
    {
        $teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);

        return app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($teacher) {
            $class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $period = $class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($class, $period);
            $carolina = Classification::query()
                ->where('academic_period_id', $period->id)
                ->get()
                ->first(fn ($classification) => $classification->enrollment->student->identity->display_name === 'Carolina Nunes');
            app(ConfirmClassification::class)->confirm($carolina, $teacher);

            return [$class->ulid, (string) $teacher->id];
        });
    }

    #[Test]
    public function the_pauta_shows_only_decided_grades(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)->get("/classes/{$classUlid}/report")->assertInertia(
            fn ($page) => $page
                ->component('reports/Pauta')
                ->where('rows', function ($rows) {
                    $rows = collect($rows);
                    // Carolina is confirmed → her first-period cell carries a value.
                    $carolina = $rows->firstWhere('name', 'Carolina Nunes');
                    $this->assertSame('91.000', $carolina['cells'][0]['value']);
                    $this->assertSame('confirmed', $carolina['cells'][0]['status']);
                    // Diogo has no decided grade → no value, never a zero.
                    $diogo = $rows->firstWhere('name', 'Diogo Ferreira');
                    $this->assertNull($diogo['cells'][0]['value']);

                    return true;
                }),
        );
    }

    #[Test]
    public function the_csv_export_carries_the_decided_grades(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $response = $this->actingAs($teacher)->get("/classes/{$classUlid}/report/export");

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $body = $response->getContent();
        $this->assertStringContainsString('Carolina Nunes', $body);
        $this->assertStringContainsString('91.000', $body);
        $this->assertStringContainsString('Nº,Aluno', $body);
    }

    #[Test]
    public function a_report_from_another_organization_is_not_found(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();

        $stranger = User::factory()->create();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/report")->assertNotFound();
        $this->actingAs($stranger)->get("/classes/{$classUlid}/report/export")->assertNotFound();
    }

    #[Test]
    public function the_teacher_toggles_the_class_wide_evidence_default(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/evidence-setting", ['include_evidence_in_report' => true])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($classUlid): void {
            $this->assertTrue(SchoolClass::where('ulid', $classUlid)->firstOrFail()->include_evidence_in_report);
        });
    }

    #[Test]
    public function a_student_override_replaces_the_class_default_and_can_be_cleared(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $teacher = User::where('email', 'ana.martins@lapis.test')->firstOrFail();

        [$enrollmentId, $enrollmentUlid] = app(CurrentOrganization::class)->runFor(
            $teacher->personalOrganization(),
            function () use ($classUlid) {
                $enrollment = SchoolClass::where('ulid', $classUlid)->firstOrFail()->enrollments()->firstOrFail();

                return [$enrollment->id, $enrollment->ulid];
            },
        );

        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/students/{$enrollmentUlid}/evidence-setting", ['include_evidence_in_report' => false])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentId): void {
            $enrollment = Enrollment::withoutGlobalScope('organization')->findOrFail($enrollmentId);
            $this->assertFalse($enrollment->include_evidence_in_report);
            $this->assertFalse($enrollment->includesEvidenceInReport());
        });

        // Clearing the override (null) falls back to the class default.
        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/evidence-setting", ['include_evidence_in_report' => true]);
        $this->actingAs($teacher)
            ->put("/classes/{$classUlid}/report/students/{$enrollmentUlid}/evidence-setting", ['include_evidence_in_report' => null])
            ->assertRedirect();

        app(CurrentOrganization::class)->runFor($teacher->personalOrganization(), function () use ($enrollmentId): void {
            $enrollment = Enrollment::withoutGlobalScope('organization')->findOrFail($enrollmentId);
            $this->assertNull($enrollment->include_evidence_in_report);
            $this->assertTrue($enrollment->includesEvidenceInReport());
        });
    }

    #[Test]
    public function a_stranger_cannot_change_another_organizations_evidence_settings(): void
    {
        [$classUlid] = $this->seedWithOneConfirmedGrade();
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put("/classes/{$classUlid}/report/evidence-setting", ['include_evidence_in_report' => true])
            ->assertNotFound();
    }
}
