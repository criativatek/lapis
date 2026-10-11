<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /admin/usage. Ser administrador da plataforma não chega: é preciso a
 * autorização própria `can_view_usage_insights`, que não é concedida por omissão.
 * Verificado no servidor em todas as rotas; esconder o menu é apresentação.
 */
class EnsureUsageInsightsAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canViewUsageInsights() === true, 403, 'Acesso reservado a pessoal autorizado.');

        return $next($request);
    }
}
