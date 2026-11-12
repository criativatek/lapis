<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\SetOrganizationDefaultScale;
use App\Http\Requests\ScaleMentionsRequest;
use App\Models\AssessmentProfile;
use App\Models\Scale;
use App\Services\Assessment\DeriveCustomScale;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * «Personalizar escala»: os níveis da escola (nomes, cortes, Inovar e fronteira
 * positiva), por perfil. Cria (ou atualiza, enquanto não congelada) uma escala
 * derivada da do sistema — ver DeriveCustomScale. A escala do sistema nunca é
 * tocada.
 */
class ScaleMentionsController extends Controller
{
    public function __construct(
        protected DeriveCustomScale $derive,
        protected SetOrganizationDefaultScale $organizationDefault,
        protected AuditLog $audit,
    ) {}

    public function store(ScaleMentionsRequest $request, Scale $scale): RedirectResponse
    {
        Gate::authorize('create', Scale::class);
        // A escala da organização mexe em configuração de toda a organização:
        // só quem gere os perfis dela decide (a mesma regra do resto desta ação).
        Gate::authorize('create', AssessmentProfile::class);

        $derived = $this->derive->for(
            $scale,
            $request->levels(),
            $request->firstPositiveLevel(),
            (string) $request->validated('profile_name'),
            $request->scaleName(),
        );

        $isOrganizationDefault = $this->organizationDefault->apply($scale, $derived, $request->usesAsOrganizationDefault());

        $this->audit->record(
            'scale.mentions_customised',
            $derived,
            $request->user(),
            "Escala «{$derived->name}» definida pelo utilizador.",
            [
                'scale_id' => $derived->id,
                'derived_from_scale_id' => $derived->derived_from_scale_id,
                'levels' => $request->levels(),
                'first_positive_level' => $request->firstPositiveLevel(),
                'organization_default' => $isOrganizationDefault,
            ],
        );

        return back()->with('derivedScaleId', $derived->id);
    }
}
