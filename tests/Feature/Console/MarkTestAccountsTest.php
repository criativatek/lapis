<?php

namespace Tests\Feature\Console;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A decisão humana aplicada em bloco — e a trava que impede o bloco de crescer.
 *
 * «Todas as contas atuais são de teste» foi verdade no dia em que foi dita. O
 * perigo deste comando é ele sobreviver a essa verdade: corrido um ano depois,
 * sem limite, marcaria como ensaio os clientes reais entretanto chegados. Daí
 * `--created-before` ser obrigatório, e daí metade destes testes serem sobre o
 * que o comando se recusa a fazer.
 */
class MarkTestAccountsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_platform_admin' => true])->save();

        return $admin;
    }

    private function organizationCreatedAt(string $when): Organization
    {
        $organization = User::factory()->create()->personalOrganization();
        $organization->forceFill(['created_at' => Carbon::parse($when)])->save();

        return $organization->fresh();
    }

    #[Test]
    public function it_marks_the_organizations_that_existed_before_the_cutoff(): void
    {
        $admin = $this->admin();
        $antiga = $this->organizationCreatedAt('2026-08-01 09:00');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($antiga->fresh()->is_test_account);
    }

    /** A trava: uma conta criada depois da data é estruturalmente inalcançável. */
    #[Test]
    public function it_never_reaches_an_account_created_after_the_cutoff(): void
    {
        $admin = $this->admin();
        $nova = $this->organizationCreatedAt('2026-09-15 09:00');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse($nova->fresh()->is_test_account, 'um registo posterior à decisão foi marcado como teste');
    }

    /**
     * O DEFEITO QUE UM ENSAIO EM MYSQL APANHOU, e que nenhum teste via.
     *
     * `--created-before=2026-08-29` era expandido para as 23:59:59 desse dia, e
     * uma conta criada às 23:55 — depois da decisão, portanto — era marcada como
     * de teste. Uma data expandida é um alvo móvel dentro do próprio dia. Agora
     * é lida à letra e a comparação é estritamente anterior.
     */
    #[Test]
    public function a_signup_later_on_the_cutoff_day_is_out_of_reach(): void
    {
        $admin = $this->admin();
        $noMesmoDia = $this->organizationCreatedAt('2026-08-29 23:55:23');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse(
            $noMesmoDia->fresh()->is_test_account,
            'uma conta criada depois da decisão, no mesmo dia, foi apanhada pela classificação histórica',
        );
    }

    /**
     * Para abranger um dia inteiro escreve-se a meia-noite seguinte, e aí sim
     * apanha o que nasceu às 23:55 desse dia. Um dia já passado, note-se: pedir
     * a meia-noite de amanhã cai no guard do futuro, que é o teste a seguir.
     */
    #[Test]
    public function the_whole_day_is_expressed_as_the_next_midnight(): void
    {
        $admin = $this->admin();
        $tardeNesseDia = $this->organizationCreatedAt('2026-08-20 23:55:23');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-21',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($tardeNesseDia->fresh()->is_test_account);
    }

    /** Uma classificação histórica não pode alcançar contas que ainda não existem. */
    #[Test]
    public function it_refuses_a_cutoff_in_the_future(): void
    {
        $admin = $this->admin();
        $organization = $this->organizationCreatedAt('2026-08-01 09:00');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => Carbon::now()->addDay()->toDateTimeString(),
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertFalse($organization->fresh()->is_test_account);
    }

    #[Test]
    public function it_refuses_without_a_cutoff(): void
    {
        $admin = $this->admin();
        $organization = $this->organizationCreatedAt('2026-08-01 09:00');

        $this->artisan('lapis:mark-test-accounts', ['--operator' => $admin->email, '--force' => true])
            ->assertExitCode(1);

        $this->assertFalse($organization->fresh()->is_test_account);
    }

    #[Test]
    public function it_refuses_without_an_operator(): void
    {
        $organization = $this->organizationCreatedAt('2026-08-01 09:00');

        $this->artisan('lapis:mark-test-accounts', ['--created-before' => '2026-08-29', '--force' => true])
            ->assertExitCode(1);

        $this->assertFalse($organization->fresh()->is_test_account);
    }

    #[Test]
    public function it_refuses_an_operator_who_is_not_a_platform_admin(): void
    {
        $notAdmin = User::factory()->create();
        $organization = $this->organizationCreatedAt('2026-08-01 09:00');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $notAdmin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(1);

        $this->assertFalse($organization->fresh()->is_test_account);
    }

    /** Cada organização leva o seu próprio evento, com o operador que decidiu. */
    #[Test]
    public function every_marked_organization_gets_its_own_audit_event(): void
    {
        $admin = $this->admin();
        $primeira = $this->organizationCreatedAt('2026-08-01 09:00');
        $segunda = $this->organizationCreatedAt('2026-08-02 09:00');

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(0);

        foreach ([$primeira, $segunda] as $organization) {
            $event = app(CurrentOrganization::class)->runFor(
                $organization,
                fn () => AuditEvent::where('event', 'admin.test_account_marked')->latest('id')->first(),
            );

            $this->assertNotNull($event, 'organização marcada sem rasto');
            $this->assertSame($admin->getKey(), $event->causer_id);
        }
    }

    #[Test]
    public function it_does_not_touch_the_subscriptions(): void
    {
        $admin = $this->admin();
        $this->organizationCreatedAt('2026-08-01 09:00');

        $before = DB::table('organization_subscriptions')->orderBy('id')->get()->toArray();

        $this->artisan('lapis:mark-test-accounts', [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertEquals($before, DB::table('organization_subscriptions')->orderBy('id')->get()->toArray());
    }

    /** Correr duas vezes não reescreve nada, nem enche a auditoria de ruído. */
    #[Test]
    public function it_is_idempotent(): void
    {
        $admin = $this->admin();
        $organization = $this->organizationCreatedAt('2026-08-01 09:00');

        $arguments = [
            '--operator' => $admin->email,
            '--created-before' => '2026-08-29',
            '--force' => true,
        ];

        $this->artisan('lapis:mark-test-accounts', $arguments)->assertExitCode(0);
        $this->artisan('lapis:mark-test-accounts', $arguments)->assertExitCode(0);

        $events = app(CurrentOrganization::class)->runFor(
            $organization,
            fn () => AuditEvent::where('event', 'admin.test_account_marked')->count(),
        );

        $this->assertSame(1, $events, 'a segunda passagem voltou a escrever');
    }
}
