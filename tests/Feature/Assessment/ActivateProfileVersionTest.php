<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\AssessmentProfileVersion;
use App\Models\Domain;
use App\Models\Organization;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\User;
use App\Services\Assessment\ActivateProfileVersion;
use App\Support\Assessment\FrozenProfileVersionException;
use App\Support\Assessment\ProfileActivationException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ActivateProfileVersionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->organization = $this->user->personalOrganization();
    }

    /**
     * Runs the callback with the tenant resolved — the supported way to enter a
     * tenant outside HTTP (the container scope is reset between resolutions, so a
     * bare set() in setUp does not survive to the test body).
     */
    protected function inTenant(callable $callback): mixed
    {
        return app(CurrentOrganization::class)->runFor($this->organization, $callback);
    }

    /**
     * @param  list<float>  $weights
     */
    protected function draftWithWeights(array $weights): AssessmentProfileVersion
    {
        $org = $this->organization;

        $year = AcademicYear::factory()->recycle($org)->create();
        $subject = Subject::factory()->recycle($org)->create();
        $scale = Scale::where('name', 'Escala 1 a 5')->firstOrFail();
        $profile = AssessmentProfile::factory()->recycle($org)->create([
            'academic_year_id' => $year->id,
            'subject_id' => $subject->id,
        ]);

        $version = AssessmentProfileVersion::factory()->recycle($org)->create([
            'assessment_profile_id' => $profile->id,
            'scale_id' => $scale->id,
            'status' => ProfileVersionStatus::Draft,
            'version_number' => 1,
        ]);

        foreach ($weights as $index => $weight) {
            $domain = Domain::factory()->recycle($org)->create(['subject_id' => $subject->id]);
            $version->domains()->create([
                'domain_id' => $domain->id,
                'weight_percent' => $weight,
                'sequence' => $index + 1,
            ]);
        }

        return $version->refresh();
    }

    protected function activate(AssessmentProfileVersion $version): AssessmentProfileVersion
    {
        return app(ActivateProfileVersion::class)->activate($version, $this->user);
    }

    #[Test]
    public function it_activates_a_draft_whose_weights_total_100(): void
    {
        $this->inTenant(function (): void {
            $version = $this->draftWithWeights([20, 25, 20, 20, 15]);

            $activated = $this->activate($version);

            $this->assertSame(ProfileVersionStatus::Active, $activated->status);
            $this->assertNotNull($activated->activated_at);
            $this->assertNotNull($activated->frozen_at);
            $this->assertSame($this->user->id, $activated->activated_by);
            $this->assertSame($activated->id, $activated->profile->refresh()->current_version_id);
        });
    }

    #[Test]
    public function it_refuses_to_activate_when_weights_do_not_total_100(): void
    {
        $this->inTenant(function (): void {
            $version = $this->draftWithWeights([20, 25, 20, 20]); // 85

            try {
                $this->activate($version);
                $this->fail('Expected ProfileActivationException.');
            } catch (ProfileActivationException $e) {
                $this->assertStringContainsString('100', $e->getMessage());
            }

            $this->assertSame(ProfileVersionStatus::Draft, $version->refresh()->status);
        });
    }

    #[Test]
    public function it_refuses_to_activate_a_profile_with_no_domains(): void
    {
        $this->inTenant(function (): void {
            $version = $this->draftWithWeights([]);

            $this->expectException(ProfileActivationException::class);
            $this->activate($version);
        });
    }

    #[Test]
    public function it_refuses_to_activate_a_version_that_is_not_a_draft(): void
    {
        $this->inTenant(function (): void {
            $version = $this->activate($this->draftWithWeights([100]));

            $this->expectException(ProfileActivationException::class);
            $this->activate($version);
        });
    }

    #[Test]
    public function activating_a_new_version_supersedes_the_previous_active_one(): void
    {
        $this->inTenant(function (): void {
            $v1 = $this->activate($this->draftWithWeights([100]));

            $v2 = AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $v1->assessment_profile_id,
                'scale_id' => $v1->scale_id,
                'status' => ProfileVersionStatus::Draft,
                'version_number' => 2,
            ]);
            $domain = Domain::factory()->recycle($this->organization)->create();
            $v2->domains()->create(['domain_id' => $domain->id, 'weight_percent' => 100, 'sequence' => 1]);

            $this->activate($v2->refresh());

            $v1->refresh();
            $this->assertSame(ProfileVersionStatus::Superseded, $v1->status);
            $this->assertSame($v2->id, $v1->superseded_by_version_id);
            $this->assertNotNull($v1->superseded_at);

            $activeCount = AssessmentProfileVersion::where('assessment_profile_id', $v1->assessment_profile_id)
                ->where('status', ProfileVersionStatus::Active->value)->count();
            $this->assertSame(1, $activeCount);
        });
    }

    #[Test]
    public function the_database_forbids_two_active_versions_for_the_same_profile(): void
    {
        $this->inTenant(function (): void {
            $v1 = $this->activate($this->draftWithWeights([100]));

            $this->expectException(QueryException::class);

            AssessmentProfileVersion::factory()->recycle($this->organization)->create([
                'assessment_profile_id' => $v1->assessment_profile_id,
                'scale_id' => $v1->scale_id,
                'status' => ProfileVersionStatus::Active,
                'version_number' => 2,
            ]);
        });
    }

    #[Test]
    public function a_frozen_version_cannot_be_edited(): void
    {
        $this->inTenant(function (): void {
            $version = $this->activate($this->draftWithWeights([100]));

            $this->expectException(FrozenProfileVersionException::class);

            $version->update(['change_note' => 'tentativa de alterar uma versão congelada']);
        });
    }

    #[Test]
    public function activating_does_not_freeze_a_shared_system_scale(): void
    {
        $this->inTenant(function (): void {
            $version = $this->activate($this->draftWithWeights([100]));

            // The 1–5 scale is a system scale (organization_id NULL): immutable by
            // contract, so one org's activation must not freeze it for everyone.
            $this->assertNull($version->scale->refresh()->frozen_at);
        });
    }

    #[Test]
    public function activating_freezes_an_organization_owned_scale(): void
    {
        $this->inTenant(function (): void {
            $ownScale = Scale::factory()->create([
                'organization_id' => $this->organization->id,
                'name' => 'Escala própria',
            ]);

            $version = $this->draftWithWeights([100]);
            $version->update(['scale_id' => $ownScale->id]);

            $this->activate($version->refresh());

            $this->assertNotNull($ownScale->refresh()->frozen_at);
        });
    }
}
