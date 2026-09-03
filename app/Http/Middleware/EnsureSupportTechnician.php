<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Guards technical access to a teacher's account as a second platform gate. */
class EnsureSupportTechnician
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user()?->isSupportTechnician()) {
            abort(403, __('Acesso técnico reservado a pessoal especificamente autorizado.'));
        }

        return $next($request);
    }
}
