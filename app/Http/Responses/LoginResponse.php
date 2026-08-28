<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * Depois de entrar, a pessoa vai para onde ia — não para o painel.
 *
 * O FORTIFY MANDAVA TODA A GENTE PARA `/dashboard`, sempre. Quem carregasse em
 * «Escolher Pro» na página pública, entrasse, e aterrasse num painel com
 * dezoito entradas de menu tinha de descobrir sozinho onde é que se subscreve —
 * e a intenção que o trouxe ali perdia-se no caminho. Era o passo em que se
 * desiste.
 *
 * O `Authenticate` do Laravel já guarda o destino em sessão quando manda um
 * visitante para o login; só faltava alguém lê-lo. `intended()` consome-o, por
 * isso não fica a apontar para lá na sessão seguinte.
 */
class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        /** @var Request $request */
        return $request->wantsJson()
            ? new JsonResponse('', 204)
            : redirect()->intended(config('fortify.home'));
    }
}
