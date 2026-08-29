<?php

namespace Tests\Feature\Admin;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationSubscription;
use App\Models\User;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * «Conta de teste» — o que a marca diz, e as muitas coisas que ela NÃO faz.
 *
 * Nasceu de um portão que disparou: o pré-voo comercial parou um deploy sobre
 * onze subscrições em vigor sem condição registada, e a resposta humana foi que
 * nenhuma delas é um cliente — são todas contas de ensaio, incluindo as de dois
 * professores parceiros convidados. Faltava à aplicação a noção de conta que
 * existe para experimentar, e as saídas sem ela eram fabricar contratos que
 * ninguém acordou ou desligar o portão.
 *
 * A metade defensiva destes testes é a que interessa: uma marca que grantisse
 * ou tirasse fosse o que fosse seria uma condição comercial com outro nome.
 */
class TestAccountTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function account(): Organization
    {
        return User::factory()->create()->personalOrganization()->fresh();
    }

    #[Test]
    public function an_organization_is_not_a_test_account_by_default(): void
    {
        $this->assertFalse($this->account()->is_test_account);
    }

    #[Test]
    public function a_public_signup_creates_a_real_account(): void
    {
        $this->post('/register', [
            'name' => 'Rita Nova',
            'email' => 'rita.nova@example.test',
            'password' => 'password-forte-123',
            'password_confirmation' => 'password-forte-123',
        ]);

        $organization = User::where('email', 'rita.nova@example.test')->sole()->personalOrganization();

        $this->assertFalse(
            $organization->fresh()->is_test_account,
            'um registo público nasceu conta de teste — nunca pode herdar isso de ninguém',
        );
    }

    /**
     * A coluna não é preenchível, e é isso que impede um `$request->all()`
     * distraído — ou um cliente a inventar o campo no formulário de registo —
     * de declarar a própria conta como de teste.
     *
     * A aplicação corre com `preventSilentlyDiscardingAttributes`, por isso a
     * tentativa REBENTA em vez de ser ignorada em silêncio. Melhor assim: um
     * atributo descartado sem ruído é um bug que só se descobre em produção.
     */
    #[Test]
    public function the_mark_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        Organization::create([
            'name' => 'Tentativa',
            'type' => 'personal',
            'owner_id' => User::factory()->create()->getKey(),
            'is_test_account' => true,
        ]);
    }

    #[Test]
    public function an_admin_can_mark_an_account_as_a_test_account_and_it_is_audited(): void
    {
        $organization = $this->account();

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => true])
            ->assertRedirect();

        $this->assertTrue($organization->fresh()->is_test_account);
        $this->assertTrue($this->trailHas($organization, 'admin.test_account_marked'));
    }

    #[Test]
    public function an_admin_can_take_the_mark_back_off(): void
    {
        $organization = $this->account();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => true]);
        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => false]);

        $this->assertFalse($organization->fresh()->is_test_account);
        $this->assertTrue($this->trailHas($organization, 'admin.test_account_cleared'));
    }

    /** O rasto tem de dizer QUEM decidiu e o que estava lá antes. */
    #[Test]
    public function the_trail_records_the_operator_and_the_previous_value(): void
    {
        $organization = $this->account();
        $admin = $this->admin();

        $this->actingAs($admin)->post("/admin/accounts/{$organization->ulid}/test-account", [
            'is_test_account' => true,
            'note' => 'Conta de ensaio do professor parceiro.',
        ]);

        $event = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'admin.test_account_marked')->latest('id')->firstOrFail(),
        );

        $this->assertSame($admin->getKey(), $event->causer_id);
        $this->assertFalse($event->properties['previous_is_test_account']);
        $this->assertTrue($event->properties['is_test_account']);
        $this->assertSame('Conta de ensaio do professor parceiro.', $event->properties['note']);
    }

    /**
     * O CERNE. Marcar não é vender, não é oferecer e não é suspender: a
     * subscrição inteira — plano, versão, estado, condição, preço e prazo — tem
     * de sair byte a byte igual, e os módulos concedidos também.
     */
    #[Test]
    public function marking_changes_nothing_about_the_subscription_or_the_access(): void
    {
        $organization = $this->account();

        $subscriptionBefore = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())->orderBy('id')->get()->toArray();
        $modulesBefore = app(Entitlements::class)->modulesFor($organization);

        $this->actingAs($this->admin())
            ->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => true]);

        $subscriptionAfter = DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())->orderBy('id')->get()->toArray();

        $this->assertEquals($subscriptionBefore, $subscriptionAfter, 'a marca tocou na subscrição');
        $this->assertSame($modulesBefore, app(Entitlements::class)->modulesFor($organization->fresh()));
    }

    /** Nem sequer uma linha nova: marcar não cria snapshot comercial nenhum. */
    #[Test]
    public function marking_creates_no_commercial_snapshot(): void
    {
        $organization = $this->account();
        // Criado ANTES da contagem: o admin tem organização pessoal própria, e
        // contá-la a meio faria este teste falhar por uma razão que não é a sua.
        $admin = $this->admin();

        DB::table('organization_subscriptions')
            ->where('organization_id', $organization->getKey())
            ->update(['commercial_condition' => null, 'contracted_price_cents' => null, 'commercial_term_ends_at' => null]);

        $countBefore = OrganizationSubscription::withoutGlobalScope('organization')->count();

        $this->actingAs($admin)
            ->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => true]);

        $row = DB::table('organization_subscriptions')->where('organization_id', $organization->getKey())->first();

        $this->assertSame($countBefore, OrganizationSubscription::withoutGlobalScope('organization')->count());
        $this->assertNull($row->commercial_condition, 'foi inventada uma condição comercial');
        $this->assertNull($row->contracted_price_cents, 'foi inventado um preço');
        $this->assertNull($row->commercial_term_ends_at, 'foi inventado um prazo comercial');
    }

    #[Test]
    public function the_backoffice_shows_whether_an_account_is_a_test_account(): void
    {
        $organization = $this->account();

        $this->actingAs($this->admin())->get("/admin/accounts/{$organization->ulid}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('account.is_test_account', false));

        $organization->forceFill(['is_test_account' => true])->save();

        $this->actingAs($this->admin())->get("/admin/accounts/{$organization->ulid}")
            ->assertInertia(fn ($page) => $page->where('account.is_test_account', true));
    }

    #[Test]
    public function a_teacher_cannot_mark_their_own_account_as_a_test_account(): void
    {
        $teacher = User::factory()->create();
        $organization = $teacher->personalOrganization();

        $this->actingAs($teacher)
            ->post("/admin/accounts/{$organization->ulid}/test-account", ['is_test_account' => true])
            ->assertForbidden();

        $this->assertFalse($organization->fresh()->is_test_account);
    }

    private function trailHas(Organization $org, string $event): bool
    {
        return app(CurrentOrganization::class)->runFor($org, fn () => AuditEvent::where('event', $event)->exists());
    }
}
