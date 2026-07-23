<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit trail, made visible (§22.4). Shows the organization's recent recorded
 * events — who did what, when. Read-only: the trail is immutable.
 */
class ActivityController extends Controller
{
    public function index(): Response
    {
        $events = AuditEvent::query()
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

        return Inertia::render('Activity', ['events' => $events]);
    }
}
