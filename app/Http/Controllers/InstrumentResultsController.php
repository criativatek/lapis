<?php

namespace App\Http\Controllers;

use App\Models\Instrument;
use App\Models\InstrumentStatus;
use App\Models\ResultsAnalysisNote;
use App\Models\User;
use App\Services\Assessment\Analysis\BuildResultsAnalysis;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The «Resultados» tab next to the correction grid (design spec §6): the
 * per-instrument statistical analysis, the printable report, and the
 * teacher's own observations. Reads only — no score, classification, or
 * profile is ever touched here, except by `updateNote()`, which writes to
 * `results_analysis_notes` alone.
 */
class InstrumentResultsController extends Controller
{
    public function __construct(protected BuildResultsAnalysis $builder) {}

    public function show(Instrument $instrument): Response|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        $props = $this->builder->forInstrument($instrument, Gate::allows('update', $instrument->schoolClass), includeIndividual: true);

        return Inertia::render('instruments/Results', $props);
    }

    public function report(Request $request, Instrument $instrument): Response|RedirectResponse
    {
        Gate::authorize('view', $instrument->schoolClass);

        if ($instrument->status === InstrumentStatus::Draft) {
            return to_route('instruments.edit', $instrument->ulid);
        }

        $includeIndividual = $request->boolean('individual');

        $props = $this->builder->forInstrument($instrument, Gate::allows('update', $instrument->schoolClass), includeIndividual: $includeIndividual);
        $props['include_individual'] = $includeIndividual;

        return Inertia::render('instruments/results/Print', $props);
    }

    public function updateNote(Request $request, Instrument $instrument): RedirectResponse
    {
        Gate::authorize('update', $instrument->schoolClass);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);

        $note = ResultsAnalysisNote::query()
            ->where('instrument_id', $instrument->getKey())
            ->where('context_kind', 'instrument')
            ->first();

        $storedVersion = $note === null ? 0 : $note->lock_version;

        if ($storedVersion !== $data['lock_version']) {
            return back()->withErrors([
                'body' => 'As observações foram alteradas noutra janela. Recarregue a página para ver a versão mais recente.',
            ]);
        }

        $user = $this->user();

        if ($note === null) {
            try {
                ResultsAnalysisNote::create([
                    'context_kind' => 'instrument',
                    'instrument_id' => $instrument->getKey(),
                    'body' => $data['body'] ?? null,
                    'lock_version' => 1,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two tabs both creating the first note at once: the read
                // above saw no row, but another request's INSERT won the
                // race. Refuse rather than 500 or silently overwrite — the
                // same «alteradas noutra janela» message a stale version
                // gets, since from this request's point of view that is
                // exactly what happened.
                return back()->withErrors([
                    'body' => 'As observações foram alteradas noutra janela. Recarregue a página para ver a versão mais recente.',
                ]);
            }
        } else {
            // Conditional on the version the teacher read, in the same
            // statement: two tabs saving at once cannot both pass the check
            // above and overwrite each other.
            $written = ResultsAnalysisNote::query()
                ->whereKey($note->getKey())
                ->where('lock_version', $data['lock_version'])
                ->update([
                    'body' => $data['body'] ?? null,
                    'lock_version' => $data['lock_version'] + 1,
                    'updated_by' => $user->id,
                    'updated_at' => now(),
                ]);

            if ($written === 0) {
                return back()->withErrors([
                    'body' => 'As observações foram alteradas noutra janela. Recarregue a página para ver a versão mais recente.',
                ]);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Observações guardadas.']);

        return back();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
