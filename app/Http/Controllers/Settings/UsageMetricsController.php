<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Usage\SetUsageOptOut;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * «Não registar a minha utilização» (docs/usage-insights.md, «Direito de
 * oposição»). Atua sempre sobre a pessoa autenticada — não há {user} na rota —
 * e nunca durante um acesso de suporte: a decisão é só da própria pessoa.
 */
class UsageMetricsController extends Controller
{
    use RefusesDuringImpersonation;

    public function update(Request $request, SetUsageOptOut $optOut): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $request->validate([
            'opted_out' => ['required', 'boolean'],
        ]);

        if ($request->boolean('opted_out')) {
            $optOut->optOut($request->user());
            $message = 'A sua utilização deixou de ser registada e as métricas associadas à sua conta foram eliminadas.';
        } else {
            $optOut->optIn($request->user());
            $message = 'A sua utilização volta a ser registada a partir de agora.';
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('profile.edit');
    }
}
