<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\PostponeLessonForCalendarActivity;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\PostponeLessonForActivityRequest;
use App\Models\CalendarEvent;
use App\Models\Lesson;
use App\Models\User;
use App\Services\Lessons\LessonDayEvents;
use App\Support\Entitlements\Entitlements;
use App\Support\Usage\UsageEventType;
use App\Support\Usage\UsageRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * «Registar e adiar a preparação» (0.171.0): uma atividade do calendário do
 * professor ocupa a aula. A pré-visualização só lê (transação revertida); a
 * execução regista o resultado e avança a preparação, e só com o `plan_token`
 * da pré-visualização que o professor viu.
 */
class LessonActivityPostponementController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected PostponeLessonForCalendarActivity $postpone,
        protected LessonDayEvents $dayEvents,
        protected Entitlements $entitlements,
        protected UsageRecorder $usage,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function preview(PostponeLessonForActivityRequest $request, Lesson $lesson): JsonResponse
    {
        $event = $this->resolveEvent($request, $lesson);

        return response()->json($this->postpone->preview($lesson, $event, $this->user($request)));
    }

    public function store(PostponeLessonForActivityRequest $request, Lesson $lesson): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $event = $this->resolveEvent($request, $lesson);

        $effect = $this->postpone->execute(
            $lesson,
            $event,
            $this->user($request),
            (string) $request->validated('plan_token'),
            $request->boolean('confirm_cascade'),
        );

        $this->usage->action(UsageEventType::LessonOutcomeRecorded, $lesson);

        // VISÍVEL: a flash de sessão `success` não chega a nenhum ecrã.
        Inertia::flash('toast', ['type' => 'success', 'message' => $effect['message']]);

        return back();
    }

    /**
     * O acontecimento tem de ser do próprio professor, da turma desta aula e
     * coincidir com ela — qualquer outro ULID é uma recusa 422 no campo, e
     * nunca um 500 nem uma pista sobre acontecimentos alheios.
     */
    private function resolveEvent(PostponeLessonForActivityRequest $request, Lesson $lesson): CalendarEvent
    {
        if (! $this->entitlements->canRead('calendar')) {
            throw ValidationException::withMessages([
                'calendar_event' => __('O Calendário do Ano Letivo não está disponível no teu plano.'),
            ]);
        }

        $event = $this->dayEvents->coincidingEvent($lesson, $this->user($request), $request->string('calendar_event')->toString());

        if ($event === null) {
            throw ValidationException::withMessages([
                'calendar_event' => __('Este acontecimento não coincide com a aula.'),
            ]);
        }

        return $event;
    }

    private function user(PostponeLessonForActivityRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
