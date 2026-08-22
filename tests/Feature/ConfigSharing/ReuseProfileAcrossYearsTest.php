<?php

declare(strict_types=1);

namespace Tests\Feature\ConfigSharing;

use App\Actions\ConfigSharing\BuildYearReusePackage;
use App\Actions\ConfigSharing\WriteConfigurationImport;
use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\Organization;
use App\Models\ProfileVersionStatus;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ReuseProfileAcrossYearsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function reuse_creates_an_independent_draft_with_the_same_structure_and_leaves_the_source_untouched(): void
    {
        [$user, $source, $target] = $this->configuredProfile();
        $sourceAttributes = $source->getAttributes();
        $sourceVersionAttributes = $source->currentVersion->getAttributes();
        $studentCount = $this->tableCount('students');
        $enrollmentCount = $this->tableCount('enrollments');

        $package = app(CurrentOrganization::class)->runFor(
            $user->personalOrganization(),
            fn (): array => app(BuildYearReusePackage::class)->handle($source, $target),
        );

        $this->actingAs($user)
            ->post("/assessment-profiles/{$source->ulid}/reuse/confirm", ['package' => json_encode($package, JSON_THROW_ON_ERROR)])
            ->assertRedirect('/assessment-profiles');

        app(CurrentOrganization::class)->runFor($user->personalOrganization(), function () use ($source, $sourceAttributes, $sourceVersionAttributes, $target): void {
            $profiles = AssessmentProfile::query()->with(['versions.domains.domain', 'versions.scale'])->orderBy('id')->get();
            $this->assertCount(2, $profiles);
            $copy = $profiles->firstWhere('academic_year_id', $target->id);
            $this->assertNotNull($copy);
            $this->assertNotSame($source->id, $copy->id);
            $this->assertNull($copy->current_version_id);
            $this->assertSame(ProfileVersionStatus::Draft, $copy->versions->sole()->status);
            $this->assertNotSame($source->current_version_id, $copy->versions->sole()->id);
            $this->assertSame($source->currentVersion->scale_id, $copy->versions->sole()->scale_id);
            $this->assertSame(
                $source->currentVersion->domains->map(fn ($domain): array => [$domain->domain->code, $domain->weight_percent, $domain->sequence])->all(),
                $copy->versions->sole()->domains->map(fn ($domain): array => [$domain->domain->code, $domain->weight_percent, $domain->sequence])->all(),
            );
            $this->assertSame($sourceAttributes, $source->fresh()->getAttributes());
            $this->assertSame($sourceVersionAttributes, $source->currentVersion->fresh()->getAttributes());
        });

        $this->assertSame($studentCount, $this->tableCount('students'));
        $this->assertSame($enrollmentCount, $this->tableCount('enrollments'));
        $this->assertDatabaseHas('audit_events', ['event' => 'configuration_package.reused', 'subject_id' => $source->id]);
    }

    #[Test]
    public function choosing_the_source_year_is_rejected_without_writing(): void
    {
        [$user, $source] = $this->configuredProfile();

        $this->actingAs($user)
            ->post("/assessment-profiles/{$source->ulid}/reuse/preview", ['target_academic_year' => $source->academicYear->ulid])
            ->assertStatus(422);

        $this->assertDatabaseCount('assessment_profiles', 1);
    }

    #[Test]
    public function an_equivalent_profile_in_the_target_year_is_not_duplicated(): void
    {
        [$user, $source, $target] = $this->configuredProfile();
        $package = app(CurrentOrganization::class)->runFor(
            $user->personalOrganization(),
            fn (): array => app(BuildYearReusePackage::class)->handle($source, $target),
        );
        app(CurrentOrganization::class)->runFor(
            $user->personalOrganization(),
            fn (): array => app(WriteConfigurationImport::class)->handle($package, ['assessment_profile']),
        );

        $this->actingAs($user)
            ->from("/assessment-profiles/{$source->ulid}/reuse/preview")
            ->post("/assessment-profiles/{$source->ulid}/reuse/confirm", ['package' => json_encode($package, JSON_THROW_ON_ERROR)])
            ->assertRedirect("/assessment-profiles/{$source->ulid}/reuse/preview")
            ->assertSessionHasErrors(['reuse' => __('Já existe um perfil equivalente nesse ano letivo.')]);

        $this->assertDatabaseCount('assessment_profiles', 2);
    }

    #[Test]
    public function a_non_owner_is_forbidden_from_all_reuse_routes(): void
    {
        [$owner, $source, $target] = $this->configuredProfile();
        $organization = $owner->personalOrganization();
        $member = User::factory()->create();
        $organization->members()->attach($member, ['joined_at' => now()]);
        $package = app(CurrentOrganization::class)->runFor(
            $organization,
            fn (): array => app(BuildYearReusePackage::class)->handle($source, $target),
        );
        $session = ['organization_id' => $organization->id];

        $this->actingAs($member)->withSession($session)->get("/assessment-profiles/{$source->ulid}/reuse")->assertForbidden();
        $this->actingAs($member)->withSession($session)->post("/assessment-profiles/{$source->ulid}/reuse/preview", ['target_academic_year' => $target->ulid])->assertForbidden();
        $this->actingAs($member)->withSession($session)->post("/assessment-profiles/{$source->ulid}/reuse/confirm", ['package' => json_encode($package, JSON_THROW_ON_ERROR)])->assertForbidden();
    }

    #[Test]
    public function an_academic_year_from_another_organization_cannot_be_selected(): void
    {
        [$user, $source] = $this->configuredProfile();
        $otherOrganization = Organization::factory()->create();
        $foreignYear = app(CurrentOrganization::class)->runFor(
            $otherOrganization,
            fn (): AcademicYear => AcademicYear::factory()->recycle($otherOrganization)->create(),
        );

        $this->actingAs($user)
            ->post("/assessment-profiles/{$source->ulid}/reuse/preview", ['target_academic_year' => $foreignYear->ulid])
            ->assertNotFound();

        $this->assertDatabaseCount('assessment_profiles', 1);
    }

    #[Test]
    public function the_reuse_package_never_contains_student_or_enrollment_data(): void
    {
        [$user, $source, $target] = $this->configuredProfile();
        $package = app(CurrentOrganization::class)->runFor(
            $user->personalOrganization(),
            fn (): array => app(BuildYearReusePackage::class)->handle($source, $target),
        );
        $json = json_encode($package, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('students', $json);
        $this->assertStringNotContainsString('enrollments', $json);
        $this->assertSame([], $package['payload']['academic_years']);
        $this->assertNotContains('academic_years', $package['components']);
    }

    /** @return array{User, AssessmentProfile, AcademicYear} */
    private function configuredProfile(): array
    {
        $user = User::factory()->create();
        $organization = $user->personalOrganization();

        return app(CurrentOrganization::class)->runFor($organization, function () use ($user, $organization): array {
            app(WriteConfigurationImport::class)->handle($this->sourcePackage(), ['academic_year', 'subject', 'assessment_profile']);
            $source = AssessmentProfile::query()->with(['academicYear', 'subject', 'currentVersion.domains.domain'])->sole();
            $version = $source->versions()->sole();
            $version->update(['status' => ProfileVersionStatus::Active]);
            $source->forceFill(['current_version_id' => $version->id])->save();
            $source->refresh()->load(['academicYear', 'subject', 'currentVersion.domains.domain']);
            $target = AcademicYear::factory()->recycle($organization)->create([
                'label' => '2027/2028',
                'starts_on' => '2027-09-01',
                'ends_on' => '2028-07-15',
            ]);

            return [$user, $source, $target];
        });
    }

    /** @return array<string, mixed> */
    private function sourcePackage(): array
    {
        return [
            'kind' => 'lapis_configuration_package', 'schema_version' => 1, 'exported_at' => now()->toIso8601String(),
            'product' => ['name' => 'LAPIS', 'version' => 'test'], 'provenance' => ['note' => 'Teste', 'organization_name' => 'Origem'],
            'components' => ['academic_years', 'subjects', 'assessment_profiles'],
            'payload' => [
                'school_identity' => null,
                'academic_years' => [['label' => '2026/2027', 'starts_on' => '2026-09-01', 'ends_on' => '2027-07-15', 'status' => 'draft', 'country_code' => 'PT', 'region_code' => null, 'periods' => []]],
                'subjects' => [['name' => 'Matemática', 'code' => 'MAT']], 'scales' => [],
                'assessment_profiles' => [[
                    'academic_year_label' => '2026/2027', 'subject_code' => 'MAT', 'grade_level' => '7', 'name' => 'Perfil MAT', 'description' => 'Estrutural', 'is_institutional_template' => false,
                    'scale_reference' => ['name' => 'Escala 1 a 5', 'kind' => 'level', 'system' => true],
                    'version' => ['domain_weight_mode' => 'must_total_100', 'period_result_mode' => 'weighted_domain_average', 'accumulated_mode' => null, 'absence_mode' => null, 'rounding_mode' => null, 'rounding_scale' => 0, 'rounding_stage' => 'final_only', 'minimum_rules' => []],
                    'domains' => [['subject_code' => 'MAT', 'code' => 'NUM', 'name' => 'Números', 'parent_code' => null, 'domain_sequence' => 1, 'is_active' => true, 'weight_percent' => '100.0000', 'sequence' => 1, 'expected_element_count' => null, 'minimum_element_count' => null]],
                ]],
            ],
        ];
    }

    private function tableCount(string $table): int
    {
        return DB::table($table)->count();
    }
}
