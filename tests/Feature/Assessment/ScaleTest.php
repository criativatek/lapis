<?php

namespace Tests\Feature\Assessment;

use App\Models\Module;
use App\Models\OrganizationModuleOverride;
use App\Models\Scale;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Escala personalizada',
            'min_value' => 1,
            'max_value' => 10,
            'kind' => 'percentage',
        ], $overrides);
    }

    #[Test]
    public function a_valid_scale_is_created_as_custom_for_the_current_organization(): void
    {
        $this->actingAs($this->user)->post('/scales', $this->payload())->assertRedirect();

        $scale = Scale::withoutGlobalScope('scaleVisibility')
            ->where('name', 'Escala personalizada')
            ->firstOrFail();

        $this->assertSame('custom', $scale->kind);
        $this->assertSame($this->user->personalOrganization()->id, $scale->organization_id);
        $this->assertNotNull($scale->organization_id);
    }

    #[Test]
    public function duplicate_name_within_the_same_organization_is_rejected(): void
    {
        app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => Scale::create([
                'name' => 'Escala personalizada',
                'kind' => 'custom',
                'min_value' => 0,
                'max_value' => 20,
            ]),
        );

        $this->actingAs($this->user)
            ->post('/scales', $this->payload())
            ->assertSessionHasErrors('name');
    }

    #[Test]
    public function maximum_value_must_be_greater_than_minimum_value(): void
    {
        $this->actingAs($this->user)
            ->post('/scales', $this->payload(['min_value' => 10, 'max_value' => 10]))
            ->assertSessionHasErrors('max_value');
    }

    #[Test]
    public function unauthenticated_and_without_module_access_are_rejected(): void
    {
        $this->post('/scales', $this->payload())->assertRedirect('/login');

        OrganizationModuleOverride::withoutGlobalScope('organization')->create([
            'organization_id' => $this->user->personalOrganization()->id,
            'module_id' => Module::where('key', 'assessment_profiles')->firstOrFail()->id,
            'enabled' => false,
            'reason' => 'Teste de acesso ao módulo.',
        ]);
        app(Entitlements::class)->flush();

        $this->actingAs($this->user)->post('/scales', $this->payload())->assertForbidden();
    }
}
