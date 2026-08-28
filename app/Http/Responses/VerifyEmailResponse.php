<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

/**
 * Depois de confirmar o email, também.
 *
 * É AQUI QUE A INTENÇÃO DE QUEM SE REGISTA TEM DE SER LIDA, e não no registo:
 * quem acaba de criar conta ainda não verificou o email, é empurrado para o
 * aviso de verificação, e só volta a esta aplicação depois de clicar no link
 * que lhe chegou por correio — muitas vezes noutro dispositivo e meia hora
 * depois. Consumir o destino no registo deitá-lo-ia fora antes de servir para
 * alguma coisa.
 */
class VerifyEmailResponse implements VerifyEmailResponseContract
{
    public function toResponse($request): RedirectResponse|JsonResponse
    {
        /** @var Request $request */
        return $request->wantsJson()
            ? new JsonResponse('', 202)
            : redirect()->intended(config('fortify.home').'?verified=1');
    }
}
