<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fechar o aviso de que a Política de Privacidade mudou.
 *
 * O MESMO DESENHO DE `OnboardingController`, e pela mesma razão: o único
 * estado que isto persiste é um carimbo no próprio utilizador, e não há
 * parâmetro `{user}` em rota nenhuma — age sempre sobre quem tem sessão
 * iniciada, nunca sobre um alvo vindo do URL.
 *
 * FECHAR NÃO É ACEITAR. A Política informa; não se aceita, e por isso não há
 * aqui consentimento a registar nem nada que se possa retirar. Não fechar o
 * aviso também não impede coisa nenhuma — ele não bloqueia a aplicação.
 */
class PrivacyNoticeController extends Controller
{
    public function dismiss(Request $request): RedirectResponse
    {
        $request->user()->privacy_notice_dismissed_at = now();
        $request->user()->save();

        return back();
    }
}
