<?php

namespace Tests\Feature\Usage;

use App\Actions\Usage\SetUsageClassification;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Usage\UsageClassification;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A classificação de uma organização para as métricas de utilização: própria,
 * decidida por uma pessoa e independente de `is_test_account` (comercial).
 */
class UsageClassificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-10-12 10:00:00', 'Europe/Lisbon'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function admin(bool $usageInsights = true): User
    {
        $user = User::factory()->create();
        $user->forceFill(['is_platform_admin' => true, 'can_view_usage_insights' => $usageInsights])->save();

        return $user;
    }

    private function organization(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    // --- Esquema e enum ---------------------------------------------------

    #[Test]
    public function every_organization_is_born_unclassified_even_when_it_is_a_commercial_test_account(): void
    {
        $this->assertTrue(Schema::hasColumns('organizations', ['usage_classification', 'usage_classified_at']));

        $organization = $this->organization();
        $this->assertSame('unclassified', $organization->usage_classification);
        $this->assertNull($organization->usage_classified_at);

        $organization->forceFill(['is_test_account' => true])->save();
        $this->assertSame('unclassified', $organization->fresh()->usage_classification);
    }

    #[Test]
    public function the_columns_are_not_mass_assignable(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Organization)->fill(['usage_classification' => 'real']);
    }

    #[Test]
    public function the_enum_has_four_values_each_with_label_and_description(): void
    {
        $this->assertSame(['unclassified', 'real', 'test', 'internal'], array_map(fn (UsageClassification $c): string => $c->value, UsageClassification::cases()));
        $this->assertSame('Por classificar', UsageClassification::Unclassified->label());
        $this->assertSame('Utilização real', UsageClassification::Real->label());
        $this->assertSame('Teste e demonstração', UsageClassification::Test->label());
        $this->assertSame('Interna', UsageClassification::Internal->label());

        foreach (UsageClassification::cases() as $case) {
            $this->assertNotSame('', $case->description());
        }
    }

    // --- Escritor ---------------------------------------------------------

    #[Test]
    public function the_action_writes_and_audits_on_the_platform_and_not_on_the_organization(): void
    {
        $operator = $this->admin();
        $organization = $this->organization();

        app(SetUsageClassification::class)->set($organization, $operator, UsageClassification::Real, '  Professora convidada  ');

        $fresh = $organization->fresh();
        $this->assertSame('real', $fresh->usage_classification);
        $this->assertSame('2026-10-12 10:00:00', $fresh->usage_classified_at?->toDateTimeString());

        $event = AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->sole();
        $this->assertNull($event->organization_id);
        $this->assertSame($operator->id, $event->causer_id);
        $this->assertSame([
            'organization_id' => $organization->id,
            'previous' => 'unclassified',
            'classification' => 'real',
            'note' => 'Professora convidada',
        ], $event->properties);

        $this->assertSame(0, AuditEvent::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('event', 'like', '%classification%')->count());
        $this->assertFalse($fresh->is_test_account);
    }

    #[Test]
    public function setting_the_same_value_neither_writes_nor_audits(): void
    {
        $operator = $this->admin();
        $organization = $this->organization();

        app(SetUsageClassification::class)->set($organization, $operator, UsageClassification::Unclassified);

        $this->assertNull($organization->fresh()->usage_classified_at);
        $this->assertSame(0, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->count());

        $action = app(SetUsageClassification::class);
        $action->set($organization, $operator, UsageClassification::Test);
        $this->travel(1)->hours();
        $action->set($organization, $operator, UsageClassification::Test);

        $this->assertSame(1, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->count());
        $this->assertSame('2026-10-12 10:00:00', $organization->fresh()->usage_classified_at?->toDateTimeString());
    }

    // --- Rota -------------------------------------------------------------

    #[Test]
    public function the_route_needs_the_usage_insights_authorisation(): void
    {
        $organization = $this->organization();
        $url = route('admin.accounts.usage-classification', $organization);

        $this->actingAs(User::factory()->create())->post($url, ['classification' => 'real'])->assertForbidden();
        $this->actingAs($this->admin(false))->post($url, ['classification' => 'real'])->assertForbidden();

        $this->assertSame('unclassified', $organization->fresh()->usage_classification);
    }

    #[Test]
    public function the_route_validates_the_value_and_the_note(): void
    {
        $organization = $this->organization();
        $url = route('admin.accounts.usage-classification', $organization);
        $operator = $this->admin();

        $this->actingAs($operator)->post($url, ['classification' => 'main'])->assertSessionHasErrors('classification');
        $this->actingAs($operator)->post($url, [])->assertSessionHasErrors('classification');
        $this->actingAs($operator)->post($url, ['classification' => 'real', 'note' => str_repeat('x', 501)])->assertSessionHasErrors('note');

        $this->assertSame('unclassified', $organization->fresh()->usage_classification);
    }

    #[Test]
    public function the_route_classifies_the_organization(): void
    {
        $organization = $this->organization();
        $operator = $this->admin();

        $this->actingAs($operator)
            ->post(route('admin.accounts.usage-classification', $organization), ['classification' => 'internal', 'note' => 'Conta da equipa'])
            ->assertRedirect();

        $this->assertSame('internal', $organization->fresh()->usage_classification);
        $this->assertSame('Conta da equipa', AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->sole()->properties['note']);
    }

    // --- Ficha da conta ---------------------------------------------------

    #[Test]
    public function the_account_page_only_carries_the_classification_to_those_who_may_see_the_metrics(): void
    {
        $organization = $this->organization();
        $organization->forceFill(['usage_classification' => 'test'])->save();
        $url = route('admin.accounts.show', $organization);

        $this->actingAs($this->admin(true))->get($url)->assertOk()->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $this->assertSame('test', $props['usage_classification']['value']);
            $this->assertSame(['unclassified', 'real', 'test', 'internal'], array_column($props['usage_classification']['options'], 'key'));
        });

        $response = $this->actingAs($this->admin(false))->get($url)->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page->where('usage_classification', null));
        $this->assertStringNotContainsString('usage_classified_at', $response->getContent());
    }

    // --- Comando ----------------------------------------------------------

    #[Test]
    public function the_list_option_writes_nothing(): void
    {
        $organization = $this->organization();

        $this->artisan('lapis:usage-classify', ['--list' => true])
            ->expectsOutputToContain($organization->ulid)
            ->assertExitCode(0);

        $this->assertSame('unclassified', $organization->fresh()->usage_classification);
        $this->assertSame(0, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->count());
    }

    #[Test]
    public function the_command_applies_a_classification_for_an_authorised_operator(): void
    {
        $organization = $this->organization();
        $operator = $this->admin();

        $this->artisan('lapis:usage-classify', [
            'organization' => $organization->ulid,
            'classification' => 'real',
            '--operator' => $operator->email,
            '--note' => 'Parceira',
        ])->assertExitCode(0);

        $this->assertSame('real', $organization->fresh()->usage_classification);
        $this->assertSame('Parceira', AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->sole()->properties['note']);
    }

    #[Test]
    public function the_command_refuses_bad_input(): void
    {
        $organization = $this->organization();
        $operator = $this->admin();
        $plainAdmin = $this->admin(false);

        $base = ['organization' => $organization->ulid, 'classification' => 'real'];

        $this->artisan('lapis:usage-classify', $base)->assertExitCode(1);
        $this->artisan('lapis:usage-classify', [...$base, '--operator' => 'ninguem@exemplo.pt'])->assertExitCode(1);
        $this->artisan('lapis:usage-classify', [...$base, '--operator' => $plainAdmin->email])->assertExitCode(1);
        $this->artisan('lapis:usage-classify', [...$base, 'classification' => 'main', '--operator' => $operator->email])->assertExitCode(1);
        $this->artisan('lapis:usage-classify', [...$base, 'organization' => 'inexistente', '--operator' => $operator->email])->assertExitCode(1);

        $this->assertSame('unclassified', $organization->fresh()->usage_classification);
        $this->assertSame(0, AuditEvent::withoutGlobalScope('organization')->where('event', 'admin.usage_classification_set')->count());
    }
}
