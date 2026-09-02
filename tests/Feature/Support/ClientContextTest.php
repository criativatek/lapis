<?php

namespace Tests\Feature\Support;

use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * O contexto técnico que viaja com um reporte.
 *
 * É UMA LISTA FECHADA, E É ESSA A DEFESA. Nenhuma chave aceita texto livre de
 * forma arbitrária: o que não está declarado é descartado na validação, não
 * sanitizado depois. Uma carga que o esquema não reconhece não tem de ser
 * limpa — tem de não entrar.
 *
 * O CONVIDADO NÃO ENVIA NADA DISTO. `/contacto` é público e a avaliação de
 * interesse legítimo escrita diz que dali não se recolhe mais do que os cinco
 * campos do formulário. O contexto existe só no canal autenticado, onde a
 * pessoa tem conta, tem fila e vê o que enviou.
 */
class ClientContextTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'page_component' => 'classes/Show',
            'environment' => [
                'browser' => 'chrome',
                'browser_major' => 151,
                'platform' => 'windows',
                'viewport' => '1440x900',
                'language' => 'pt-PT',
            ],
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'classes_students',
            'subject' => 'A grelha não abre',
            'description' => 'Fica em branco.',
            'client_context' => $this->context(),
        ], $overrides);
    }

    #[Test]
    public function the_declared_context_is_stored_as_it_was_sent(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload())->assertRedirect();

        $stored = SupportRequest::query()->sole()->client_context;

        $this->assertSame('classes/Show', $stored['page_component']);
        $this->assertSame('chrome', $stored['environment']['browser']);
        $this->assertSame(151, $stored['environment']['browser_major']);
    }

    #[Test]
    public function a_key_the_schema_does_not_know_never_reaches_the_column(): void
    {
        // O caso que interessa. Um cliente pode enviar o que quiser; o que o
        // servidor guarda é só o que declarou aceitar.
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload([
            'client_context' => $this->context([
                'cookies' => 'sessao=abc123',
                'local_storage' => ['aluno' => 'Marta Tomás'],
                'stack' => 'at Turma.vue:42',
            ]),
        ]))->assertRedirect();

        $stored = SupportRequest::query()->sole()->client_context;

        $this->assertArrayNotHasKey('cookies', $stored);
        $this->assertArrayNotHasKey('local_storage', $stored);
        $this->assertArrayNotHasKey('stack', $stored);
        $this->assertSame('classes/Show', $stored['page_component']);
    }

    #[Test]
    public function a_browser_outside_the_closed_list_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload([
            'client_context' => $this->context([
                'environment' => ['browser' => 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36'],
            ]),
        ]))->assertSessionHasErrors('client_context.environment.browser');

        $this->assertDatabaseCount('support_requests', 0);
    }

    #[Test]
    public function the_rings_are_stored_with_the_columns_they_declare(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload([
            'client_context' => $this->context([
                'console' => [['level' => 'error', 'text' => 'A grelha rebentou', 'at' => '2026-09-02T01:00:00.000Z']],
                'network' => [['method' => 'GET', 'route' => '/classes/:id', 'status' => 500, 'at' => '2026-09-02T01:00:00.000Z']],
                'errors' => [['name' => 'TypeError', 'message' => 'x is not a function', 'where' => 'app-abc.js:1', 'at' => '2026-09-02T01:00:00.000Z']],
            ]),
        ]))->assertRedirect();

        $stored = SupportRequest::query()->sole()->client_context;

        $this->assertSame('A grelha rebentou', $stored['console'][0]['text']);
        $this->assertSame(500, $stored['network'][0]['status']);
        $this->assertSame('TypeError', $stored['errors'][0]['name']);
    }

    #[Test]
    public function a_ring_longer_than_its_ceiling_is_refused(): void
    {
        // O tecto não é uma sugestão: um anel que crescesse com a sessão
        // passaria a ser um registo de tudo o que a pessoa fez, e isso é outra
        // coisa, com outra base legal.
        $this->actingAs(User::factory()->create());

        $console = array_fill(0, 101, ['level' => 'log', 'text' => 'x', 'at' => '2026-09-02T01:00:00.000Z']);

        $this->post('/support', $this->payload([
            'client_context' => $this->context(['console' => $console]),
        ]))->assertSessionHasErrors('client_context.console');

        $this->assertDatabaseCount('support_requests', 0);
    }

    #[Test]
    public function a_column_nobody_declared_is_dropped_from_inside_a_ring(): void
    {
        // A poda tem de descer até dentro das linhas. Uma chave a mais numa
        // entrada de rede é tão boa porta como uma chave a mais no topo.
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload([
            'client_context' => $this->context([
                'network' => [[
                    'method' => 'GET',
                    'route' => '/classes/:id',
                    'status' => 500,
                    'at' => '2026-09-02T01:00:00.000Z',
                    'url' => 'https://lapispro.com/classes/01M1CP8P9936KY71CD6GVSJV60?token=abc',
                    'headers' => ['Authorization' => 'Bearer segredo'],
                ]],
            ]),
        ]))->assertRedirect();

        $stored = SupportRequest::query()->sole()->client_context;

        $this->assertArrayNotHasKey('url', $stored['network'][0]);
        $this->assertArrayNotHasKey('headers', $stored['network'][0]);
        $this->assertStringNotContainsString('token', json_encode($stored));
        $this->assertStringNotContainsString('01M1CP8P', json_encode($stored));
    }

    #[Test]
    public function a_console_level_outside_the_closed_list_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload([
            'client_context' => $this->context([
                'console' => [['level' => 'trace', 'text' => 'x']],
            ]),
        ]))->assertSessionHasErrors('client_context.console.0.level');
    }

    #[Test]
    public function a_guest_sends_no_context_at_all(): void
    {
        $this->post('/contacto', [
            'requester_name' => 'Ana',
            'requester_email' => 'ana@exemplo.pt',
            'category' => 'access',
            'subject' => 'Não entro',
            'description' => 'A palavra-passe não é aceite.',
            'client_context' => $this->context(),
        ])->assertRedirect();

        $this->assertNull(SupportRequest::query()->sole()->client_context);
    }

    #[Test]
    public function a_request_without_context_is_still_a_valid_request(): void
    {
        // O widget não é o único caminho: o formulário de `/support/novo`
        // continua a existir e não recolhe nada disto.
        $this->actingAs(User::factory()->create());

        $this->post('/support', $this->payload(['client_context' => null]))->assertRedirect();

        $this->assertNull(SupportRequest::query()->sole()->client_context);
    }
}
