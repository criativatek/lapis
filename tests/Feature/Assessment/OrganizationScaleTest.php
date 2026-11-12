<?php

namespace Tests\Feature\Assessment;

use App\Actions\Organizations\SetOrganizationDefaultScale;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Scale;
use App\Models\User;
use App\Services\Assessment\DeriveCustomScale;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A escala da organização: definida uma vez, usada pelos perfis novos.
 * É só um apontador (`organizations.default_scale_id`) — nenhum perfil
 * existente muda sozinho.
 */
class OrganizationScaleTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    protected function system(): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')->where('name', 'Escala 1 a 5')->firstOrFail();
    }

    /**
     * @return list<array{label: string, from_percent: int, inovar_code: string|null}>
     */
    protected function levels(): array
    {
        return [
            ['label' => 'Insuficiente', 'from_percent' => 0, 'inovar_code' => 'NS'],
            ['label' => 'Suficiente', 'from_percent' => 50, 'inovar_code' => 'S'],
            ['label' => 'Bom', 'from_percent' => 70, 'inovar_code' => 'SB'],
            ['label' => 'Muito Bom', 'from_percent' => 90, 'inovar_code' => 'SMB'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'levels' => $this->levels(),
            'first_positive_level' => 2,
            'profile_name' => 'Português',
            'scale_name' => 'Escala da escola',
            'use_as_organization_default' => true,
        ], $overrides);
    }

    protected function save(Scale $source, array $overrides = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)->post("/scales/{$source->ulid}/mencoes", $this->payload($overrides));
    }

    protected function defaultScaleId(?User $user = null): ?int
    {
        return Organization::query()->whereKey(($user ?? $this->user)->personalOrganization()->id)->value('default_scale_id');
    }

    protected function ownScale(string $name, ?User $user = null): Scale
    {
        return Scale::withoutGlobalScope('scaleVisibility')
            ->where('organization_id', ($user ?? $this->user)->personalOrganization()->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    #[Test]
    public function a_new_profile_opens_with_the_system_scale_until_the_organization_has_one(): void
    {
        $this->actingAs($this->user)->get('/assessment-profiles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultScaleId', $this->system()->id));
    }

    #[Test]
    public function ticking_the_box_makes_the_saved_scale_the_one_new_profiles_open_with(): void
    {
        $this->save($this->system())->assertSessionHasNoErrors();

        $scale = $this->ownScale('Escala da escola');
        $this->assertSame($scale->id, $this->defaultScaleId());

        $this->actingAs($this->user)->get('/assessment-profiles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('defaultScaleId', $scale->id)
                ->where('scales', fn ($scales) => collect($scales)->firstWhere('id', $scale->id)['organization_default'] === true
                    && collect($scales)->firstWhere('id', $this->system()->id)['organization_default'] === false));

        $event = AuditEvent::withoutGlobalScopes()->where('event', 'scale.mentions_customised')->latest('id')->firstOrFail();
        $this->assertTrue($event->properties['organization_default']);
    }

    #[Test]
    public function the_form_lists_the_organization_scale_first_among_the_custom_ones(): void
    {
        $this->save($this->system(), ['use_as_organization_default' => false, 'scale_name' => 'A primeira', 'profile_name' => 'A']);
        $this->save($this->system(), ['scale_name' => 'Z da organização', 'profile_name' => 'Z']);

        $this->actingAs($this->user)->get('/assessment-profiles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('scales', function ($scales): bool {
                $custom = collect($scales)->where('system', false)->pluck('label')->values()->all();

                return $custom === ['Z da organização', 'A primeira'];
            }));
    }

    #[Test]
    public function without_the_box_nothing_is_set_and_the_audit_says_so(): void
    {
        $this->save($this->system(), ['use_as_organization_default' => false])->assertSessionHasNoErrors();

        $this->assertNull($this->defaultScaleId());
        $event = AuditEvent::withoutGlobalScopes()->where('event', 'scale.mentions_customised')->latest('id')->firstOrFail();
        $this->assertFalse($event->properties['organization_default']);
    }

    #[Test]
    public function editing_in_place_keeps_the_pointer_on_the_same_scale(): void
    {
        $this->save($this->system());
        $scale = $this->ownScale('Escala da escola');

        $this->save($scale, ['scale_name' => 'Escala da escola'])->assertSessionHasNoErrors();

        $this->assertSame($scale->id, $this->defaultScaleId());
    }

    #[Test]
    public function the_pointer_moves_to_the_new_scale_when_copy_on_write_creates_one(): void
    {
        $this->save($this->system());
        $frozen = $this->ownScale('Escala da escola');
        $frozen->forceFill(['frozen_at' => Carbon::parse('2026-11-01 10:00:00')])->save();

        $this->save($frozen, ['scale_name' => 'Escala da escola v2'])->assertSessionHasNoErrors();

        $fresh = $this->ownScale('Escala da escola v2');
        $this->assertNotSame($frozen->id, $fresh->id);
        $this->assertSame($fresh->id, $this->defaultScaleId());
    }

    #[Test]
    public function unticking_clears_the_pointer_only_when_it_pointed_at_this_scale_or_its_predecessor(): void
    {
        $this->save($this->system());
        $organizationScale = $this->ownScale('Escala da escola');

        // Outra escala, sem caixa: o apontador fica onde estava.
        $this->save($this->system(), ['use_as_organization_default' => false, 'scale_name' => 'Outra', 'profile_name' => 'Outra']);
        $this->assertSame($organizationScale->id, $this->defaultScaleId());

        // Desmarcar na escala da organização: limpa.
        $this->save($organizationScale, ['use_as_organization_default' => false, 'scale_name' => 'Escala da escola']);
        $this->assertNull($this->defaultScaleId());
    }

    #[Test]
    public function unticking_on_a_copy_on_write_clears_a_pointer_that_pointed_at_the_predecessor(): void
    {
        $this->save($this->system());
        $frozen = $this->ownScale('Escala da escola');
        $frozen->forceFill(['frozen_at' => Carbon::parse('2026-11-01 10:00:00')])->save();

        $this->save($frozen, ['use_as_organization_default' => false, 'scale_name' => 'Escala da escola v2']);

        $this->assertNull($this->defaultScaleId());
    }

    #[Test]
    public function a_name_typed_by_the_teacher_that_exists_on_another_scale_is_an_error_on_the_name(): void
    {
        $this->save($this->system(), ['scale_name' => 'Repetida', 'use_as_organization_default' => false]);

        $this->save($this->system(), ['scale_name' => 'Repetida', 'profile_name' => 'Outro'])->assertSessionHasErrors('scale_name');
        $this->assertSame(1, Scale::withoutGlobalScope('scaleVisibility')->whereNotNull('organization_id')->count());
    }

    #[Test]
    public function the_untouched_default_name_gets_a_numbered_suffix_instead(): void
    {
        $this->save($this->system(), ['scale_name' => 'Escala 1 a 4 · Português', 'use_as_organization_default' => false]);
        $this->save($this->system(), ['scale_name' => 'Escala 1 a 4 · Português', 'use_as_organization_default' => false])->assertSessionHasNoErrors();

        $this->assertNotNull($this->ownScale('Escala 1 a 4 · Português (2)'));
    }

    #[Test]
    public function renaming_in_place_to_a_free_name_works_and_to_a_taken_one_does_not(): void
    {
        $this->save($this->system(), ['scale_name' => 'Primeira', 'use_as_organization_default' => false, 'profile_name' => 'P1']);
        $this->save($this->system(), ['scale_name' => 'Segunda', 'use_as_organization_default' => false, 'profile_name' => 'P2']);
        $second = $this->ownScale('Segunda');

        $this->save($second, ['scale_name' => 'Primeira'])->assertSessionHasErrors('scale_name');
        $this->save($second, ['scale_name' => 'Terceira'])->assertSessionHasNoErrors();
        $this->assertSame($second->id, $this->ownScale('Terceira')->id);
    }

    #[Test]
    public function another_organizations_scale_can_never_become_this_organizations_default(): void
    {
        $this->save($this->system());
        $strangersScale = $this->ownScale('Escala da escola');
        $stranger = User::factory()->create();

        // A escala de outra organização nem sequer é visível: a rota responde 404 e nada se grava.
        $this->save($strangersScale, ['scale_name' => 'Roubada'], $stranger)->assertNotFound();
        $this->assertNull($this->defaultScaleId($stranger));

        // E o serviço recusa-se a apontar a organização para uma escala que não é dela.
        app(CurrentOrganization::class)->runFor($stranger->personalOrganization(), function () use ($strangersScale): void {
            $this->expectException(\InvalidArgumentException::class);
            app(SetOrganizationDefaultScale::class)->apply($this->system(), $strangersScale, true);
        });
    }

    #[Test]
    public function a_default_that_points_at_a_scale_the_tenant_cannot_see_falls_back_to_the_system_scale(): void
    {
        $this->save($this->system());
        $strangersScale = $this->ownScale('Escala da escola');
        $stranger = User::factory()->create();

        // Um apontador adulterado diretamente na base de dados.
        Organization::query()->whereKey($stranger->personalOrganization()->id)->update(['default_scale_id' => $strangersScale->id]);

        $this->actingAs($stranger)->get('/assessment-profiles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultScaleId', $this->system()->id));
    }

    #[Test]
    public function deleting_the_scale_falls_back_to_the_system_scale(): void
    {
        $this->save($this->system());
        $scale = $this->ownScale('Escala da escola');
        $scale->levels()->delete();
        $scale->delete();

        // Em MySQL a FK desfaz o apontador (`nullOnDelete`); aqui, em SQLite, o
        // apontador pode ficar para trás — o que conta é o que o formulário lê.
        $this->actingAs($this->user)->get('/assessment-profiles/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('defaultScaleId', $this->system()->id));
    }

    #[Test]
    public function a_member_who_is_not_the_owner_cannot_define_the_organization_scale(): void
    {
        $member = User::factory()->create();
        $organization = $this->user->personalOrganization();
        $organization->members()->attach($member, ['joined_at' => now()]);

        $this->actingAs($member)->withSession(['organization_id' => $organization->id])
            ->post("/scales/{$this->system()->ulid}/mencoes", $this->payload())
            ->assertForbidden();

        $this->assertNull($this->defaultScaleId());
    }

    #[Test]
    public function the_service_alone_keeps_its_old_behaviour_without_a_scale_name(): void
    {
        $derived = app(CurrentOrganization::class)->runFor(
            $this->user->personalOrganization(),
            fn () => app(DeriveCustomScale::class)->for($this->system(), $this->levels(), 2, 'Matemática'),
        );

        $this->assertSame('Escala 1 a 4 · Matemática', $derived->name);
    }
}
