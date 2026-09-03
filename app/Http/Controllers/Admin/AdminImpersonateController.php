<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StartSupportAccessRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Support impersonation (§ backoffice): a platform admin views the app as a
 * teacher to debug or help. Both start and stop are audited — this is the most
 * sensitive action, so it always leaves a trail. Never impersonate another admin.
 */
class AdminImpersonateController extends Controller
{
    public function __construct(
        protected AuditLog $audit,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function start(StartSupportAccessRequest $request, Organization $organization): RedirectResponse
    {
        $target = $organization->owner;

        if ($target === null || $target->isPlatformAdmin()) {
            abort(403, __('Não é possível impersonar esta conta.'));
        }

        // Remember who we really are, then become the teacher. Audit BEFORE the
        // switch so the causer is the admin, not the impersonated user.
        $validated = $request->validated();
        $accessId = (string) Str::ulid();

        session([
            'impersonator_id' => Auth::id(),
            'support_access_id' => $accessId,
        ]);
        $this->audit(
            $organization,
            'admin.impersonation_started',
            "Impersonação iniciada de {$target->email}.",
            [
                'category' => $validated['category'],
                'ticket_reference' => $validated['ticket_reference'] ?? null,
                'note' => $validated['note'] ?? null,
                'support_access_id' => $accessId,
            ],
        );

        Auth::login($target);

        return redirect('/dashboard');
    }

    public function stop(): RedirectResponse
    {
        $impersonatorId = session()->pull('impersonator_id');
        $accessId = session()->pull('support_access_id');

        if ($impersonatorId === null) {
            abort(403);
        }

        $impersonated = User::find(Auth::id()); // the teacher we are currently viewing as
        Auth::login(User::query()->where('id', $impersonatorId)->firstOrFail());

        $organization = $impersonated?->personalOrganization();
        if ($organization !== null) {
            $this->audit(
                $organization,
                'admin.impersonation_stopped',
                "Impersonação terminada de {$impersonated->email}.",
                ['support_access_id' => $accessId],
            );
        }

        return redirect('/admin');
    }

    /** @param  array<string, mixed>  $properties */
    protected function audit(Organization $organization, string $event, string $summary, array $properties = []): void
    {
        $this->currentOrganization->runFor(
            $organization,
            fn () => $this->audit->record($event, $organization, summary: $summary, properties: $properties),
        );
    }
}
