<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\LeaveOrganization;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\User;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OrganizationMembershipController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected LeaveOrganization $leaveOrganization,
    ) {}

    public function leave(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        try {
            $this->leaveOrganization->leave($this->currentOrganization->get(), $this->user($request));
        } catch (MembershipException $exception) {
            return back()->withErrors(['organization' => $exception->getMessage()]);
        }

        $request->session()->forget('organization_id');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saiu da organização.')]);

        return to_route('dashboard');
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
