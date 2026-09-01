<?php

declare(strict_types=1);

namespace Tests\Feature\ConfigSharing;

use App\Actions\ConfigSharing\BuildConfigurationImportPlan;
use App\Actions\ConfigSharing\GenerateConfigurationPackage;
use App\Actions\ConfigSharing\ValidateConfigurationPackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\Plan;
use App\Models\ProfileVersionStatus;
use App\Models\Subject;
use App\Models\SubscriptionStatus;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConfigurationSharingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function base_is_denied_and_pro_and_institutional_are_allowed(): void
    {
        $base = User::factory()->create();
        $this->actingAs($base)->get('/configuracao/partilhar')->assertForbidden();

        foreach (['pro', 'institutional'] as $plan) {
            $user = User::factory()->create();
            $this->subscribe($user, $plan);
            $this->actingAs($user)->get('/configuracao/partilhar')->assertOk();
            $this->actingAs($user)->get('/configuracao/importar')->assertOk();
        }
    }

    #[Test]
    public function fully_configured_teacher_exports_only_the_structural_whitelist(): void
    {
        $organization = User::factory()->create()->personalOrganization();
        app(CurrentOrganization::class)->runFor($organization, function (): void {
            $package = $this->package();
            app(WriteConfigurationImport::class)->handle($package, ['school_identity', 'academic_year', 'subject', 'assessment_profile']);
            $profile = AssessmentProfile::query()->sole();
            $version = $profile->versions()->sole();
            $version->update(['status' => ProfileVersionStatus::Active]);
            $profile->forceFill(['current_version_id' => $version->id])->save();

            $export = app(GenerateConfigurationPackage::class)->handle(['school_identity' => true, 'assessment_profiles' => [$profile->ulid]]);
            $this->assertSame('lapis_configuration_package', $export['kind']);
            $this->assertContains('academic_years', $export['components']);
            $this->assertContains('subjects', $export['components']);
            $this->assertContains('assessment_profiles', $export['components']);
            $json = json_encode($export, JSON_THROW_ON_ERROR);
            foreach (['students', 'enrollments', 'scores', 'self_assessments', 'evidence_records', 'interventions', 'reports', 'users', 'memberships', 'passwords', 'tokens', 'logo_path'] as $forbidden) {
                $this->assertStringNotContainsString('"'.$forbidden.'"', $json);
            }
        });
    }

    #[Test]
    public function empty_tenant_previews_new_and_imports_a_draft_idempotently(): void
    {
        $organization = User::factory()->create()->personalOrganization();
        app(CurrentOrganization::class)->runFor($organization, function (): void {
            $package = app(ValidateConfigurationPackage::class)->fromJson(json_encode($this->package(), JSON_THROW_ON_ERROR));
            $first = app(BuildConfigurationImportPlan::class)->handle($package);
            $this->assertSame(4, $first['summary']['new']);
            $result = app(WriteConfigurationImport::class)->handle($package, ['school_identity', 'academic_year', 'subject', 'assessment_profile']);
            $this->assertSame(4, $result['created']);
            $this->assertSame(ProfileVersionStatus::Draft, AssessmentProfileVersion::query()->sole()->status);
            $this->assertNull(AssessmentProfile::query()->sole()->current_version_id);

            $second = app(BuildConfigurationImportPlan::class)->handle($package);
            $this->assertSame(4, $second['summary']['existing']);
            app(WriteConfigurationImport::class)->handle($package, ['school_identity', 'academic_year', 'subject', 'assessment_profile']);
            $this->assertSame(1, AcademicYear::query()->count());
            $this->assertSame(1, AssessmentProfile::query()->count());
        });
    }

    #[Test]
    public function conflicting_business_key_is_reported_and_never_overwritten(): void
    {
        $organization = User::factory()->create()->personalOrganization();
        app(CurrentOrganization::class)->runFor($organization, function (): void {
            AcademicYear::query()->create(['label' => '2026/2027', 'starts_on' => '2026-09-02', 'ends_on' => '2027-07-15', 'status' => 'draft', 'country_code' => 'PT']);
            $package = $this->package();
            $plan = app(BuildConfigurationImportPlan::class)->handle($package);
            $this->assertSame('conflict', collect($plan['rows'])->firstWhere('type', 'academic_year')['status']);
            app(WriteConfigurationImport::class)->handle($package, ['academic_year']);
            $this->assertSame('2026-09-02', AcademicYear::query()->sole()->starts_on->toDateString());
        });
    }

    #[Test]
    public function malformed_wrong_kind_version_and_malicious_keys_are_cleanly_rejected(): void
    {
        $validator = app(ValidateConfigurationPackage::class);
        foreach (['{garbage', json_encode(['schema_version' => 1]), json_encode(['kind' => 'lapis_configuration_package', 'schema_version' => 99])] as $json) {
            try {
                $validator->fromJson((string) $json);
                $this->fail('Expected validation failure.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('file', $exception->errors());
            }
        }
        foreach (['students', 'enrollments', 'scores', 'users', 'password', 'token'] as $key) {
            $package = $this->package();
            $package['payload']['subjects'][0][$key] = ['malicious'];
            $this->expectValidationFailure($package);
        }
    }

    #[Test]
    public function payload_component_mismatch_is_rejected(): void
    {
        $package = $this->package();
        $package['components'] = ['subjects'];
        $this->expectValidationFailure($package);
    }

    #[Test]
    public function a_schema_version_1_package_is_rejected_with_a_clear_message(): void
    {
        $package = $this->package();
        $package['schema_version'] = 1;

        try {
            app(ValidateConfigurationPackage::class)->fromJson(json_encode($package, JSON_THROW_ON_ERROR));
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Este pacote foi gerado por uma versão anterior; volte a gerar a partilha.'],
                $exception->errors()['file'],
            );
        }
    }

    #[Test]
    public function a_profile_shared_with_three_grade_levels_arrives_with_all_three(): void
    {
        $organization = User::factory()->create()->personalOrganization();
        app(CurrentOrganization::class)->runFor($organization, function (): void {
            $package = $this->package();
            $package['payload']['assessment_profiles'][0]['grade_levels'] = ['7.º', '8.º', '9.º'];

            app(WriteConfigurationImport::class)->handle($package, ['school_identity', 'academic_year', 'subject', 'assessment_profile']);

            $profile = AssessmentProfile::query()->with('gradeLevels')->sole();
            $this->assertSame(['7.º', '8.º', '9.º'], $profile->gradeLevels->pluck('grade_level')->all());
        });
    }

    #[Test]
    public function import_session_is_bound_to_the_current_tenant_and_provenance_cannot_target_writes(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');
        $source = $user->personalOrganization();
        $other = Organization::factory()->withMember($user)->create();
        $package = $this->package();
        $package['provenance']['organization_name'] = 'Organização A';
        $file = UploadedFile::fake()->createWithContent('config.json', json_encode($package, JSON_THROW_ON_ERROR));
        $this->actingAs($user)->withSession(['organization_id' => $source->id])->post('/configuracao/importar/preview', ['file' => $file])->assertOk();
        $this->actingAs($user)->withSession(['organization_id' => $other->id])->post('/configuracao/importar/confirmar', ['types' => ['academic_year']])->assertForbidden();
        $this->assertDatabaseMissing('academic_years', ['organization_id' => $source->id]);
        $this->assertDatabaseMissing('academic_years', ['organization_id' => $other->id]);
    }

    #[Test]
    public function importing_an_a_package_into_b_only_writes_b_rows(): void
    {
        $source = User::factory()->create()->personalOrganization();
        $destination = User::factory()->create()->personalOrganization();
        app(CurrentOrganization::class)->runFor($source, fn () => AcademicYear::query()->create(['label' => 'Origem', 'starts_on' => '2025-09-01', 'ends_on' => '2026-07-15', 'status' => 'draft', 'country_code' => 'PT']));
        $package = $this->package();
        $package['provenance']['organization_name'] = $source->name;
        app(CurrentOrganization::class)->runFor($destination, fn () => app(WriteConfigurationImport::class)->handle($package, ['academic_year']));

        $this->assertDatabaseHas('academic_years', ['organization_id' => $source->id, 'label' => 'Origem']);
        $this->assertDatabaseMissing('academic_years', ['organization_id' => $source->id, 'label' => '2026/2027']);
        $this->assertDatabaseHas('academic_years', ['organization_id' => $destination->id, 'label' => '2026/2027']);
    }

    #[Test]
    public function upload_size_is_limited_to_twenty_megabytes(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');
        $file = UploadedFile::fake()->create('too-large.json', 20481, 'application/json');
        $this->actingAs($user)->post('/configuracao/importar/preview', ['file' => $file])->assertSessionHasErrors('file');
    }

    #[Test]
    public function audit_entries_contain_only_safe_counts_and_component_names(): void
    {
        $user = User::factory()->create();
        $this->subscribe($user, 'pro');
        $organization = $user->personalOrganization();
        $subject = app(CurrentOrganization::class)->runFor($organization, fn () => Subject::query()->create(['name' => 'Segredo pedagógico não deve ir para auditoria', 'code' => 'SAFE']));
        $this->actingAs($user)->post('/configuracao/partilhar', ['subjects' => [$subject->ulid]])->assertOk();
        $event = AuditEvent::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('event', 'configuration_package.exported')->sole();
        $encoded = json_encode($event->properties, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($subject->name, $encoded);
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('token', strtolower($encoded));
    }

    /**
     * Um pacote exportado ANTES do rebranding, que é o que existe no disco de
     * quem já exportou: `product.name` diz «LAPIS». O campo é informativo e
     * nunca é validado, e mantê-lo assim aqui afirma que um pacote antigo
     * continua a importar. Não substituir por «Lapispro».
     *
     * @return array<string, mixed>
     */
    private function package(): array
    {
        return [
            'kind' => 'lapis_configuration_package', 'schema_version' => 2, 'exported_at' => now()->toIso8601String(),
            'product' => ['name' => 'LAPIS', 'version' => 'test'], 'provenance' => ['note' => 'Informativo, nunca usado para autorizar escrita.', 'organization_name' => 'Origem'],
            'components' => ['school_identity', 'academic_years', 'subjects', 'assessment_profiles'],
            'payload' => [
                'school_identity' => ['official_name' => 'Escola Segura'],
                'academic_years' => [['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-07-15', 'status' => 'draft', 'country_code' => 'PT', 'region_code' => null, 'periods' => []]],
                'subjects' => [['name' => 'Matemática', 'code' => 'MAT']], 'scales' => [],
                'assessment_profiles' => [[
                    'academic_year_label' => '2026/2027', 'subject_code' => 'MAT', 'grade_levels' => ['7'], 'name' => 'Perfil MAT', 'description' => 'Estrutural', 'is_institutional_template' => false,
                    'scale_reference' => ['name' => 'Escala 1 a 5', 'kind' => 'level', 'system' => true],
                    'version' => ['domain_weight_mode' => 'must_total_100', 'period_result_mode' => 'weighted_domain_average', 'accumulated_mode' => null, 'absence_mode' => null, 'rounding_mode' => null, 'rounding_scale' => 0, 'rounding_stage' => 'final_only', 'minimum_rules' => []],
                    'domains' => [['subject_code' => 'MAT', 'code' => 'NUM', 'name' => 'Números', 'parent_code' => null, 'domain_sequence' => 1, 'is_active' => true, 'weight_percent' => '100.0000', 'sequence' => 1, 'expected_element_count' => null, 'minimum_element_count' => null]],
                ]],
            ],
        ];
    }

    /** @param array<string, mixed> $package */
    private function expectValidationFailure(array $package): void
    {
        try {
            app(ValidateConfigurationPackage::class)->fromJson(json_encode($package, JSON_THROW_ON_ERROR));
            $this->fail('Expected validation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
    }

    private function subscribe(User $user, string $plan): void
    {
        OrganizationSubscription::withoutGlobalScope('organization')->where('organization_id', $user->personalOrganization()->id)->delete();
        OrganizationSubscription::withoutGlobalScope('organization')->create(['organization_id' => $user->personalOrganization()->id, 'plan_id' => Plan::query()->where('key', $plan)->firstOrFail()->id, 'status' => SubscriptionStatus::Active, 'starts_at' => Carbon::now()->subDay()]);
        app(Entitlements::class)->flush();
    }
}
