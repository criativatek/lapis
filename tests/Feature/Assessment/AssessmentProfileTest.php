<?php

namespace Tests\Feature\Assessment;

use App\Models\AcademicYear;
use App\Models\AssessmentProfile;
use App\Models\ProfileVersionStatus;
use App\Models\Scale;
use App\Models\Subject;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AssessmentProfileTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function scaleId(): int
    {
        return app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Scale::where('name', 'Escala 1 a 5')->firstOrFail()->id,
        );
    }

    /** @var array{year: int, subject: int}|null Created once per test, then reused. */
    protected ?array $context = null;

    /**
     * @return array{year: int, subject: int}
     */
    protected function context(): array
    {
        return $this->context ??= app(CurrentOrganization::class)->runFor($this->user->personalOrganization(), function () {
            return [
                'year' => AcademicYear::factory()->recycle($this->user->personalOrganization())->create()->id,
                'subject' => Subject::factory()->recycle($this->user->personalOrganization())->create()->id,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        $context = $this->context();

        return array_merge([
            'name' => 'Português – 7.º Ano – Escala 1 a 5',
            'academic_year_id' => $context['year'],
            'subject_id' => $context['subject'],
            'grade_level' => '7.º',
            'description' => null,
            'scale_id' => $this->scaleId(),
            'domains' => [
                ['name' => 'Oralidade', 'weight' => 20],
                ['name' => 'Leitura', 'weight' => 25],
                ['name' => 'Escrita', 'weight' => 20],
                ['name' => 'Gramática', 'weight' => 15],
                ['name' => 'Educação Literária', 'weight' => 20],
            ],
        ], $overrides);
    }

    #[Test]
    public function a_teacher_creates_a_profile_as_a_draft(): void
    {
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload())->assertRedirect('/assessment-profiles');

        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();
        $this->assertNull($profile->current_version_id, 'A new profile has no active version yet.');

        $version = $profile->versions()->firstOrFail();
        $this->assertSame(ProfileVersionStatus::Draft, $version->status);
        $this->assertSame(5, $version->domains()->count());
    }

    #[Test]
    public function activating_a_valid_draft_freezes_it_and_sets_the_current_version(): void
    {
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload());
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->post("/assessment-profiles/{$profile->ulid}/activate")->assertRedirect('/assessment-profiles');

        $profile->refresh();
        $this->assertNotNull($profile->current_version_id);
        $this->assertSame(ProfileVersionStatus::Active, $profile->currentVersion->status);
        $this->assertNotNull($profile->currentVersion->frozen_at);
    }

    #[Test]
    public function activation_is_rejected_when_weights_do_not_total_100(): void
    {
        $payload = $this->payload(['domains' => [
            ['name' => 'Oralidade', 'weight' => 50],
            ['name' => 'Leitura', 'weight' => 30],
        ]]); // 80

        $this->actingAs($this->user)->post('/assessment-profiles', $payload);
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();

        $this->actingAs($this->user)->post("/assessment-profiles/{$profile->ulid}/activate")
            ->assertSessionHasErrors('activation');

        $this->assertNull($profile->refresh()->current_version_id);
    }

    #[Test]
    public function editing_an_active_profile_opens_a_new_draft_version(): void
    {
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload());
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();
        $this->actingAs($this->user)->post("/assessment-profiles/{$profile->ulid}/activate");

        // Editing the now-active profile must not touch v1 — it opens v2 as a draft.
        $editPayload = $this->payload([
            'academic_year_id' => $profile->academic_year_id,
            'subject_id' => $profile->subject_id,
            'domains' => [
                ['name' => 'Oralidade', 'weight' => 30],
                ['name' => 'Leitura', 'weight' => 70],
            ],
        ]);

        $this->actingAs($this->user)->put("/assessment-profiles/{$profile->ulid}", $editPayload)->assertRedirect();

        $profile->refresh();
        $this->assertSame(2, $profile->versions()->count());
        $this->assertSame(ProfileVersionStatus::Active, $profile->currentVersion->status);
        $this->assertSame(1, $profile->currentVersion->version_number, 'v1 stays the active/frozen one until v2 is activated.');
        $this->assertNotNull($profile->draftVersion());
        $this->assertSame(2, $profile->draftVersion()->version_number);
    }

    #[Test]
    public function a_teacher_cannot_touch_another_organizations_profile(): void
    {
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload());
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();

        $intruder = User::factory()->create();
        $this->actingAs($intruder)->get("/assessment-profiles/{$profile->ulid}/edit")->assertNotFound();
        $this->actingAs($intruder)->post("/assessment-profiles/{$profile->ulid}/activate")->assertNotFound();
    }

    #[Test]
    public function a_profile_with_an_active_version_cannot_be_deleted(): void
    {
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload());
        $profile = AssessmentProfile::withoutGlobalScope('organization')->firstOrFail();

        // Draft-only: deletable.
        $this->actingAs($this->user)->post('/assessment-profiles', $this->payload(['name' => 'Outro perfil']));
        $draftOnly = AssessmentProfile::withoutGlobalScope('organization')->where('name', 'Outro perfil')->firstOrFail();
        $this->actingAs($this->user)->delete("/assessment-profiles/{$draftOnly->ulid}")->assertRedirect();

        // Active: not deletable.
        $this->actingAs($this->user)->post("/assessment-profiles/{$profile->ulid}/activate");
        $this->actingAs($this->user)->delete("/assessment-profiles/{$profile->ulid}")->assertForbidden();
    }
}
