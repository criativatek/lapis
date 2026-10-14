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
            'events' => $this->present(
                AuditEvent::query()->where(fn (Builder $query) => $query
                    ->where('causer_id', $user->getKey())
                    // O que o suporte técnico fez em nome deste utilizador (ADR-0014).
                    ->orWhere('properties->acting_as_user_id', $user->getKey())),
                $user,
            ),
            'scope' => 'mine',
        ]);
    }

    public function organization(): Response
    {
        $user = $this->user();

        return Inertia::render('Activity', [
            'events' => $this->present(AuditEvent::query()->visibleTo($user), $user),
            // Drives the page's own heading, not an access decision — the query
            // above has already decided what is IN the events.
            'scope' => $user->ownsCurrentOrganization() ? 'organization' : 'own',
        ]);
    }

    /**
     * @param  Builder<AuditEvent>  $query
     * @return array<int, array{ulid: string, event: string, summary: string|null, causer: string|null, technical_access: bool, subject_type: string|null, at: string}>
     */
    protected function present(Builder $query, User $viewer): array
    {
        return $query
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (AuditEvent $event) use ($viewer): array {
                // Feito pelo suporte técnico em nome de alguém (ADR-0014) e visto
                // por quem não é o autor: nunca o nome, o email nem o id do
                // administrador — só que foi o suporte. O próprio administrador
                // continua a ver o seu nome.
                $technicalAccess = isset($event->properties['acting_as_user_id'])
                    && (int) $event->causer_id !== (int) $viewer->getKey();

                return [
                    'ulid' => $event->ulid,
                    'event' => $event->event,
                    'summary' => $event->summary,
                    'causer' => $technicalAccess ? 'Suporte técnico Lapispro' : $event->causer?->name,
                    'technical_access' => $technicalAccess,
                    'subject_type' => $event->subject_type,
                    'at' => $event->created_at->toIso8601String(),
                ];
            })
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
