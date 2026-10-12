<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Usage\SetUsageClassification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SetUsageClassificationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\Usage\UsageClassification;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Classificar uma organização para as métricas de utilização. A rota passa por
 * `usage-insights`. Não altera nada na conta e é independente da marca
 * comercial «conta de teste».
 */
class AdminUsageClassificationController extends Controller
{
    public function update(SetUsageClassificationRequest $request, Organization $organization, SetUsageClassification $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();
        $validated = $request->validated();

        $action->set(
            $organization,
            $operator,
            UsageClassification::from($validated['classification']),
            $validated['note'] ?? null,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Classificação guardada.']);

        return back();
    }
}
