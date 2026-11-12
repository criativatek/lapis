<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScaleMentionsRequest;
use App\Models\Scale;
use App\Services\Assessment\DeriveScaleWithLabels;
use App\Services\Audit\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * «Personalizar menções»: as cinco menções com os nomes da escola, por perfil.
 * Cria (ou atualiza, enquanto não congelada) uma escala derivada da «Escala 1
 * a 5» — ver DeriveScaleWithLabels. A escala do sistema nunca é tocada.
 */
class ScaleMentionsController extends Controller
{
    public function __construct(protected DeriveScaleWithLabels $derive, protected AuditLog $audit) {}

    public function store(ScaleMentionsRequest $request, Scale $scale): RedirectResponse
    {
        Gate::authorize('create', Scale::class);

        $derived = $this->derive->for($scale, $request->labels(), (string) $request->validated('profile_name'));

        $this->audit->record(
            'scale.mentions_customised',
            $derived,
            $request->user(),
            "Menções da escala «{$derived->name}» definidas pelo utilizador.",
            [
                'scale_id' => $derived->id,
                'derived_from_scale_id' => $derived->derived_from_scale_id,
                'labels' => $request->labels(),
            ],
        );

        return back()->with('derivedScaleId', $derived->id);
    }
}
