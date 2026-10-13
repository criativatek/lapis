<?php

namespace Tests\Feature\Usage;

use App\Actions\Usage\SetUsageCollection;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Usage\Insights\InsightsTestCase;

/**
 * A autorização de LIGAR E DESLIGAR a recolha é separada da de CONSULTAR o
 * painel (docs/usage-insights.md, «Quem tem acesso»): administrador da
 * plataforma E `can_control_usage_collection`, não concedida por omissão,
 * verificada no servidor em todos os pontos de entrada, auditada, e retirada
 * com o estatuto de administrador.
 */
class UsageCollectionControlTest extends InsightsTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A base dos testes do painel começa com a recolha ligada; aqui começa
        // desligada, para cada `start` contar.
        DB::table('usage_collection_periods')->delete();
    }

    private function controller(string $name = 'Controla'): User
    {
        $user = $this->platformAdmin(usageInsights: true, name: $name);
        $user->forceFill(['can_control_usage_collection' => true])->save();

        return $user;
    }

    private function audits(string $event): int
    {
        return AuditEvent::query()->withoutGlobalScope('organization')->where('event', $event)->count();
    }

    private function accountOf(User $user): Organization
    {
        return $this->organizationOf($user);
    }

    // --- Quem pode ligar e desligar -------------------------------------------

    #[Test]
    public function the_authorisation_is_not_given_by_default_nor_by_viewing_the_panel(): void
    {
        // `operator` consulta o painel, e é administrador.
        $this->assertTrue($this->operator->canViewUsageInsights());
        $this->assertFalse($this->operator->canControlUsageCollection());
        $this->assertFalse(User::factory()->create()->refresh()->can_control_usage_collection);

        // A autorização sem o estatuto de administrador não vale nada.
        $flagOnly = User::factory()->create();
        $flagOnly->forceFill(['can_control_usage_collection' => true])->save();
        $this->assertFalse($flagOnly->canControlUsageCollection());

        $this->assertTrue($this->controller()->canControlUsageCollection());
    }

    #[Test]
    public function an_admin_who_only_views_the_panel_cannot_start_or_stop(): void
    {
        $this->artisan('lapis:usage-collection', ['action' => 'start', '--operator' => $this->operator->email])->assertFailed();
        $this->assertSame(0, DB::table('usage_collection_periods')->count());

        // Nem pela ação diretamente — a verificação está nela, não só no comando.
        $this->expectException(AuthorizationException::class);
        app(SetUsageCollection::class)->start($this->operator);
    }

    #[Test]
    public function stop_is_guarded_the_same_way(): void
    {
        DB::table('usage_collection_periods')->insert(['started_at' => '2026-10-12 09:00:00', 'ended_at' => null, 'is_open' => true]);

        $this->artisan('lapis:usage-collection', ['action' => 'stop', '--operator' => $this->operator->email])->assertFailed();
        $this->assertSame(1, DB::table('usage_collection_periods')->where('is_open', true)->count());

        $this->expectException(AuthorizationException::class);
        app(SetUsageCollection::class)->stop($this->operator);
    }

    #[Test]
    public function an_authorised_admin_can_start_and_stop_and_both_are_audited(): void
    {
        $controller = $this->controller();

        $this->artisan('lapis:usage-collection', ['action' => 'start', '--operator' => $controller->email])->assertSuccessful();
        $this->assertSame(1, DB::table('usage_collection_periods')->where('is_open', true)->count());

        $this->artisan('lapis:usage-collection', ['action' => 'stop', '--operator' => $controller->email])->assertSuccessful();
        $this->assertSame(0, DB::table('usage_collection_periods')->where('is_open', true)->count());

        $this->assertSame(1, $this->audits('admin.usage_collection_started'));
        $this->assertSame(1, $this->audits('admin.usage_collection_stopped'));
    }

    #[Test]
    public function a_control_authorisation_withdrawn_mid_process_counts_at_once(): void
    {
        $controller = $this->controller();
        DB::table('users')->where('id', $controller->id)->update(['can_control_usage_collection' => false]);

        // O objeto em memória ainda a tem; a ação lê da base.
        $this->expectException(AuthorizationException::class);
        app(SetUsageCollection::class)->start($controller);
    }

    // --- Concessão no servidor (primeira concessão) ------------------------------

    #[Test]
    public function the_console_grant_is_for_admins_only_and_audited(): void
    {
        $admin = $this->platformAdmin(name: 'Administradora');
        $teacher = $this->person('Professora');

        $this->artisan('lapis:grant-usage-insights', ['email' => $teacher->email, '--collection-control' => true])->assertFailed();
        $this->assertFalse($teacher->refresh()->can_control_usage_collection);

        $this->artisan('lapis:grant-usage-insights', ['email' => $admin->email, '--collection-control' => true])->assertSuccessful();
        $admin->refresh();
        $this->assertTrue($admin->canControlUsageCollection());
        // Só a de controlo: a de ver o painel é outra.
        $this->assertFalse($admin->can_view_usage_insights);

        $event = AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_collection_control_granted')->sole();
        $this->assertNull($event->causer_id);
        $this->assertNull($event->organization_id);
        $this->assertSame($admin->id, $event->properties['target_user_id']);
        $this->assertSame('console', $event->properties['via']);

        // Repetir não muda nada nem volta a auditar.
        $this->artisan('lapis:grant-usage-insights', ['email' => $admin->email, '--collection-control' => true])->assertSuccessful();
        $this->assertSame(1, $this->audits('admin.usage_collection_control_granted'));

        $this->artisan('lapis:grant-usage-insights', ['email' => $admin->email, '--collection-control' => true, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($admin->refresh()->can_control_usage_collection);
        $this->assertSame(1, $this->audits('admin.usage_collection_control_revoked'));
    }

    // --- Concessão no backoffice ----------------------------------------------------

    #[Test]
    public function an_admin_who_only_views_the_panel_cannot_give_it_to_anyone_not_even_themselves(): void
    {
        $colleague = $this->platformAdmin(usageInsights: true, name: 'Colega');

        foreach ([$this->operator, $colleague] as $target) {
            $this->actingAs($this->operator)
                ->post(route('admin.accounts.usage-collection-control', $this->accountOf($target)), ['granted' => true])
                ->assertForbidden();
        }

        $this->assertFalse($this->operator->refresh()->can_control_usage_collection);
        $this->assertFalse($colleague->refresh()->can_control_usage_collection);
        $this->assertSame(0, $this->audits('admin.usage_collection_control_granted'));
    }

    #[Test]
    public function an_authorised_admin_grants_and_revokes_it_to_another_admin_with_an_audit(): void
    {
        $controller = $this->controller();
        $colleague = $this->platformAdmin(usageInsights: true, name: 'Colega');

        $this->actingAs($controller)
            ->post(route('admin.accounts.usage-collection-control', $this->accountOf($colleague)), ['granted' => true])
            ->assertRedirect();
        $this->assertTrue($colleague->refresh()->canControlUsageCollection());

        $event = AuditEvent::query()->withoutGlobalScope('organization')->where('event', 'admin.usage_collection_control_granted')->sole();
        $this->assertSame($controller->id, $event->causer_id);
        $this->assertSame($colleague->id, $event->properties['target_user_id']);

        $this->actingAs($controller)
            ->post(route('admin.accounts.usage-collection-control', $this->accountOf($colleague)), ['granted' => false])
            ->assertRedirect();
        $this->assertFalse($colleague->refresh()->can_control_usage_collection);
        $this->assertSame(1, $this->audits('admin.usage_collection_control_revoked'));
    }

    #[Test]
    public function nobody_changes_their_own_control_and_the_target_must_be_an_admin(): void
    {
        $controller = $this->controller();
        $teacher = $this->person('Professora');

        $this->actingAs($controller)
            ->post(route('admin.accounts.usage-collection-control', $this->accountOf($controller)), ['granted' => false])
            ->assertSessionHasErrors('granted');
        $this->assertTrue($controller->refresh()->can_control_usage_collection);

        $this->actingAs($controller)
            ->post(route('admin.accounts.usage-collection-control', $this->accountOf($teacher)), ['granted' => true])
            ->assertSessionHasErrors('granted');
        $this->assertFalse($teacher->refresh()->can_control_usage_collection);

        $this->assertSame(0, $this->audits('admin.usage_collection_control_granted'));
        $this->assertSame(0, $this->audits('admin.usage_collection_control_revoked'));
    }

    #[Test]
    public function the_account_page_tells_only_those_who_hold_it(): void
    {
        $controller = $this->controller();
        $colleague = $this->platformAdmin(usageInsights: true, name: 'Colega');

        $this->actingAs($controller)
            ->get(route('admin.accounts.show', $this->accountOf($colleague)))
            ->assertInertia(fn ($page) => $page->where('usage_collection_control', [
                'can_manage' => true,
                'owner_is_admin' => true,
                'is_self' => false,
                'granted' => false,
            ]));

        // Quem só consulta o painel não sabe quem a tem.
        $this->actingAs($this->operator)
            ->get(route('admin.accounts.show', $this->accountOf($controller)))
            ->assertInertia(fn ($page) => $page->where('usage_collection_control', [
                'can_manage' => false,
                'owner_is_admin' => false,
                'is_self' => false,
                'granted' => false,
            ]));
    }

    // --- Retirar o estatuto de administrador ----------------------------------------

    #[Test]
    public function revoking_admin_status_also_withdraws_it_by_command_and_in_the_backoffice(): void
    {
        $byCommand = $this->controller('Por comando');
        $this->artisan('lapis:make-admin', ['email' => $byCommand->email, '--revoke' => true])->assertSuccessful();
        $this->assertFalse($byCommand->refresh()->can_control_usage_collection);

        $inBackoffice = $this->controller('No backoffice');
        $this->actingAs($this->controller('Quem revoga'))
            ->post("/admin/accounts/{$this->accountOf($inBackoffice)->ulid}/toggle-admin")
            ->assertRedirect();
        $inBackoffice->refresh();
        $this->assertFalse($inBackoffice->is_platform_admin);
        $this->assertFalse($inBackoffice->can_control_usage_collection);
    }
}
