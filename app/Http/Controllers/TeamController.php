<?php

namespace App\Http\Controllers;

use App\Actions\Organizations\CancelOrganizationInvitation;
use App\Actions\Organizations\CreateOrRenewOrganizationInvitation;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Equipa (Fatia 3) — the organization's own members and pending invitations,
 * for its owner only. `module:institution_admin` (route middleware) already
 * keeps a non-institutional plan out; OrganizationInvitationPolicy adds the
 * two checks a plan gate cannot make — the organization's actual TYPE, and
 * whether THIS user owns it.
 */
class TeamController extends Controller
{
    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected CreateOrRenewOrganizationInvitation $createInvitation,
        protected CancelOrganizationInvitation $cancelInvitation,
    ) {}

    public function index(): Response
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('viewAny', [OrganizationInvitation::class, $organization]);

        $organization->load('owner');

        return Inertia::render('team/Index', [
            'members' => $organization->members()->get()->map(fn (User $member): array => [
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

    public function store(Request $request): RedirectResponse
    {
        $organization = $this->currentOrganization->get();

        Gate::authorize('create', [OrganizationInvitation::class, $organization]);

        $this->refuseDuringImpersonation($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

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

    /**
     * Team management is a governance action taken ON BEHALF of the person
     * being impersonated — support has no business sending invitations that
     * will look, to everyone else in the school, like the owner sent them.
     */
    protected function refuseDuringImpersonation(Request $request): void
    {
        if ($request->session()->has('impersonator_id')) {
            abort(403, __('Não é possível gerir a equipa durante uma sessão de suporte.'));
        }
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
