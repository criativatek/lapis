<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationType;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class InsightsAccessTest extends InsightsTestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function listingRoutes(): array
    {
        return [
            'visão geral' => ['admin.usage.overview'],
            'acompanhamento' => ['admin.usage.users.index'],
            'ranking' => ['admin.usage.ranking'],
        ];
    }

    #[Test]
    #[DataProvider('listingRoutes')]
    public function a_teacher_is_refused(string $routeName): void
    {
        $this->visit($routeName, as: $this->person('Professora Comum'))->assertForbidden();
    }

    #[Test]
    #[DataProvider('listingRoutes')]
    public function an_admin_without_the_authorisation_is_refused(string $routeName): void
    {
        $this->visit($routeName, as: $this->platformAdmin(usageInsights: false, name: 'Admin Sem Acesso'))->assertForbidden();
    }

    #[Test]
    #[DataProvider('listingRoutes')]
    public function an_authorised_admin_gets_the_page(string $routeName): void
    {
        $this->visit($routeName)->assertOk();
    }

    #[Test]
    public function the_detail_page_is_guarded_too(): void
    {
        $teacher = $this->person('Pessoa Observada');
        $state = $this->state($teacher);

        $this->visit('admin.usage.users.show', as: $teacher, parameters: ['usageUser' => $state])->assertForbidden();
        $this->visit('admin.usage.users.show', as: $this->platformAdmin(name: 'Outro Admin'), parameters: ['usageUser' => $state])->assertForbidden();
        $this->visit('admin.usage.users.show', parameters: ['usageUser' => $state])->assertOk();
    }

    #[Test]
    public function the_owner_of_an_institutional_organization_cannot_see_it(): void
    {
        $owner = $this->person('Responsável da Escola');
        $school = Organization::factory()->institutional()->withMember($owner)->create(['owner_id' => $owner->id]);
        $this->assertSame(OrganizationType::Institutional, $school->type);

        foreach (['admin.usage.overview', 'admin.usage.users.index', 'admin.usage.ranking'] as $routeName) {
            $this->visit($routeName, as: $owner)->assertForbidden();
        }
    }

    // --- Conceder e revogar no backoffice --------------------------------

    private function accountOfAdmin(User $admin): Organization
    {
        return $this->organizationOf($admin);
    }

    #[Test]
    public function an_authorised_operator_can_grant_the_authorisation_to_another_admin(): void
    {
        $colleague = $this->platformAdmin(name: 'Colega Admin');

        $this->actingAs($this->operator)
            ->post(route('admin.accounts.usage-insights', $this->accountOfAdmin($colleague)), ['granted' => true])
            ->assertRedirect();

        $this->assertTrue($colleague->refresh()->can_view_usage_insights);

        $event = AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_insights_granted')->sole();
        $this->assertSame($this->operator->id, $event->causer_id);
        $this->assertSame($colleague->id, $event->properties['target_user_id']);
        $this->assertNull($event->organization_id);
    }

    #[Test]
    public function an_authorised_operator_can_revoke_it(): void
    {
        $colleague = $this->platformAdmin(usageInsights: true, name: 'Colega Admin');

        $this->actingAs($this->operator)
            ->post(route('admin.accounts.usage-insights', $this->accountOfAdmin($colleague)), ['granted' => false])
            ->assertRedirect();

        $this->assertFalse($colleague->refresh()->can_view_usage_insights);
        $this->assertSame(1, AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_insights_revoked')->count());
    }

    #[Test]
    public function the_target_must_be_a_platform_admin(): void
    {
        $teacher = $this->person('Professora Comum');

        $this->actingAs($this->operator)
            ->post(route('admin.accounts.usage-insights', $this->organizationOf($teacher)), ['granted' => true])
            ->assertSessionHasErrors('granted');

        $this->assertFalse($teacher->refresh()->can_view_usage_insights);
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_insights_granted')->count());
    }

    #[Test]
    public function an_operator_cannot_revoke_their_own_authorisation(): void
    {
        $this->actingAs($this->operator)
            ->post(route('admin.accounts.usage-insights', $this->accountOfAdmin($this->operator)), ['granted' => false])
            ->assertSessionHasErrors(['granted' => 'Use o comando para retirar a sua própria autorização.']);

        $this->assertTrue($this->operator->refresh()->can_view_usage_insights);
        $this->assertSame(0, AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_insights_revoked')->count());
    }

    #[Test]
    public function an_operator_without_the_authorisation_cannot_grant_it(): void
    {
        $withoutAccess = $this->platformAdmin(name: 'Sem Acesso');
        $colleague = $this->platformAdmin(name: 'Colega Admin');

        $this->actingAs($withoutAccess)
            ->post(route('admin.accounts.usage-insights', $this->accountOfAdmin($colleague)), ['granted' => true])
            ->assertForbidden();

        $this->assertFalse($colleague->refresh()->can_view_usage_insights);
    }

    #[Test]
    public function the_account_page_exposes_only_the_three_flags(): void
    {
        $colleague = $this->platformAdmin(usageInsights: true, name: 'Colega Admin');
        $teacher = $this->person('Professora Comum');

        $this->actingAs($this->operator)
            ->get(route('admin.accounts.show', $this->accountOfAdmin($colleague)))
            ->assertInertia(fn ($page) => $page->where('usage_insights', [
                'can_manage' => true,
                'owner_is_admin' => true,
                'granted' => true,
            ]));

        $this->actingAs($this->platformAdmin(name: 'Sem Acesso'))
            ->get(route('admin.accounts.show', $this->organizationOf($teacher)))
            ->assertInertia(fn ($page) => $page->where('usage_insights', [
                'can_manage' => false,
                'owner_is_admin' => false,
                'granted' => false,
            ]));
    }
}
