<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\CancelOrganizationClosure;
use App\Actions\Organizations\CancelOrganizationInvitation;
use App\Actions\Organizations\CreateOrRenewOrganizationInvitation;
use App\Actions\Organizations\RemoveOrganizationMember;
use App\Actions\Organizations\RequestOrganizationClosure;
use App\Actions\Organizations\TransferOrganizationOwnership;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Organizations\MembershipException;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipa (Fatia 3) — an institutional organization's members and pending
 * invitations, owner-only (OrganizationInvitationPolicy). Extended in Fatia 4
 * with removing a member and transferring ownership; both still owner-only,
 * both still refused during impersonation. The Fatia 5 organization closure
 * mutations remain here for route compatibility; their UI lives under
 * Administração Institucional.
 */
class TeamController extends Controller
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected CreateOrRenewOrganizationInvitation $createInvitation,
        protected CancelOrganizationInvitation $cancelInvitation,
        protected RemoveOrganizationMember $removeOrganizationMember,
        protected TransferOrganizationOwnership $transferOrganizationOwnership,
        protected RequestOrganizationClosure $requestOrganizationClosure,
        protected CancelOrganizationClosure $cancelOrganizationClosure,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewAny', [OrganizationInvitation::class, $organization]);

        $organization->load('owner');

        return Inertia::render('team/Index', [
            'members' => $organization->members()->get()->map(fn (User $member): array => [
                'id' => $member->getKey(),
                'name' => $member->name,
                'email' => $member->email,
                'is_owner' => $member->is($organization->owner),
                'active' => $member->isActive(),
            ])->sortByDesc('is_owner')->values(),
            'invitations' => $organization->invitations()
                ->whereNull('accepted_at')
                ->whereNull('cancelled_at')
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (OrganizationInvitation $invitation): array => [
                    'ulid' => $invitation->ulid,
                    'email' => $invitation->email,
                    'created_at' => $invitation->created_at?->toDateString(),
                    'expires_at' => $invitation->expires_at->toDateString(),
                    'expired' => $invitation->isExpired(),
                ]),
        ]);
    }

    /**
     * Request the organization's own recoverable closure (§9-§10). Owner
     * only — a member gets a 403 from the policy before this ever runs.
     */
    public function requestClosure(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('requestClosure', $organization);
        $this->refuseDuringImpersonation($request);

        try {
            $this->requestOrganizationClosure->request($organization, $this->user($request));
        } catch (MembershipException $exception) {
            return back()->withErrors(['organization' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Encerramento da organização pedido.')]);

        return back();
    }

    /**
     * Reactivate the organization within its recovery window (§12).
     */
    public function cancelClosure(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('cancelClosure', $organization);
        $this->refuseDuringImpersonation($request);

        try {
            $this->cancelOrganizationClosure->cancel($organization, $this->user($request));
        } catch (MembershipException $exception) {
            return back()->withErrors(['organization' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Organização reativada.')]);

        return back();
    }

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('create', [OrganizationInvitation::class, $organization]);
        $this->refuseDuringImpersonation($request);

        $validated = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->createInvitation->invite($organization, $this->user($request), $validated['email']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Convite enviado.')]);

        return back();
    }

    public function destroy(Request $request, OrganizationInvitation $invitation): RedirectResponse
    {
        Gate::authorize('cancel', $invitation);
        $this->refuseDuringImpersonation($request);
        $this->cancelInvitation->cancel($invitation, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Convite cancelado.')]);

        return back();
    }

    public function removeMember(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('remove', $organization);
        $this->refuseDuringImpersonation($request);

        try {
            $member = $this->targetMember($request, $organization);
            $this->removeOrganizationMember->remove($organization, $this->user($request), $member);
        } catch (MembershipException $exception) {
            return back()->withErrors(['organization' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Membro removido da organização.')]);

        return back();
    }

    public function transferOwnership(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('transferOwnership', $organization);
        $this->refuseDuringImpersonation($request);

        try {
            $member = $this->targetMember($request, $organization);
            $this->transferOrganizationOwnership->transfer($organization, $this->user($request), $member);
        } catch (MembershipException $exception) {
            return back()->withErrors(['organization' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Responsabilidade da organização transferida.')]);

        // Never back to `team.index`: the acting owner just gave up the very
        // privilege that page requires (OrganizationInvitationPolicy::viewAny
        // is owner-only), so redirecting them there is a guaranteed 403 on
        // the very next request — the transfer itself already succeeded, but
        // it would look like it failed. Same reasoning as
        // OrganizationController::switch() and
        // OrganizationMembershipController::leave(): after anything that can
        // change what the CURRENT user is allowed to see, land on `dashboard`,
        // never a page whose access this exact request may have just revoked.
        return to_route('dashboard');
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    /**
     * `User` has no `ulid` — nothing has ever needed to address one directly
     * in a URL before this fatia. Rather than retrofit the identifier scheme
     * of the app's most sensitive table for two owner-only actions, the
     * target travels in the request body (like `ClassReassignmentController`
     * already does) and is resolved through THIS organization's own members
     * only. A cross-organization or nonexistent id surfaces through the exact
     * same `MembershipException` → session-error path as every other guard
     * in this feature, not a bare 404 — one consistent failure shape for
     * "this operation is not valid," regardless of which check caught it.
     */
    protected function targetMember(Request $request, Organization $organization): User
    {
        $validated = $request->validate(['member' => ['required', 'integer']]);

        $member = $organization->members()->whereKey((int) $validated['member'])->first();

        if ($member === null) {
            throw MembershipException::notAMember();
        }

        return $member;
    }
}
