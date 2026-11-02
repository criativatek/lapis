<?php

namespace Tests\Feature\Usage\Insights;

use App\Models\User;
use App\Services\Usage\Insights\OnlineNow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * «Ligados agora» lê só a tabela `sessions`: quem tem uma sessão viva, uma
 * entrada por pessoa, sem acesso técnico e sem quem ficou fora do painel.
 */
class OnlineNowTest extends InsightsTestCase
{
    /**
     * Uma sessão como a aplicação a grava: base64 do payload no formato de
     * `session.serialization` — JSON por omissão (`config/session.php`), que é
     * o que está em produção; `serialize()` só se a configuração o pedir.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sessionOf(?User $user, int $minutesAgo, array $payload = []): void
    {
        $data = $payload + ['_token' => 'x'];

        DB::table('sessions')->insert([
            'id' => 'sessao-'.bin2hex(random_bytes(6)),
            'user_id' => $user?->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => base64_encode(config('session.serialization') === 'json' ? (string) json_encode($data) : serialize($data)),
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    /** @return array<string, mixed> */
    private function online(): array
    {
        return app(OnlineNow::class)->build(CarbonImmutable::now());
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_column($this->online()['people'], 'name');
    }

    #[Test]
    public function the_shape_carries_the_windows_and_the_hour_of_lisbon(): void
    {
        $online = $this->online();

        $this->assertSame('10:00', $online['as_of']);
        $this->assertSame(15, $online['window_minutes']);
        $this->assertSame((int) config('session.lifetime'), $online['session_minutes']);
        $this->assertSame(0, $online['active_count']);
        $this->assertSame([], $online['people']);
    }

    #[Test]
    public function an_active_and_a_merely_live_session_are_told_apart(): void
    {
        $this->sessionOf($this->person('Ativa'), 3);
        $this->sessionOf($this->person('Viva'), 60);

        $online = $this->online();

        $this->assertSame(1, $online['active_count']);
        $this->assertSame(['Ativa', 'Viva'], array_column($online['people'], 'name'));
        $this->assertSame([true, false], array_column($online['people'], 'active'));
        $this->assertSame([3, 60], array_column($online['people'], 'minutes_ago'));
    }

    #[Test]
    public function the_edge_of_the_active_window_is_exclusive_and_a_just_now_session_is_zero_minutes(): void
    {
        $this->sessionOf($this->person('Agora'), 0);
        $this->sessionOf($this->person('No Limite'), 15);

        $people = collect($this->online()['people'])->keyBy('name');

        $this->assertSame(0, $people['Agora']['minutes_ago']);
        $this->assertTrue($people['Agora']['active']);
        $this->assertFalse($people['No Limite']['active']);
    }

    #[Test]
    public function an_expired_session_is_left_out(): void
    {
        $this->sessionOf($this->person('Expirada'), 121);
        $this->sessionOf($this->person('Quase'), 119);

        $this->assertSame(['Quase'], $this->names());
    }

    #[Test]
    public function people_who_opted_out_are_closing_or_anonymized_are_left_out(): void
    {
        $optedOut = $this->person('Oposição');
        $closing = $this->person('Encerramento');
        $anonymized = $this->person('Anonimizada');
        $normal = $this->person('Normal');

        DB::table('users')->where('id', $optedOut->id)->update(['usage_opted_out_at' => now()]);
        DB::table('users')->where('id', $closing->id)->update(['closure_requested_at' => now()]);
        DB::table('users')->where('id', $anonymized->id)->update(['anonymized_at' => now()]);

        foreach ([$optedOut, $closing, $anonymized, $normal] as $user) {
            $this->sessionOf($user, 2);
        }

        $this->assertSame(['Normal'], $this->names());
    }

    #[Test]
    public function an_impersonated_session_does_not_make_the_person_online_but_the_technicians_own_does(): void
    {
        $technician = $this->platformAdmin(name: 'Técnica');
        $teacher = $this->person('Professora');

        // A técnica a trabalhar na conta da professora: a sessão é da professora, mas é da técnica.
        $this->sessionOf($teacher, 1, ['impersonator_id' => $technician->id]);
        $this->sessionOf($technician, 5);

        $this->assertSame(['Técnica'], $this->names());

        // Se a professora tiver também a sua própria sessão, conta.
        $this->sessionOf($teacher, 20);

        $people = collect($this->online()['people'])->keyBy('name');
        $this->assertSame(['Técnica', 'Professora'], $this->names());
        $this->assertSame(20, $people['Professora']['minutes_ago']);
    }

    #[Test]
    public function the_application_stores_sessions_as_json_and_that_is_what_is_read(): void
    {
        // A 0.189.0 lia `serialize()` e, em produção (JSON), nunca via a impersonação.
        $this->assertSame('json', config('session.serialization'));

        $teacher = $this->person('Impersonada');
        DB::table('sessions')->insert([
            'id' => 'sessao-json',
            'user_id' => $teacher->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => base64_encode('{"_token":"x","impersonator_id":7}'),
            'last_activity' => now()->subMinutes(2)->getTimestamp(),
        ]);

        $this->assertSame([], $this->names());
    }

    #[Test]
    public function a_php_serialized_payload_is_read_when_the_configuration_asks_for_it(): void
    {
        config(['session.serialization' => 'php']);

        $teacher = $this->person('Impersonada PHP');
        $this->sessionOf($teacher, 1, ['impersonator_id' => 7]);
        $this->sessionOf($this->person('Normal PHP'), 2);

        $this->assertSame(['Normal PHP'], $this->names());
    }

    #[Test]
    public function an_undecodable_payload_counts_as_a_normal_session(): void
    {
        $teacher = $this->person('Payload Estranho');
        DB::table('sessions')->insert([
            'id' => 'sessao-estranha',
            'user_id' => $teacher->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'teste',
            'payload' => 'isto não é base64 nem serialize',
            'last_activity' => now()->subMinutes(4)->getTimestamp(),
        ]);

        $this->assertSame(['Payload Estranho'], $this->names());
    }

    #[Test]
    public function internal_staff_are_flagged(): void
    {
        $support = $this->person('Suporte');
        $support->forceFill(['is_support_technician' => true])->save();
        $this->sessionOf($support, 1);
        $this->sessionOf($this->platformAdmin(name: 'Admin'), 2);
        $this->sessionOf($this->person('Professor'), 3);

        $people = collect($this->online()['people'])->keyBy('name');

        $this->assertTrue($people['Suporte']['internal']);
        $this->assertTrue($people['Admin']['internal']);
        $this->assertFalse($people['Professor']['internal']);
    }

    #[Test]
    public function several_sessions_of_the_same_person_are_one_entry_with_the_latest_activity(): void
    {
        $teacher = $this->person('Duas Sessões');
        $this->sessionOf($teacher, 40);
        $this->sessionOf($teacher, 6);
        $this->sessionOf($teacher, 90);

        $online = $this->online();

        $this->assertCount(1, $online['people']);
        $this->assertSame(6, $online['people'][0]['minutes_ago']);
        $this->assertSame(1, $online['active_count']);
    }

    #[Test]
    public function the_most_recent_activity_comes_first(): void
    {
        $this->sessionOf($this->person('Terceira'), 50);
        $this->sessionOf($this->person('Primeira'), 1);
        $this->sessionOf($this->person('Segunda'), 20);

        $this->assertSame(['Primeira', 'Segunda', 'Terceira'], $this->names());
    }

    #[Test]
    public function a_session_without_a_user_is_ignored(): void
    {
        $this->sessionOf(null, 1);

        $this->assertSame([], $this->online()['people']);
    }

    #[Test]
    public function the_ulid_is_there_only_when_the_person_has_an_aggregate_state(): void
    {
        $withState = $this->person('Com Estado');
        $withoutState = $this->person('Sem Estado');
        $state = $this->state($withState);
        $this->sessionOf($withState, 1);
        $this->sessionOf($withoutState, 2);

        $people = collect($this->online()['people'])->keyBy('name');

        $this->assertSame($state->refresh()->ulid, $people['Com Estado']['ulid']);
        $this->assertNull($people['Sem Estado']['ulid']);
    }

    #[Test]
    public function the_overview_carries_it_regardless_of_the_filters(): void
    {
        $this->sessionOf($this->person('Ligada'), 2);

        $default = $this->props('admin.usage.overview')['online'];
        $narrowed = $this->props('admin.usage.overview', ['segment' => 'test', 'plan' => 'base'])['online'];

        $this->assertSame(['Ligada'], array_column($default['people'], 'name'));
        $this->assertSame($default, $narrowed);
    }
}
