<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, made visible (§22.4), in its two readings. Read-only in
 * both: the trail is immutable.
 *
 * «Minha atividade» (`index`) — what THIS user did in the current organization,
 * and nothing else, whoever they are. Every plan: a teacher checking what they
 * did is a property of the platform, like exporting their own data (§20), not
 * something a plan sells. So there is no module key, and the filter is
 * `causer_id`, never `visibleTo()`: an owner opening «Minha atividade» sees
 * their own actions, not the organization's.
 *
 * «Auditoria da organização» (`organization`) — the transversal view, gated by
 * `audit_log` (Institucional) on the route. Visibility there still lives on the
 * model (AuditEvent::scopeVisibleTo): the owner sees everything, a member only
 * their own. The gate and the scope are separate layers.
 */
class ActivityController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();

        return Inertia::render('Activity', [
            'events' => $this->present(AuditEvent::query()->where('causer_id', $user->getKey())),
            'scope' => 'mine',
        ]);
    }

    public function organization(): Response
    {
        $user = $this->user();

        return Inertia::render('Activity', [
            'events' => $this->present(AuditEvent::query()->visibleTo($user)),
            // Drives the page's own heading, not an access decision — the query
            // above has already decided what is IN the events.
            'scope' => $user->ownsCurrentOrganization() ? 'organization' : 'own',
        ]);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return array<int, array{ulid: string, event: string, summary: string|null, causer: string|null, subject_type: string|null, at: string}>
     */
    protected function present(Builder $query): array
    {
        return $query
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'ulid' => $event->ulid,
                'event' => $event->event,
                'summary' => $event->summary,
                'causer' => $event->causer?->name,
                'subject_type' => $event->subject_type,
                'at' => $event->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
