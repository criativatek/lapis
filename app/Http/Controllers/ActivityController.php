<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, made visible (§22.4). Shows what THIS user is allowed to see
 * of the organization's recorded events — who did what, when. Read-only: the
 * trail is immutable.
 *
 * Visibility itself lives on the model (AuditEvent::scopeVisibleTo), not here:
 * a controller answers "what does the page need", not "who may see what" — the
 * same reason authorization always lives in a Policy and never inline.
 */
class ActivityController extends Controller
{
    public function index(): Response
    {
        $user = $this->user();

        $events = AuditEvent::query()
            ->visibleTo($user)
            ->with('causer')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'ulid' => $event->ulid,
                'event' => $event->event,
                'summary' => $event->summary,
                'causer' => $event->causer?->name,
                'subject_type' => $event->subject_type,
                'at' => $event->created_at->toIso8601String(),
            ]);

        return Inertia::render('Activity', [
            'events' => $events,
            // Drives the page's own heading, not an access decision — the query
            // above has already decided what is IN $events.
            'scope' => $user->ownsCurrentOrganization() ? 'organization' : 'own',
        ]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
