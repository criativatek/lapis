<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubjectRequest;
use App\Models\Subject;
use App\Services\SubjectUsage;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(SubjectUsage $usage): Response
    {
        Gate::authorize('viewAny', Subject::class);

        $subjects = Subject::orderBy('name')->get();
        $inUse = $usage->idsInUse(array_values(array_map(intval(...), $subjects->modelKeys())));

        return Inertia::render('subjects/Index', [
            'subjects' => $subjects->map(fn (Subject $subject) => [
                'ulid' => $subject->ulid,
                'name' => $subject->name,
                'code' => $subject->code,
                // Apresentação: diz antes do clique que não se pode eliminar.
                // Quem recusa é destroy(), que volta a perguntar.
                'in_use' => in_array($subject->getKey(), $inUse, true),
            ]),
            // A member still reads the catalogue every class depends on; only
            // the organization's owner manages it (Fatia 1).
            'canManage' => Gate::allows('create', Subject::class),
        ]);
    }

    public function store(SubjectRequest $request): RedirectResponse
    {
        Gate::authorize('create', Subject::class);

        Subject::create($request->validated());

        return to_route('subjects.index');
    }

    public function update(SubjectRequest $request, Subject $subject): RedirectResponse
    {
        Gate::authorize('update', $subject);

        $subject->update($request->validated());

        return to_route('subjects.index');
    }

    public function destroy(Subject $subject, SubjectUsage $usage): RedirectResponse
    {
        Gate::authorize('delete', $subject);

        $blocking = $usage->blocking($subject);

        if ($blocking !== []) {
            // Um redirecionamento com toast, como as outras recusas de negócio
            // (§ EnrollmentController::destroy) — nunca o 500 da chave
            // estrangeira, e nunca uma cascata.
            Inertia::flash('toast', ['type' => 'error', 'message' => $usage->explain($subject->name, $blocking)]);

            return back();
        }

        try {
            DB::transaction(fn () => $subject->delete());
        } catch (QueryException) {
            // Passou a estar em uso entre a verificação e o DELETE.
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => "Não é possível eliminar a disciplina {$subject->name} porque está a ser utilizada. Nenhum dado foi alterado.",
            ]);

            return back();
        }

        return to_route('subjects.index');
    }
}
