<?php

namespace Tests\Feature\DataImports;

use App\Actions\ConfigSharing\GenerateConfigurationPackage;
use App\Actions\ConfigSharing\ValidateConfigurationPackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\DataExport;
use App\Models\DataImport;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\DeriveCustomScale;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SubscribesOrganizations;
use Tests\TestCase;
use ZipArchive;

/**
 * Uma escala de 4 e uma de 7 níveis, com os códigos do Inovar da escola, viajam
 * intactas na partilha de configuração e no backup: nada na viagem supõe cinco
 * níveis nem os códigos F, I, S, B, MB.
 */
class CustomScalePortabilityTest extends TestCase
{
    use RefreshDatabase;
    use SubscribesOrganizations;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->teacher = User::factory()->create();
    }

    private function inOrganization(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($organization, $callback);
    }

    /**
     * @return array<string, list<array{label: string, from_percent: int, inovar_code: string|null}>>
     */
    private function definitions(): array
    {
        return [
            'Quatro níveis' => [
                ['label' => 'Não satisfaz', 'from_percent' => 0, 'inovar_code' => 'NS'],
                ['label' => 'Satisfaz', 'from_percent' => 50, 'inovar_code' => 'S'],
                ['label' => 'Satisfaz bem', 'from_percent' => 70, 'inovar_code' => 'SB'],
                ['label' => 'Satisfaz muito bem', 'from_percent' => 90, 'inovar_code' => 'SMB'],
            ],
            'Sete níveis' => [
                ['label' => 'A', 'from_percent' => 0, 'inovar_code' => '1'],
                ['label' => 'B', 'from_percent' => 10, 'inovar_code' => '2'],
                ['label' => 'C', 'from_percent' => 25, 'inovar_code' => '2'],
                ['label' => 'D', 'from_percent' => 50, 'inovar_code' => '3'],
                ['label' => 'E', 'from_percent' => 60, 'inovar_code' => null],
                ['label' => 'F', 'from_percent' => 80, 'inovar_code' => '4'],
                ['label' => 'G', 'from_percent' => 99, 'inovar_code' => '5'],
            ],
        ];
    }

    /**
     * @return list<Scale> as escalas derivadas, cada uma com o seu perfil
     */
    private function scenario(): array
    {
        $organization = $this->teacher->personalOrganization();

        return $this->inOrganization($organization, function () use ($organization): array {
            $year = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2025/2026', 'starts_on' => '2025-09-01', 'ends_on' => '2026-08-31',
            ]);
            $system = Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5')->firstOrFail();
            $scales = [];
            $number = 0;

            foreach ($this->definitions() as $name => $levels) {
                $number++;
                $scale = app(DeriveCustomScale::class)->for($system, $levels, 2, $name, $name);
                $subject = Subject::factory()->recycle($organization)->create(['name' => "Disciplina {$number}", 'code' => "D{$number}"]);
                $profile = AssessmentProfile::factory()->recycle($organization)->create([
                    'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'name' => "Perfil {$number}",
                ]);
                $version = AssessmentProfileVersion::factory()->recycle($organization)->create([
                    'assessment_profile_id' => $profile->id, 'scale_id' => $scale->id, 'domain_weight_mode' => 'must_total_100',
                ]);
                $class = SchoolClass::factory()->recycle($organization)->create([
                    'academic_year_id' => $year->id, 'subject_id' => $subject->id, 'label' => "Turma {$number}",
                    'assessment_profile_version_id' => $version->id,
                ]);
                $class->teachers()->attach($this->teacher, ['role' => 'owner']);
                $scales[] = $scale;
            }

            return $scales;
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function levelsOf(Scale $scale): array
    {
        return $scale->levels()->orderBy('sequence')->get()
            ->map(fn ($level): array => [
                'code' => $level->code,
                'label' => $level->label,
                'inovar_code' => $level->inovar_code,
                'sequence' => $level->sequence,
                'numeric_value' => (string) $level->numeric_value,
                'band_min_normalized' => (string) $level->band_min_normalized,
                'band_max_normalized' => (string) $level->band_max_normalized,
                'is_negative' => $level->is_negative,
            ])->all();
    }

    #[Test]
    public function a_four_and_a_seven_level_scale_survive_the_configuration_package(): void
    {
        $scales = $this->scenario();
        $original = array_map(fn (Scale $scale): array => $this->levelsOf($scale), $scales);

        $package = $this->inOrganization($this->teacher->personalOrganization(), fn () => app(GenerateConfigurationPackage::class)
            ->handle(['scales' => array_map(fn (Scale $scale): string => $scale->ulid, $scales)]));
        $json = json_encode($package, JSON_THROW_ON_ERROR);

        $colleague = User::factory()->create();
        $this->inOrganization($colleague->personalOrganization(), function () use ($json, $scales, $original): void {
            $validated = app(ValidateConfigurationPackage::class)->fromJson($json);
            app(WriteConfigurationImport::class)->handle($validated, ['scale']);

            foreach ($scales as $index => $scale) {
                $copy = Scale::query()->where('name', $scale->name)->firstOrFail();
                $this->assertSame($original[$index], $this->levelsOf($copy));
                $this->assertSame(count($original[$index]), $copy->levels()->count());
            }
        });
    }

    #[Test]
    public function the_configuration_package_refuses_a_code_longer_than_the_column(): void
    {
        $scales = $this->scenario();
        $package = $this->inOrganization($this->teacher->personalOrganization(), fn () => app(GenerateConfigurationPackage::class)
            ->handle(['scales' => [$scales[0]->ulid]]));
        $package['payload']['scales'][0]['levels'][0]['inovar_code'] = 'ABCDEFGHI';

        $this->expectException(ValidationException::class);
        app(ValidateConfigurationPackage::class)->fromJson(json_encode($package, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function a_four_and_a_seven_level_scale_survive_the_backup(): void
    {
        $scales = $this->scenario();
        $original = array_map(fn (Scale $scale): array => $this->levelsOf($scale), $scales);

        $backup = $this->backupUpload();
        $json = $this->backupJson($backup);
        $exported = collect($json['scales'])->whereIn('name', ['Quatro níveis', 'Sete níveis']);
        $this->assertSame([4, 7], $exported->map(fn (array $scale): int => count($scale['levels']))->sort()->values()->all());

        $colleague = User::factory()->create();
        $destination = $colleague->personalOrganization();
        $this->subscribeOrganizationTo($destination, 'pro');
        $this->actingAs($colleague)->withSession(['organization_id' => $destination->id])
            ->post('/data-imports', ['file' => $backup])->assertSessionHasNoErrors();
        $import = DataImport::withoutGlobalScope('organization')->where('organization_id', $destination->id)->latest('id')->firstOrFail();
        $this->actingAs($colleague)->withSession(['organization_id' => $destination->id])
            ->post("/data-imports/{$import->ulid}/confirm")->assertRedirect()->assertSessionHasNoErrors();

        $this->inOrganization($destination, function () use ($scales, $original): void {
            foreach ($scales as $index => $scale) {
                $copy = Scale::query()->where('organization_id', $this->destinationId())->where('name', $scale->name)->firstOrFail();
                $this->assertSame($original[$index], $this->levelsOf($copy));
            }
        });
    }

    private function destinationId(): int
    {
        return app(CurrentOrganization::class)->id();
    }

    /** @return array<string, mixed> */
    private function backupJson(UploadedFile $file): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()));
        $json = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $zip->close();

        return $json;
    }

    private function backupUpload(): UploadedFile
    {
        $organization = $this->teacher->personalOrganization();
        $this->actingAs($this->teacher)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $this->teacher->id)->latest('id')->firstOrFail();

        return new UploadedFile(Storage::disk('local')->path($export->disk_path), 'backup.zip', 'application/zip', null, true);
    }
}
