<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\AssignClassTeacher;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ClassReassignmentController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected AssignClassTeacher $assignClassTeacher,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewReassignments', $organization);

        return Inertia::render('classes/reassignment/Index', [
            // A detached pivot contains no trustworthy previous-teacher data;
            // exposing or reconstructing it from audit rows would be fragile.
            'classes' => SchoolClass::query()
                ->needingReassignment()
                ->with(['subject', 'academicYear'])
                ->get()
                ->map(fn (SchoolClass $class): array => [
                    'ulid' => $class->ulid,
                    'label' => $class->label,
                    'subject' => $class->subject?->name,
                    'academic_year' => $class->academicYear->label,
                ]),
            'members' => $organization->members()->get()->map(fn (User $member): array => [
                'id' => $member->getKey(),
                'name' => $member->name,
                'email' => $member->email,
            ]),
        ]);
    }

    public function assign(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('assignTeacher', $class);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate(['member' => ['required', 'integer']]);
        $organization = $this->currentOrganization->get();
        $target = $organization->members()->whereKey((int) $validated['member'])->firstOrFail();

        try {
            $this->assignClassTeacher->assign($class, $target);
        } catch (MembershipException $exception) {
            return back()->withErrors(['member' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Turma reatribuída.')]);

        return back();
    }
}
