<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\Organization;
use App\Models\RectificationReason;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\EvaluationSheetReadiness;
use App\Services\Assessment\ProposeClassifications;
use App\Services\Assessment\PublishClassifications;
use App\Services\Assessment\RectifyClassification;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Preparar fecho» notices a rectification made after the last INOVAR export:
 * the export is a photograph, and a rectified grade makes it stale.
 */
class EvaluationSheetReadinessRectificationTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private SchoolClass $class;

    private AcademicPeriod $period;

    private Classification $carolina;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ana = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $this->organization = $this->ana->personalOrganization();
        $this->carolina = $this->asTenant(function (): Classification {
            $this->class = SchoolClass::where('label', '7.º A')->firstOrFail();
            $this->period = $this->class->academicYear->periods()->where('sequence', 1)->firstOrFail();

            app(ProposeClassifications::class)->forPeriod($this->class, $this->period);
            $row = Classification::query()
                ->where('academic_period_id', $this->period->id)
                ->where('scope', ClassificationScope::Period)
                ->get()
                ->first(fn (Classification $c) => $c->enrollment->student->identity->display_name === 'Carolina Nunes');
            $level = $this->class->profileVersion->scale->levels()->where('code', '4')->firstOrFail();

            app(ConfirmClassification::class)->confirm($row, $this->ana, $level->id);
            app(PublishClassifications::class)->forPeriod($this->class, $this->period, ClassificationScope::Period);

            return $row->fresh();
        });
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor(Organization::query()->findOrFail($this->organization->id), $callback);
    }

    private function exportInovar(): void
    {
        $this->asTenant(function (): void {
            $payload = ['version' => 3, 'students' => [['enrollment_id' => $this->carolina->enrollment_id]]];
            EvaluationSheetExport::create([
                'class_id' => $this->class->id,
                'academic_period_id' => $this->period->id,
                'scope' => ClassificationScope::Period,
                'adapter' => 'inovar',
                'moment_label' => 'Semestre — 1.º Semestre',
                'effective_at' => '2026-12-15',
                'payload' => $payload,
                'payload_hash' => CanonicalPayload::hash($payload),
                'exported_with_warnings' => false,
                'warning_count' => 0,
                'file_disk' => 'local',
                'exported_by' => $this->ana->id,
                'exported_at' => now(),
            ]);
        });
    }

    private function rectify(): void
    {
        $level = $this->asTenant(fn () => $this->class->profileVersion->scale->levels()->where('code', '3')->firstOrFail());

        $this->asTenant(fn () => app(RectifyClassification::class)->rectify(
            $this->carolina, $this->ana, RectificationReason::EntryError, null, $level->id, null, $this->carolina->lock_version, false,
        ));
    }

    /** @return array<string, mixed> */
    private function inovarLine(): array
    {
        return $this->asTenant(function (): array {
            $sheet = app(BuildEvaluationSheet::class)->for($this->class, $this->period);
            $readiness = app(EvaluationSheetReadiness::class)->for($this->class, $this->period, $sheet, true);

            return collect($readiness['items'])->firstWhere('key', 'inovar');
        });
    }

    #[Test]
    public function a_rectification_after_the_export_asks_for_attention(): void
    {
        $this->exportInovar();
        $this->travel(1)->hours();
        $this->rectify();

        $line = $this->inovarLine();

        $this->assertSame(EvaluationSheetReadiness::STATE_ATTENTION, $line['state']);
        $this->assertSame('Há classificações retificadas depois desta exportação.', $line['detail']);
    }

    #[Test]
    public function a_rectification_before_the_export_says_nothing(): void
    {
        $this->rectify();
        $this->travel(1)->hours();
        $this->exportInovar();

        $line = $this->inovarLine();

        $this->assertSame(EvaluationSheetReadiness::STATE_OK, $line['state']);
        $this->assertNull($line['detail']);
    }

    #[Test]
    public function an_export_without_any_rectification_is_unchanged(): void
    {
        $this->exportInovar();

        $line = $this->inovarLine();

        $this->assertSame(EvaluationSheetReadiness::STATE_OK, $line['state']);
        $this->assertNull($line['detail']);
    }
}
