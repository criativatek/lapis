<?php

namespace Tests\Feature\DataExports;

use App\Console\Commands\PruneDataExports;
use App\Models\DataExport;
use App\Models\Enrollment;
use App\Models\EvidenceKind;
use App\Models\EvidenceRecord;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\SchoolClass;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class DataExportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{Organization, User} */
    private function institutionalOrganization(): array
    {
        $owner = User::factory()->withoutOrganization()->create();
        $organization = Organization::factory()->institutional()->create(['owner_id' => $owner->id]);
        $organization->members()->attach($owner, ['joined_at' => now()]);

        OrganizationSubscription::withoutGlobalScope('organization')->create([
            'organization_id' => $organization->id,
            'plan_id' => Plan::where('key', 'institutional')->firstOrFail()->id,
            'status' => SubscriptionStatus::Active,
            'starts_at' => Carbon::now(),
        ]);

        return [$organization, $owner];
    }

    private function member(Organization $organization): User
    {
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);

        return $member;
    }

    private function classWithEvidence(Organization $organization, User $teacher): SchoolClass
    {
        return app(CurrentOrganization::class)->runFor($organization, function () use ($organization, $teacher): SchoolClass {
            $class = SchoolClass::factory()->recycle($organization)->create();
            $class->teachers()->attach($teacher, ['role' => 'owner']);
            $enrollment = Enrollment::factory()->recycle($organization)->create(['class_id' => $class->id]);
            EvidenceRecord::create([
                'class_id' => $class->id,
                'enrollment_id' => $enrollment->id,
                'occurred_at' => now(),
                'kind' => EvidenceKind::Note,
                'description' => 'Registo de teste.',
                'created_by' => $teacher->id,
            ]);

            return $class;
        });
    }

    private function extractZip(string $absolutePath): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($absolutePath) === true, 'O ficheiro gerado deve ser um ZIP válido.');

        return $zip;
    }

    /**
     * Pulls the XLSX bytes out of the zip, writes them to a real temp file
     * (PhpSpreadsheet's reader needs a path, not a string), and loads it —
     * proving the workbook actually opens, not just that bytes exist.
     */
    private function openWorkbook(ZipArchive $zip): Spreadsheet
    {
        $bytes = $zip->getFromName('Exportacao-LAPIS.xlsx');
        $this->assertNotFalse($bytes, 'Exportacao-LAPIS.xlsx tem de existir no ZIP.');

        $tempPath = tempnam(sys_get_temp_dir(), 'lapis_export_test_');
        file_put_contents($tempPath, $bytes);

        try {
            return IOFactory::createReader('Xlsx')->load($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    #[Test]
    public function a_personal_organization_owner_can_export_their_own_data(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $personal = $user->personalOrganization();

        $response = $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->post('/data-exports');

        $response->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->firstOrFail();
        $this->assertTrue($export->isReady());
        $this->assertSame($user->id, $export->requested_by);
    }

    #[Test]
    public function the_zip_contains_exactly_the_expected_top_level_files(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));

        $this->assertNotFalse($zip->locateName('Exportacao-LAPIS.xlsx'));
        $this->assertNotFalse($zip->locateName('backup-lapis.json'));
        $this->assertNotFalse($zip->locateName('README.txt'));
    }

    #[Test]
    public function the_workbook_opens_and_always_has_a_resumo_sheet(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $personal = $user->personalOrganization();

        $this->actingAs($user)->withSession(['organization_id' => $personal->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));

        $spreadsheet = $this->openWorkbook($zip);

        $this->assertTrue($spreadsheet->sheetNameExists('Resumo'));

        $resumo = $spreadsheet->getSheetByName('Resumo');
        $labels = [];

        for ($row = 2; $row <= $resumo->getHighestRow(); $row++) {
            $labels[] = $resumo->getCell("A{$row}")->getValue();
        }

        $this->assertContains('Utilizador', $labels);
        $this->assertContains('Organização', $labels);
        $this->assertContains('Versão do LÁPIS', $labels);
        $this->assertContains('Nº de turmas', $labels);
    }

    #[Test]
    public function an_institutional_member_exports_only_their_own_classes_with_human_readable_names(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        $ownClass = $this->classWithEvidence($organization, $member);
        $ownersClass = $this->classWithEvidence($organization, $owner);

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();

        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $member->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));
        $spreadsheet = $this->openWorkbook($zip);

        $turmas = $spreadsheet->getSheetByName('Turmas');
        $this->assertNotNull($turmas);

        $headerRow = [];
        foreach (range('A', 'F') as $column) {
            $headerRow[] = $turmas->getCell("{$column}1")->getValue();
        }
        $this->assertSame(['Ano letivo', 'Disciplina', 'Ano', 'Turma', 'Estado', 'Professor(es)'], $headerRow);

        $labels = [];
        for ($row = 2; $row <= $turmas->getHighestRow(); $row++) {
            $labels[] = $turmas->getCell("D{$row}")->getValue();
        }

        $this->assertContains($ownClass->label, $labels);
        $this->assertNotContains($ownersClass->label, $labels);

        // A member never gets the owner-only governance extras.
        $this->assertSame(false, $zip->locateName('configuracao/equipa.csv'));
        $this->assertSame(false, $zip->locateName('configuracao/auditoria.csv'));
    }

    #[Test]
    public function an_owner_export_includes_team_and_audit_but_no_colleagues_pedagogical_data(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);
        $membersClass = $this->classWithEvidence($organization, $member);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();

        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));

        $this->assertNotFalse($zip->locateName('configuracao/equipa.csv'));
        $this->assertNotFalse($zip->locateName('configuracao/auditoria.csv'));

        // The owner does not teach the member's class — it must not appear,
        // and there is no "Turmas" sheet at all since the owner teaches none.
        $spreadsheet = $this->openWorkbook($zip);
        $this->assertFalse($spreadsheet->sheetNameExists('Turmas'));
        $this->assertFalse($spreadsheet->sheetNameExists('Alunos'));

        $backup = json_decode((string) $zip->getFromName('backup-lapis.json'), true);
        $classLabels = array_column($backup['classes'], 'label');
        $this->assertNotContains($membersClass->label, $classLabels);
    }

    #[Test]
    public function technical_ids_are_not_the_primary_columns_in_the_workbook(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));
        $spreadsheet = $this->openWorkbook($zip);

        $forbiddenHeaders = ['organization_id', 'user_id', 'student_id', 'class_id', 'academic_year_id', 'subject_id', 'instrument_id'];

        foreach ($spreadsheet->getSheetNames() as $sheetName) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            $highestColumn = $sheet->getHighestColumn();

            foreach ($sheet->rangeToArray("A1:{$highestColumn}1")[0] as $header) {
                foreach ($forbiddenHeaders as $forbidden) {
                    $this->assertNotSame($forbidden, $header, "«{$forbidden}» não pode ser um cabeçalho de coluna em «{$sheetName}».");
                }
            }
        }
    }

    #[Test]
    public function dates_and_numbers_keep_their_native_excel_type(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));
        $spreadsheet = $this->openWorkbook($zip);

        $registos = $spreadsheet->getSheetByName('Registos');
        $this->assertNotNull($registos);
        $this->assertTrue(is_numeric($registos->getCell('D2')->getValue()), 'A data em «Registos» tem de ser um valor numérico Excel, não uma string.');
        $this->assertTrue($registos->getStyle('D2')->getNumberFormat()->getFormatCode() !== 'General');
    }

    #[Test]
    public function a_download_is_denied_to_anyone_other_than_the_requester(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $member = $this->member($organization);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->get("/data-exports/{$export->ulid}")->assertForbidden();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get("/data-exports/{$export->ulid}")->assertOk();
    }

    #[Test]
    public function a_cross_tenant_user_cannot_reach_another_organizations_export(): void
    {
        Storage::fake('local');
        [$organizationA, $ownerA] = $this->institutionalOrganization();
        [$organizationB, $ownerB] = $this->institutionalOrganization();

        $this->actingAs($ownerA)->withSession(['organization_id' => $organizationA->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $ownerA->id)->firstOrFail();

        $this->actingAs($ownerB)->withSession(['organization_id' => $organizationB->id])
            ->get("/data-exports/{$export->ulid}")->assertNotFound();
    }

    #[Test]
    public function an_expired_export_can_no_longer_be_downloaded(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $export->forceFill(['expires_at' => now()->subMinute()])->save();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->get("/data-exports/{$export->ulid}")->assertForbidden();
    }

    #[Test]
    public function the_export_never_contains_secrets_and_has_a_valid_backup_json(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));

        $backupRaw = $zip->getFromName('backup-lapis.json');
        $this->assertNotFalse($backupRaw);
        $backup = json_decode($backupRaw, true);
        $this->assertIsArray($backup);
        $this->assertArrayHasKey('schema_version', $backup);
        $this->assertArrayHasKey('app_version', $backup);
        $this->assertSame($organization->ulid, $backup['organization']['ulid']);

        // README.txt is explanatory copy that legitimately NAMES what is
        // excluded ("Não inclui... password...") — scanning it for these
        // words would flag the exact sentence that promises they're absent.
        // Exportacao-LAPIS.xlsx is a binary format; a raw substring scan on
        // it is still meaningful (these words would never legitimately
        // appear in the compressed XML either) and costs nothing extra.
        $forbidden = ['password', 'remember_token', 'two_factor', 'token_hash', 'recovery_code'];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if ($name === 'README.txt') {
                continue;
            }

            $contents = (string) $zip->getFromName($name);

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $contents, "«{$needle}» não pode aparecer em {$name}");
            }
        }
    }

    /**
     * Fatia 6: enrolled_on is the one field a restore cannot safely invent
     * (NOT NULL, drives late-entry handling, §11.4) — schema_version bumped
     * to 3 specifically to carry it. Regression against reopening that gap.
     * Fatia 6.1 bumped schema_version again, to 4, for the full pedagogical
     * restore (docs/backup-schema.md), and Fatia 6.2 to 5, for restorable
     * academic years/subjects — enrollments themselves are untouched by
     * either bump, so this test's assertions stay the same shape, just
     * against the new current version.
     */
    #[Test]
    public function the_backup_json_carries_enrollment_dates_for_restore(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));
        $backup = json_decode((string) $zip->getFromName('backup-lapis.json'), true);

        $this->assertSame(5, $backup['schema_version']);
        $this->assertNotEmpty($backup['enrollments']);
        foreach ($backup['enrollments'] as $enrollment) {
            $this->assertArrayHasKey('enrolled_on', $enrollment);
            $this->assertArrayHasKey('left_on', $enrollment);
            $this->assertArrayHasKey('class_number', $enrollment);
            $this->assertNotNull($enrollment['enrolled_on']);
        }
    }

    #[Test]
    public function the_exported_zip_is_never_web_reachable(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();

        // The private disk root is storage/app/private — never public/.
        $this->assertStringContainsString('data-exports', $export->disk_path);
        $this->assertFalse(str_starts_with($export->disk_path, 'public/'));
    }

    #[Test]
    public function the_prune_command_removes_expired_exports_and_clears_the_row(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $export->forceFill(['expires_at' => now()->subDay()])->save();
        touch(Storage::disk('local')->path($export->disk_path), now()->subDay()->getTimestamp());

        $this->artisan(PruneDataExports::class)->assertSuccessful();

        $this->assertNull($export->fresh()->disk_path);
    }

    /**
     * Regression: this command runs from the scheduler (routes/console.php,
     * hourly), where NO organization is ever resolved — CurrentOrganization
     * throws for any tenant-scoped query that does not explicitly say
     * withoutGlobalScope('organization'). The test above calls
     * $this->artisan() right after a real HTTP request, which leaves a
     * tenant resolved in the container and would pass even if this command
     * were broken for the one context that actually matters. This one
     * forces app(CurrentOrganization::class)->forget() first, so it fails
     * exactly the way the real scheduler would if the fix regressed.
     */
    #[Test]
    public function the_prune_command_runs_with_no_tenant_resolved_at_all(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $export->forceFill(['expires_at' => now()->subDay()])->save();
        touch(Storage::disk('local')->path($export->disk_path), now()->subDay()->getTimestamp());

        app(CurrentOrganization::class)->forget();
        $this->assertFalse(app(CurrentOrganization::class)->isResolved());

        $this->artisan(PruneDataExports::class)->assertSuccessful();

        $this->assertNull($export->fresh()->disk_path);
    }
}
