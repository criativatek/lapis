<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Membership/governance actions taken ON BEHALF of the person being
 * impersonated — support has no business leaving, removing, transferring,
 * or reassigning anything that will look, to everyone else in the school,
 * like the impersonated owner or member did it themselves.
 */
trait RefusesDuringImpersonation
{
    protected function refuseDuringImpersonation(Request $request): void
    {
        if ($request->session()->has('impersonator_id')) {
            abort(403, __('Não é possível realizar esta ação durante uma sessão de suporte.'));
        }
    }
}
