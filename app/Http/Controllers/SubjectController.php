<?php

namespace App\Http\Controllers;

use App\Http\Requests\SubjectRequest;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class SubjectController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Subject::class);

        return Inertia::render('subjects/Index', [
            'subjects' => Subject::orderBy('name')->get()->map(fn (Subject $subject) => [
                'ulid' => $subject->ulid,
                'name' => $subject->name,
                'code' => $subject->code,
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

    public function destroy(Subject $subject): RedirectResponse
    {
        Gate::authorize('delete', $subject);

        $subject->delete();

        return to_route('subjects.index');
    }
}
