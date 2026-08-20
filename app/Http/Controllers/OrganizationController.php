<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Which organization the current session is acting for (Fatia 2).
 *
 * The only write this controller ever does is `session()->put('organization_id', ...)`
 * — the exact mechanism ResolveOrganization already reads on every request. There
 * is deliberately no "current organization" write path anywhere else: a session
 * id the user is not a member of is worthless, because ResolveOrganization
 * re-checks membership from the database regardless of what this stamps.
 */
class OrganizationController extends Controller
{
    /**
     * Switches the acting organization to one the user actually belongs to.
     *
     * The membership check here is what makes this safe against a manipulated
     * `organization` value — not a courtesy, the actual guard. Nothing here
     * inspects `owner_id`: membership is what ResolveOrganization reads, and an
     * owner without a membership row could never be switched into their own
     * organization either (the invariant this fatia's creation actions protect).
     *
     * Redirects to the dashboard rather than back to the referring page on
     * purpose (§22 of the multi-user brief): the page a request came from may
     * carry a class, subject or report id that belongs to the ORGANIZATION LEFT
     * BEHIND, and there is no dependable way to tell whether it still means
     * anything in the new one.
     */
    public function switch(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization' => ['required', 'string'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $organization = $user->organizations()
            ->where('organizations.ulid', $validated['organization'])
            ->first();

        if ($organization === null) {
            abort(403, __('Não pertence a essa organização.'));
        }

        $request->session()->put('organization_id', $organization->getKey());

        return to_route('dashboard');
    }
}
