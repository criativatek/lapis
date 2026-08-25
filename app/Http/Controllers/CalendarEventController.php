<?php

namespace App\Http\Controllers;

use App\Actions\Calendar\SaveCalendarEvent;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Calendar\CalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Os «acontecimentos» do Calendário do Ano Letivo (Fase 5.3) — criar, alterar e
 * eliminar.
 *
 * SEPARATE FROM AcademicYearCalendarController, DELIBERATELY. That controller
 * is a reading and says so in its own docblock — it refuses nothing during
 * impersonation precisely because looking changes nothing. This one writes, and
 * a class that both reads harmlessly and writes consequentially would have to
 * hedge that sentence in every method. Two controllers, one page: these three
 * actions all redirect back to whichever calendar view the teacher was looking
 * at, which is where the result of every one of them is visible.
 *
 * Gated by module:calendar, the same entitlement the views already carry — the
 * capability to write on the calendar is part of having the calendar, not a
 * second thing to sell.
 *
 * RefusesDuringImpersonation on all three, unlike the read-only views: an
 * acontecimento carries the name of the teacher who created it, and support has
 * no business putting one in somebody's calendar under their name.
 */
class CalendarEventController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:calendar'];
    }

    public function store(CalendarEventRequest $request, SaveCalendarEvent $saveCalendarEvent): RedirectResponse
    {
        Gate::authorize('create', CalendarEvent::class);
        $this->refuseDuringImpersonation($request);

        $saveCalendarEvent->execute(
            null,
            $request->safe()->only(['type', 'title', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'description']),
            $this->schoolClassUlids($request),
            $this->user($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acontecimento adicionado ao calendário.']);

        return back();
    }

    public function update(
        CalendarEventRequest $request,
        CalendarEvent $calendarEvent,
        SaveCalendarEvent $saveCalendarEvent,
    ): RedirectResponse {
        Gate::authorize('update', $calendarEvent);
        $this->refuseDuringImpersonation($request);

        $saveCalendarEvent->execute(
            $calendarEvent,
            $request->safe()->only(['type', 'title', 'starts_on', 'ends_on', 'starts_at', 'ends_at', 'description']),
            $this->schoolClassUlids($request),
            $this->user($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acontecimento atualizado.']);

        return back();
    }

    /**
     * ELIMINAR UM ACONTECIMENTO ELIMINA UM ACONTECIMENTO. As avaliações, a
     * estrutura do ano e o horário têm ciclos de vida inteiramente próprios e
     * não são tocados aqui — a única outra coisa que desaparece são as ligações
     * às turmas deste acontecimento, que sem ele não querem dizer nada.
     */
    public function destroy(Request $request, CalendarEvent $calendarEvent): RedirectResponse
    {
        Gate::authorize('delete', $calendarEvent);
        $this->refuseDuringImpersonation($request);

        $calendarEvent->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Acontecimento eliminado.']);

        return back();
    }

    /**
     * @return list<string>
     */
    private function schoolClassUlids(CalendarEventRequest $request): array
    {
        $ulids = $request->validated('school_class_ulids') ?? [];

        return array_values(array_filter(
            is_array($ulids) ? $ulids : [],
            fn (mixed $ulid): bool => is_string($ulid),
        ));
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
