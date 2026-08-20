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
    public function an_institutional_member_exports_only_their_own_classes(): void
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

        $classesCsv = $zip->getFromName('dados/turmas.csv');
        $this->assertStringContainsString($ownClass->label, $classesCsv);
        $this->assertStringNotContainsString($ownersClass->label, $classesCsv);

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

        // The owner does not teach the member's class — it must not appear.
        $classesCsv = $zip->getFromName('dados/turmas.csv');
        $this->assertStringNotContainsString($membersClass->label, $classesCsv);
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
    public function the_export_never_contains_secrets_and_has_a_valid_manifest_and_csv(): void
    {
        Storage::fake('local');
        [$organization, $owner] = $this->institutionalOrganization();
        $this->classWithEvidence($organization, $owner);

        $this->actingAs($owner)->withSession(['organization_id' => $organization->id])
            ->post('/data-exports')->assertRedirect();
        $export = DataExport::withoutGlobalScope('organization')->where('requested_by', $owner->id)->firstOrFail();
        $zip = $this->extractZip(Storage::disk('local')->path($export->disk_path));

        $manifestRaw = $zip->getFromName('manifest.json');
        $this->assertNotFalse($manifestRaw);
        $manifest = json_decode($manifestRaw, true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('schema_version', $manifest);
        $this->assertArrayHasKey('app_version', $manifest);
        $this->assertSame($organization->ulid, $manifest['organization']['ulid']);

        $classesCsv = $zip->getFromName('dados/turmas.csv');
        $this->assertNotFalse($classesCsv);
        $this->assertStringContainsString('ulid,label,disciplina,ano_letivo,estado', str_replace('"', '', explode("\n", (string) $classesCsv)[0]));

        // README.txt is explanatory copy that legitimately NAMES what is
        // excluded ("Não inclui... password...") — scanning it for these
        // words would flag the exact sentence that promises they're absent.
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
}
