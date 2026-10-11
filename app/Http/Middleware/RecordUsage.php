<?php

namespace App\Http\Middleware;

use App\Support\Usage\UsageFailureCode;
use App\Support\Usage\UsageFeature;
use App\Support\Usage\UsageRecorder;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * `usage:<feature>` — regista uma consulta deliberada ou uma falha técnica numa
 * funcionalidade (docs/usage-insights.md). As ações concluídas não passam por
 * aqui: são registadas pelos controladores, depois de a operação ter sucesso.
 *
 * O MAIS INTERIOR GANHA. Cada instância escreve a sua funcionalidade no pedido
 * antes de `$next`; a rota (mais interior) escreve por cima do grupo. Na saída
 * a instância mais interior é a primeira a correr, regista com o que lá está e
 * marca `usage.handled`; as exteriores saem sem registar. Só há uma linha.
 *
 * Nunca impede a resposta: tudo o que é leitura da resposta está num
 * `try/catch (Throwable)`.
 */
class RecordUsage
{
    public function __construct(private readonly UsageRecorder $recorder) {}

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $request->attributes->set('usage.feature', $feature);

        $response = $next($request);

        $this->record($request, $response);

        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        try {
            if ($request->attributes->get('usage.handled') === true) {
                return;
            }

            $request->attributes->set('usage.handled', true);

            $featureName = $request->attributes->get('usage.feature');
            $feature = is_string($featureName) ? UsageFeature::tryFrom($featureName) : null;

            if ($feature === null) {
                Log::warning('usage.unknown_feature');

                return;
            }

            $status = $response->getStatusCode();

            if ($status >= 500) {
                $exception = property_exists($response, 'exception') ? $response->exception : null;

                if ($exception instanceof Throwable && ! $exception instanceof HttpExceptionInterface) {
                    $this->recorder->failure(
                        $feature,
                        UsageFailureCode::fromThrowable($exception),
                        $request->route()?->getName() ?? 'unnamed',
                    );
                }

                return;
            }

            if ($this->isDeliberateView($request, $response)) {
                $this->recorder->view($feature);
            }
        } catch (Throwable) {
            // A medição nunca pode estragar a resposta ao professor.
        }
    }

    private function isDeliberateView(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $response->getStatusCode() !== 200) {
            return false;
        }

        $isInertia = $request->headers->has('X-Inertia');
        $isHtml = str_starts_with((string) $response->headers->get('Content-Type'), 'text/html');

        if (! $isInertia && ! $isHtml) {
            return false;
        }

        // Recarregamentos parciais não são uma nova consulta.
        foreach (['X-Inertia-Partial-Component', 'X-Inertia-Partial-Data', 'X-Inertia-Partial-Except'] as $header) {
            if ($request->headers->has($header)) {
                return false;
            }
        }

        return ! $this->isPrefetch($request);
    }

    /**
     * O `@inertiajs/core` v3 marca os pré-carregamentos com `Purpose: prefetch`;
     * os outros três são os cabeçalhos que os navegadores usam para o mesmo.
     */
    private function isPrefetch(Request $request): bool
    {
        return strtolower((string) $request->headers->get('Purpose')) === 'prefetch'
            || str_contains(strtolower((string) $request->headers->get('Sec-Purpose')), 'prefetch')
            || strtolower((string) $request->headers->get('X-Moz')) === 'prefetch';
    }
}
