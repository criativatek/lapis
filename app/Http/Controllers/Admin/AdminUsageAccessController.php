<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Conceder ou retirar a autorização de ver as métricas de utilização ao dono de
 * uma organização. A rota passa por `usage-insights`: só quem já a tem a pode
 * dar. O alvo tem de ser administrador da plataforma, e ninguém retira a sua
 * própria autorização por aqui (há um comando para isso).
 */
class AdminUsageAccessController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function update(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate(['granted' => ['required', 'boolean']]);
        $grant = (bool) $validated['granted'];

        $operator = $request->user();
        $target = $organization->owner;

        if ($target === null || ! $target->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'granted' => 'Só um administrador da plataforma pode receber esta autorização.',
            ]);
        }

        if (! $grant && $operator !== null && $target->is($operator)) {
            throw ValidationException::withMessages([
                'granted' => 'Use o comando para retirar a sua própria autorização.',
            ]);
        }

        if ($target->can_view_usage_insights !== $grant) {
            $target->forceFill(['can_view_usage_insights' => $grant])->save();

            $this->audit->recordPlatform(
                $grant ? 'admin.usage_insights_granted' : 'admin.usage_insights_revoked',
                $operator,
                $grant
                    ? 'Concedida a autorização de ver as métricas de utilização.'
                    : 'Retirada a autorização de ver as métricas de utilização.',
                ['target_user_id' => $target->id],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $grant ? 'Autorização concedida.' : 'Autorização retirada.',
        ]);

        return back();
    }

    /**
     * Conceder ou retirar a autorização — SEPARADA — de ligar e desligar a
     * recolha. A rota passa por `usage-collection-control`: quem só consulta o
     * painel não chega aqui, por isso não se pode atribuir a si próprio. O alvo
     * tem de ser administrador da plataforma, e ninguém mexe na sua própria
     * autorização por aqui (nem para a dar nem para a tirar: há um comando).
     */
    public function updateCollectionControl(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate(['granted' => ['required', 'boolean']]);
        $grant = (bool) $validated['granted'];

        $operator = $request->user();
        $target = $organization->owner;

        if ($target === null || ! $target->isPlatformAdmin()) {
            throw ValidationException::withMessages([
                'granted' => 'Só um administrador da plataforma pode receber esta autorização.',
            ]);
        }

        if ($operator !== null && $target->is($operator)) {
            throw ValidationException::withMessages([
                'granted' => 'Ninguém altera a sua própria autorização de controlar a recolha por aqui.',
            ]);
        }

        if ($target->can_control_usage_collection !== $grant) {
            $target->forceFill(['can_control_usage_collection' => $grant])->save();

            $this->audit->recordPlatform(
                $grant ? 'admin.usage_collection_control_granted' : 'admin.usage_collection_control_revoked',
                $operator,
                $grant
                    ? 'Concedida a autorização de ligar e desligar a recolha de métricas de utilização.'
                    : 'Retirada a autorização de ligar e desligar a recolha de métricas de utilização.',
                ['target_user_id' => $target->id],
            );
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $grant ? 'Autorização concedida.' : 'Autorização retirada.',
        ]);

        return back();
    }
}
