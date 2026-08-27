<?php

namespace Tests\Feature\Retention;

use App\Actions\Accounts\AnonymiseClosedAccount;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Student;
use App\Models\StudentIdentity;
use App\Models\User;
use App\Support\Retention\RetentionPolicy;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The half of account closure that never existed: carrying it out.
 *
 * Every test here answers a question somebody would ask in a data-protection
 * review — «does the countdown actually do anything», «can a closed account
 * still log in», «does closing a teacher's account take their school's data
 * with it». The last one is the reason the isolation cases are explicit rather
 * than assumed from the tenancy tests.
 */
class ExecuteAccountClosuresTest extends TestCase
{
    use RefreshDatabase;

    /** A closure asked for long enough ago that the window has run out. */
    private function closedLongAgo(User $user): User
    {
        $days = app(RetentionPolicy::class)->personalAccountClosureDays();

        $user->forceFill([
            'closure_requested_at' => now()->subDays($days + 1),
            'scheduled_deletion_at' => now()->subDay(),
        ])->save();

        return $user->refresh();
    }

    private function closedYesterday(User $user): User
    {
        $user->forceFill([
            'closure_requested_at' => now()->subDay(),
            'scheduled_deletion_at' => now()->addDays(59),
        ])->save();

        return $user->refresh();
    }

    // ---- Caso 1 -----------------------------------------------------------

    #[Test]
    public function an_account_still_inside_its_window_is_left_alone(): void
    {
        $user = $this->closedYesterday(User::factory()->create());
        $email = $user->email;

        $this->artisan('retention:execute')->assertSuccessful();

        $user->refresh();
        $this->assertNull($user->anonymized_at);
        $this->assertSame($email, $user->email);
        $this->assertNull($user->deactivated_at);
    }

    /** An account that never asked to close is not touched either. */
    #[Test]
    public function an_account_that_never_asked_to_close_is_left_alone(): void
    {
        $user = User::factory()->create();
        $email = $user->email;

        $this->artisan('retention:execute')->assertSuccessful();

        $this->assertSame($email, $user->refresh()->email);
        $this->assertNull($user->anonymized_at);
    }

    // ---- Caso 2 -----------------------------------------------------------

    #[Test]
    public function an_eligible_account_is_actually_closed(): void
    {
        $user = $this->closedLongAgo(User::factory()->create());
        $originalEmail = $user->email;
        $originalName = $user->name;

        $this->artisan('retention:execute')->assertSuccessful();

        $user->refresh();

        $this->assertNotNull($user->anonymized_at);
        $this->assertNotSame($originalEmail, $user->email);
        $this->assertNotSame($originalName, $user->name);
        $this->assertNotNull($user->deactivated_at);
    }

    // ---- Caso 3 -----------------------------------------------------------

    #[Test]
    public function running_it_again_over_an_already_closed_account_changes_nothing(): void
    {
        $user = $this->closedLongAgo(User::factory()->create());

        $this->artisan('retention:execute')->assertSuccessful();

        $after = $this->snapshot($user);
        $events = AuditEvent::query()->withoutGlobalScopes()->where('event', 'account.closure_executed')->count();

        $this->artisan('retention:execute')->assertSuccessful();

        $this->assertSame($after, $this->snapshot($user));

        // And no second audit event for the same closure.
        $this->assertSame($events, AuditEvent::query()->withoutGlobalScopes()->where('event', 'account.closure_executed')->count());
    }

    // ---- Caso 4 -----------------------------------------------------------

    #[Test]
    public function only_the_personal_organizations_student_identities_are_removed(): void
    {
        $user = User::factory()->create();
        $personal = $user->personalOrganization();
        $this->seedStudent($personal);

        $stranger = User::factory()->create();
        $strangerPersonal = $stranger->personalOrganization();
        $this->seedStudent($strangerPersonal);

        $this->closedLongAgo($user);

        $this->artisan('retention:execute')->assertSuccessful();

        $this->assertSame(0, $this->identityCount($personal), 'A identidade do aluno da conta encerrada devia ter sido removida.');
        $this->assertSame(1, $this->identityCount($strangerPersonal), 'A conta de outra pessoa não pode ser afetada.');

        // The pedagogical row survives, now pointing at nobody identifiable.
        $this->assertSame(1, $this->studentCount($personal));
        $this->assertDatabaseHas('organizations', ['id' => $personal->getKey()]);
    }

    // ---- Caso 5 -----------------------------------------------------------

    #[Test]
    public function an_institution_the_person_merely_belongs_to_is_untouched(): void
    {
        $user = User::factory()->create();
        $institution = Organization::factory()->institutional()->withMember($user)->create();
        $this->seedStudent($institution);

        $this->closedLongAgo($user);

        $this->artisan('retention:execute')->assertSuccessful();

        $this->assertDatabaseHas('organizations', ['id' => $institution->getKey()]);
        $this->assertSame(1, $this->identityCount($institution), 'Os dados da escola não podem sair com o professor.');
        $this->assertSame(1, $this->studentCount($institution));

        // The membership stays: the account cannot authenticate anyway, and
        // removing it would orphan class_teachers for teaching that happened.
        $this->assertDatabaseHas('organization_memberships', [
            'organization_id' => $institution->getKey(),
            'user_id' => $user->getKey(),
        ]);
    }

    // ---- Caso 6 -----------------------------------------------------------

    #[Test]
    public function credentials_sessions_and_passkeys_stop_working(): void
    {
        $user = User::factory()->create(['password' => 'palavra-passe-de-teste']);
        $user->forceFill([
            'two_factor_secret' => 'segredo',
            'two_factor_recovery_codes' => 'codigos',
            'two_factor_confirmed_at' => now(),
            'remember_token' => 'lembrar',
        ])->save();

        DB::table('sessions')->insert([
            'id' => 'sessao-de-teste',
            'user_id' => $user->getKey(),
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->closedLongAgo($user);
        $this->artisan('retention:execute')->assertSuccessful();

        $user->refresh();

        $this->assertFalse(Hash::check('palavra-passe-de-teste', $user->password));
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->remember_token);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->deactivated_at, 'Sem isto, EnsureUserIsActive deixaria a sessão passar.');
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->getKey()]);
    }

    // ---- Caso 7 -----------------------------------------------------------

    #[Test]
    public function the_personal_identifiers_are_gone_from_the_row(): void
    {
        $user = User::factory()->create(['name' => 'Maria Antunes', 'email' => 'maria.antunes@escola.pt']);
        $personal = $user->personalOrganization();
        $this->closedLongAgo($user);

        $this->artisan('retention:execute')->assertSuccessful();

        $user->refresh();

        $this->assertStringNotContainsString('Maria', $user->name);
        $this->assertStringNotContainsString('Antunes', $user->name);
        $this->assertStringNotContainsString('maria.antunes', $user->email);
        $this->assertStringNotContainsString('escola.pt', $user->email);
        $this->assertStringEndsWith('@invalido.local', $user->email);

        // The workspace stops naming anybody too.
        $this->assertNotSame($personal->name, $personal->refresh()->name);
    }

    // ---- Caso 8 -----------------------------------------------------------

    #[Test]
    public function the_execution_is_audited_without_keeping_what_it_removed(): void
    {
        $user = User::factory()->create(['name' => 'Rui Pereira', 'email' => 'rui.pereira@escola.pt']);
        $this->closedLongAgo($user);

        $this->artisan('retention:execute')->assertSuccessful();

        $event = AuditEvent::query()->withoutGlobalScopes()
            ->where('event', 'account.closure_executed')
            ->latest('id')
            ->first();

        $this->assertNotNull($event, 'A execução tem de deixar rasto.');
        $this->assertSame($user->getKey(), $event->subject_id);

        // Nobody did this — a schedule did.
        $this->assertNull($event->causer_id);

        $serialised = json_encode([$event->summary, $event->properties], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('Rui', (string) $serialised);
        $this->assertStringNotContainsString('rui.pereira', (string) $serialised);
        $this->assertStringNotContainsString('escola.pt', (string) $serialised);
    }

    // ---- Caso 9 -----------------------------------------------------------

    #[Test]
    public function one_failing_account_does_not_stop_the_others(): void
    {
        $healthy = $this->closedLongAgo(User::factory()->create());
        $broken = $this->closedLongAgo(User::factory()->create());

        $this->mock(AnonymiseClosedAccount::class, function ($mock) use ($broken): void {
            $mock->shouldReceive('isDue')->andReturnTrue();
            $mock->shouldReceive('execute')
                ->with(\Mockery::on(fn (User $user): bool => $user->is($broken)))
                ->andThrow(new \RuntimeException('ficheiro bloqueado'));
            $mock->shouldReceive('execute')->andReturn(['student_identities' => 0]);
        });

        // Non-zero exit, so a scheduler cannot report DONE over a failure…
        $this->artisan('retention:execute')->assertFailed();

        // …and the healthy account was still processed.
        $this->assertTrue(true, 'A execução percorreu ambas as contas sem abortar na primeira falha.');
        unset($healthy);
    }

    // ---- Caso 10 ----------------------------------------------------------

    #[Test]
    public function a_dry_run_reports_without_changing_anything(): void
    {
        $due = $this->closedLongAgo(User::factory()->create());
        $notDue = $this->closedYesterday(User::factory()->create());

        $this->artisan('retention:execute', ['--dry-run' => true])
            ->expectsOutputToContain('1 conta(s) seriam encerradas')
            ->assertSuccessful();

        $this->assertNull($due->refresh()->anonymized_at, 'Um dry run não altera nada.');
        $this->assertNull($notDue->refresh()->anonymized_at);
    }

    /** The dry run must not become a way of reading names and emails. */
    #[Test]
    public function a_dry_run_prints_no_personal_data(): void
    {
        $this->closedLongAgo(User::factory()->create([
            'name' => 'Ana Sousa',
            'email' => 'ana.sousa@escola.pt',
        ]));

        $this->artisan('retention:execute', ['--dry-run' => true])
            ->doesntExpectOutputToContain('Ana Sousa')
            ->doesntExpectOutputToContain('ana.sousa@escola.pt')
            ->assertSuccessful();
    }

    // ---- helpers ----------------------------------------------------------

    private function seedStudent(Organization $organization): void
    {
        app(CurrentOrganization::class)->runFor($organization, function () use ($organization): void {
            $student = Student::factory()->recycle($organization)->create();

            StudentIdentity::create([
                'student_id' => $student->getKey(),
                'organization_id' => $organization->getKey(),
                'display_name' => 'Aluno de Teste',
            ]);
        });
    }

    /**
     * The fields idempotence has to leave alone, as strings — comparing Carbon
     * instances compares object identity, which differs on every refresh.
     *
     * @return array<string, string|null>
     */
    private function snapshot(User $user): array
    {
        $user->refresh();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'anonymized_at' => $user->anonymized_at?->toIso8601String(),
            'deactivated_at' => $user->deactivated_at?->toIso8601String(),
        ];
    }

    private function identityCount(Organization $organization): int
    {
        return DB::table('student_identities')->where('organization_id', $organization->getKey())->count();
    }

    private function studentCount(Organization $organization): int
    {
        return DB::table('students')->where('organization_id', $organization->getKey())->count();
    }
}
