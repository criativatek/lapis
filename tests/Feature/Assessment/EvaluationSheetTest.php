<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\BuildEvaluationSheet;
use App\Services\Assessment\ConfirmClassification;
use App\Services\Assessment\ProposeClassifications;
use App\Support\Hashing\CanonicalPayload;
use App\Support\Tenancy\CurrentOrganization;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EvaluationSheetTest extends TestCase
{
    use RefreshDatabase;

    protected User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teacher = User::factory()->create(['email' => 'ana.martins@lapis.test']);
        $this->seed(DemoDataSeeder::class);
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    private function asTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->teacher->personalOrganization(), $callback);
    }

    /** @return array{SchoolClass, AcademicPeriod} */
    private function context(): array
    {
        return $this->asTenant(function (): array {
            $schoolClass = SchoolClass::where('label', '7.º A')->firstOrFail();

            return [$schoolClass, $schoolClass->academicYear->periods()->where('sequence', 1)->firstOrFail()];
        });
    }

    #[Test]
    public function its_migration_runs_on_sqlite_and_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('evaluation_sheet_exports'));

        $migration = require base_path('database/migrations/2026_10_01_000100_create_evaluation_sheet_exports_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('evaluation_sheet_exports'));

        $migration->up();
        $this->assertTrue(Schema::hasColumns('evaluation_sheet_exports', [
            'ulid', 'organization_id', 'class_id', 'academic_period_id', 'scope',
            'interim_assessment_id', 'payload', 'payload_hash', 'file_checksum', 'exported_at',
        ]));
    }

    #[Test]
    public function an_export_is_immutable_and_detects_payload_tampering(): void
    {
        $export = $this->export();

        $this->assertTrue($export->isIntact());
        $export->payload = ['version' => 1, 'students' => ['changed']];
        $this->assertFalse($export->isIntact());

        $this->expectException(LogicException::class);
        $export->update(['moment_label' => 'Alterado']);
    }

    #[Test]
    public function exports_are_isolated_by_the_current_organization(): void
    {
        $export = $this->export();
        $stranger = User::factory()->create();

        $visible = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): int => EvaluationSheetExport::query()->whereKey($export->id)->count(),
        );

        $this->assertSame(0, $visible);
    }

    #[Test]
    public function it_builds_ordered_results_labels_decisions_and_coverage(): void
    {
        [$schoolClass, $academicPeriod] = $this->context();

        $sheet = $this->asTenant(function () use ($schoolClass, $academicPeriod): array {
            app(ProposeClassifications::class)->forPeriod($schoolClass, $academicPeriod);
            $carolina = Classification::query()
                ->where('academic_period_id', $academicPeriod->id)
                ->get()
                ->first(fn (Classification $classification): bool => $classification->enrollment->student->identity->display_name === 'Carolina Nunes');
            $levelFour = $schoolClass->profileVersion->scale->levels()->where('code', '4')->firstOrFail();
            app(ConfirmClassification::class)->confirm($carolina, $this->teacher, $levelFour->id);

            return app(BuildEvaluationSheet::class)->for($schoolClass, $academicPeriod);
        });

        $this->assertSame('period', $sheet['scope']);
        $this->assertSame(
            ['Oralidade', 'Leitura', 'Escrita', 'Gramática', 'Educação Literária'],
            array_column($sheet['domains'], 'name'),
        );

        $carolina = collect($sheet['students'])->firstWhere('name', 'Carolina Nunes');
        $this->assertSame(3, $carolina['class_number']);
        $this->assertSame('5.000', $carolina['overall']['scale_value']);
        $this->assertSame('Muito Bom', $carolina['overall']['scale_level_label']);
        // O código do nível viaja a par da menção — é o código que a pauta
        // mostra, e a menção segue para o `title` da célula (SUP-2C774B).
        $this->assertSame('5', $carolina['overall']['scale_level_code']);
        $this->assertSame('confirmed', $carolina['classification']['status']);
        $this->assertSame('91.000', $carolina['classification']['proposed_value']);
        $this->assertSame('Muito Bom', $carolina['classification']['proposed_scale_level_label']);
        $this->assertSame('5', $carolina['classification']['proposed_scale_level_code']);
        $this->assertSame('4.000', $carolina['classification']['final_value']);
        $this->assertSame('Bom', $carolina['classification']['final_scale_level_label']);
        $this->assertSame('4', $carolina['classification']['final_scale_level_code']);

        $reading = collect((array) $carolina['domains'])->firstWhere('name', 'Leitura');
        $this->assertSame('93.125000', $reading['normalized_value']);
        $this->assertSame('25.0000', $reading['weight_percent_applied']);
        $this->assertSame('Muito Bom', $reading['scale_level_label']);
        $this->assertSame('5', $reading['scale_level_code']);

        $diogo = collect($sheet['students'])->firstWhere('name', 'Diogo Ferreira');
        $this->assertNull($diogo['classification']);
        $this->assertTrue($diogo['overall']['has_coverage_warning']);
        $this->assertNotEmpty($diogo['coverage']['absences']);
    }

    #[Test]
    public function accumulated_scope_is_explicit_and_another_tenant_gets_no_students(): void
    {
        [$schoolClass, $academicPeriod] = $this->context();

        $accumulated = $this->asTenant(fn (): array => app(BuildEvaluationSheet::class)->for(
            $schoolClass,
            $academicPeriod,
            ClassificationScope::Accumulated,
        ));
        $this->assertSame('accumulated', $accumulated['scope']);

        $stranger = User::factory()->create();
        $foreignReading = app(CurrentOrganization::class)->runFor(
            $stranger->personalOrganization(),
            fn (): array => app(BuildEvaluationSheet::class)->for($schoolClass, $academicPeriod),
        );
        $this->assertSame([], $foreignReading['students']);
    }

    private function export(): EvaluationSheetExport
    {
        [$schoolClass, $academicPeriod] = $this->context();
        $payload = ['version' => 1, 'students' => []];

        return $this->asTenant(fn (): EvaluationSheetExport => EvaluationSheetExport::create([
            'class_id' => $schoolClass->id,
            'academic_period_id' => $academicPeriod->id,
            'scope' => ClassificationScope::Period,
            'moment_label' => 'Final do 1.º Semestre',
            'payload' => $payload,
            'payload_hash' => CanonicalPayload::hash($payload),
            'file_path' => 'evaluation-sheets/export.xlsx',
            'file_checksum' => hash('sha256', 'fixture'),
            'original_extension' => 'xlsx',
            'exported_by' => $this->teacher->id,
            'exported_at' => now(),
        ]));
    }
}
