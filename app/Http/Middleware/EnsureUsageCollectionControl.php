<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda o que mexe no CONTROLO da recolha de métricas de utilização (hoje, a
 * concessão desta mesma autorização no backoffice). Consultar o painel
 * (`usage-insights`) não chega: é preciso ser administrador da plataforma E ter
 * `can_control_usage_collection`, que não é concedida por omissão.
 */
class EnsureUsageCollectionControl
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->canControlUsageCollection() === true, 403, 'Reservado a quem pode controlar a recolha de métricas.');

        return $next($request);
    }
}
