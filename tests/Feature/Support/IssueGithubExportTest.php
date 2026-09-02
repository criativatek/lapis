<?php

namespace Tests\Feature\Support;

use App\Actions\Support\AnonymiseSupportRequest;
use App\Actions\Support\ExportIssueToGithub;
use App\Actions\Support\OpenSupportRequest;
use App\Models\SupportCategory;
use App\Models\SupportRequest;
use App\Models\User;
use App\Support\Support\IssueGithubPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * O que atravessa a fronteira, e o que fica de dentro dela.
 *
 * O TESTE CENTRAL É O DA LISTA DE PERMISSÕES. Compara as chaves que o payload
 * produz com uma lista literal escrita à mão: uma lista de exclusões — «tudo
 * menos o `subject`» — vaza no dia em que alguém acrescenta uma coluna e não se
 * lembra dela, e esta quebra nesse mesmo dia. É a diferença entre uma garantia
 * verificável e uma intenção.
 */
class IssueGithubExportTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        config([
            'lapis.support.github.token' => 'ficticio',
            'lapis.support.github.repository' => 'exemplo/repositorio',
            'lapis.support.github.api_url' => 'https://api.github.invalid',
        ]);
    }

    private function request(): SupportRequest
    {
        $user = User::factory()->create();

        return app(OpenSupportRequest::class)->open([
            'category' => SupportCategory::Assessment->value,
            'subject' => 'SENTINELA-RESUMO',
            'description' => 'SENTINELA-DESCRICAO',
            'technical_route' => '/classes/:id',
            'client_context' => [
                'page_component' => 'classes/Show',
                'environment' => ['browser' => 'chrome', 'browser_major' => 151, 'platform' => 'windows'],
                'console' => [['level' => 'log', 'text' => 'SENTINELA-CONSOLA']],
                'network' => [['method' => 'GET', 'route' => '/classes/:id', 'status' => 500]],
                'errors' => [['name' => 'TypeError', 'message' => 'SENTINELA-ERRO', 'where' => 'app-abc.js:1']],
            ],
        ], $user, $user->personalOrganization());
    }

    #[Test]
    public function the_payload_carries_exactly_the_keys_the_allow_list_names(): void
    {
        $fields = IssueGithubPayload::fields($this->request());

        // Igualdade, não «contém»: uma chave nova aparece aqui como falha.
        $this->assertSame(IssueGithubPayload::ALLOWED, array_keys($fields));
    }

    #[Test]
    public function nothing_a_person_wrote_crosses_the_border(): void
    {
        $request = $this->request();

        $body = IssueGithubPayload::body($request, 'A nota que o operador escreveu.');
        $title = IssueGithubPayload::title($request);

        foreach (['SENTINELA-RESUMO', 'SENTINELA-DESCRICAO', 'SENTINELA-CONSOLA'] as $sentinela) {
            $this->assertStringNotContainsString($sentinela, $body, "«{$sentinela}» atravessou a fronteira.");
            $this->assertStringNotContainsString($sentinela, $title);
        }

        // A consola é a única parte do contexto que leva texto que a aplicação
        // não compôs — e por isso é a única que não sai.
        $this->assertStringNotContainsString('SENTINELA-ERRO', $body);

        // O que SAI, e tem de sair, ou a exportação não serve para nada.
        $this->assertStringContainsString('A nota que o operador escreveu.', $body);
        $this->assertStringContainsString('/classes/:id', $body);
        $this->assertStringContainsString('TypeError', $body);
        $this->assertStringContainsString('500 GET', $body);
    }

    #[Test]
    public function without_configuration_it_refuses_to_run(): void
    {
        // A predefinição não é prudência, é a condição para isto existir.
        config(['lapis.support.github.token' => null, 'lapis.support.github.repository' => null]);

        $export = app(ExportIssueToGithub::class);

        $this->assertFalse($export->isConfigured());

        $this->expectException(RuntimeException::class);
        $export->export($this->request(), User::factory()->create(), 'nota');
    }

    #[Test]
    public function a_successful_export_records_the_issue(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['number' => 42, 'html_url' => 'https://github.invalid/exemplo/repositorio/issues/42'], 201)]);

        $exported = app(ExportIssueToGithub::class)->export($this->request(), User::factory()->create(), 'nota');

        $this->assertSame(42, $exported->github_issue_number);
        $this->assertStringContainsString('/issues/42', $exported->github_issue_url);
    }

    #[Test]
    public function exporting_twice_is_refused(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['number' => 42, 'html_url' => 'https://github.invalid/x/y/issues/42'], 201)]);

        $operator = User::factory()->create();
        $exported = app(ExportIssueToGithub::class)->export($this->request(), $operator, 'nota');

        // Dois issues para o mesmo pedido, e o segundo sem forma de ser
        // encontrado a partir daqui.
        $this->expectException(RuntimeException::class);
        app(ExportIssueToGithub::class)->export($exported, $operator, 'outra nota');
    }

    #[Test]
    public function a_refusal_from_the_tracker_never_leaks_the_url(): void
    {
        $this->configure();
        Http::fake(['*' => Http::response(['message' => 'Bad credentials'], 401)]);

        try {
            app(ExportIssueToGithub::class)->export($this->request(), User::factory()->create(), 'nota');
            $this->fail('Devia ter falhado.');
        } catch (RuntimeException $exception) {
            // O estado, e nada mais: um URL da API leva o repositório, e uma
            // excepção de ligação levaria o token.
            $this->assertStringContainsString('401', $exception->getMessage());
            $this->assertStringNotContainsString('github.invalid', $exception->getMessage());
            $this->assertStringNotContainsString('ficticio', $exception->getMessage());
        }
    }

    #[Test]
    public function anonymisation_redacts_the_issue_and_forgets_the_pointer(): void
    {
        $this->configure();
        Http::fake([
            '*/issues/42' => Http::response([], 200),
            '*' => Http::response(['number' => 42, 'html_url' => 'https://github.invalid/x/y/issues/42'], 201),
        ]);

        $operator = User::factory()->create();
        $exported = app(ExportIssueToGithub::class)->export($this->request(), $operator, 'nota');

        app(AnonymiseSupportRequest::class)->execute($exported->fresh());

        // Um apontador para conteúdo é conteúdo.
        $anonymised = $exported->fresh();
        $this->assertNull($anonymised->github_issue_number);
        $this->assertNull($anonymised->github_issue_url);

        // E lá fora, o melhor que a API permite: reescrever e fechar.
        Http::assertSent(fn ($pedido) => $pedido->method() === 'PATCH'
            && str_contains($pedido->url(), '/issues/42')
            && $pedido['title'] === '[expurgado]');
    }
}
