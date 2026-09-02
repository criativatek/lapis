<?php

namespace App\Actions\Support;

use App\Models\SupportRequest;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Support\IssueGithubPayload;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Abrir um issue num rastreador externo, por acto de um operador.
 *
 * NUNCA AUTOMÁTICO. O sistema equivalente do Plaanly despeja lá cada reporte
 * assim que ele chega; aqui é um botão que uma pessoa carrega depois de ler o
 * pedido, e o corpo leva um texto que essa pessoa escreveu. A diferença é toda:
 * uma transferência para fora decidida caso a caso é uma decisão; a mesma
 * transferência feita por omissão é uma política que ninguém aprovou.
 *
 * SÍNCRONO, DEPOIS DO COMMIT. Não há worker de filas em produção — a ADR-0011
 * §5 documenta isso e o `SupportNotifier` já vive com essa restrição. Um job
 * `ShouldQueue` seria escrito na tabela `jobs` e **nunca corria**, e o botão
 * ficaria a dizer que exportou.
 *
 * DESLIGADO SEM CONFIGURAÇÃO, e é a condição para existir: sem `token` e sem
 * `repository`, `isConfigured()` devolve false, o botão não aparece e este
 * método recusa-se a correr. Ver a ADR-0013 antes de ligar.
 */
class ExportIssueToGithub
{
    public function __construct(protected AuditLog $audit) {}

    public function isConfigured(): bool
    {
        return $this->token() !== '' && $this->repository() !== '';
    }

    /**
     * @throws RuntimeException quando não está configurado, já foi exportado,
     *                          ou o GitHub recusou
     */
    public function export(SupportRequest $request, User $operator, string $notes): SupportRequest
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('A exportação para o rastreador externo não está configurada.');
        }

        if ($request->github_issue_number !== null) {
            // Exportar duas vezes faria dois issues para o mesmo pedido, e o
            // segundo não teria como ser encontrado a partir daqui.
            throw new RuntimeException('Este pedido já foi exportado.');
        }

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(20)
                ->post($this->url('/issues'), [
                    'title' => IssueGithubPayload::title($request),
                    'body' => IssueGithubPayload::body($request, $notes),
                    'labels' => ['reporte'],
                ]);
        } catch (ConnectionException) {
            // Sem a mensagem: a excepção de ligação carrega o URL inteiro, e um
            // URL com token dentro é a única coisa aqui que não pode ir para um
            // log (§47 do CLAUDE.md).
            throw new RuntimeException('Não foi possível contactar o rastreador externo.');
        }

        if ($response->failed()) {
            // O estado, e nada mais: o corpo de erro do GitHub cita de volta o
            // pedido que foi feito.
            throw new RuntimeException('O rastreador externo respondeu '.$response->status().'.');
        }

        return DB::transaction(function () use ($request, $operator, $response): SupportRequest {
            $request->forceFill([
                'github_issue_number' => (int) $response->json('number'),
                'github_issue_url' => (string) $response->json('html_url'),
            ])->save();

            $this->audit->recordPlatform(
                'support.exported',
                $operator,
                'Pedido de suporte '.$request->reference.' exportado para o rastreador externo.',
                // O número do issue, e não o URL: o URL nomeia o repositório, e
                // o rasto não precisa de saber para onde foi para registar que
                // foi.
                ['reference' => $request->reference, 'issue' => (int) $response->json('number')],
            );

            return $request->fresh();
        });
    }

    /**
     * Apaga o conteúdo do issue exportado, aos 24 meses.
     *
     * O QUE ISTO CONSEGUE E O QUE NÃO CONSEGUE. A API do GitHub não permite
     * apagar um issue por REST — permite reescrevê-lo e fechá-lo, e é isso que
     * se faz. **O GitHub guarda o histórico de edições de um corpo de issue**,
     * visível a quem tem acesso ao repositório, portanto isto reduz o que lá
     * está mas não o elimina como a anonimização elimina no Lapispro.
     *
     * Essa diferença é a razão pela qual exportar é uma decisão e não um passo:
     * é a única parte deste domínio onde a promessa de eliminação passa a ser
     * «o melhor que se consegue» em vez de «foi apagado».
     */
    public function redact(SupportRequest $request): bool
    {
        if (! $this->isConfigured() || $request->github_issue_number === null) {
            return false;
        }

        try {
            $response = Http::withToken($this->token())
                ->acceptJson()
                ->timeout(20)
                ->patch($this->url('/issues/'.$request->github_issue_number), [
                    'title' => '[expurgado]',
                    'body' => 'O pedido de suporte que deu origem a este issue foi eliminado ao fim do prazo de conservação.',
                    'state' => 'closed',
                ]);

            return $response->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    protected function token(): string
    {
        return (string) config('lapis.support.github.token', '');
    }

    protected function repository(): string
    {
        return (string) config('lapis.support.github.repository', '');
    }

    protected function url(string $path): string
    {
        return config('lapis.support.github.api_url').'/repos/'.$this->repository().$path;
    }
}
