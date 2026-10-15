<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Timetable\TimetableActivityRequest;
use App\Models\TimetableActivity;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;

/**
 * Atividades não letivas do horário do professor (0.168.0). Escrevem uma tabela
 * só delas: não tocam em `recurring_lesson_slots` nem em `lessons`, e por isso
 * nunca criam uma aula, um número ou um sumário.
 */
class TimetableActivityController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function store(TimetableActivityRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        TimetableActivity::create($this->attributes($request) + ['user_id' => $this->user($request)->getKey()]);

        return back()->with('success', 'Atividade adicionada ao horário.');
    }

    public function update(TimetableActivityRequest $request, TimetableActivity $timetableActivity): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $timetableActivity->update($this->attributes($request));

        return back()->with('success', 'Atividade atualizada.');
    }

    public function destroy(Request $request, TimetableActivity $timetableActivity): RedirectResponse
    {
        Gate::authorize('delete', $timetableActivity);
        $this->refuseDuringImpersonation($request);

        $timetableActivity->delete();

        return back()->with('success', 'Atividade removida do horário.');
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TimetableActivityRequest $request): array
    {
        $validated = $request->validated();

        return [
            'kind' => $validated['kind'],
            'title' => trim((string) $validated['title']),
            'class_id' => $request->schoolClass()?->getKey(),
            'day_of_week' => $validated['day_of_week'],
            'starts_at' => $validated['starts_at'],
            'ends_at' => $validated['ends_at'],
            'starts_on' => $validated['starts_on'] ?? null,
            'ends_on' => $validated['ends_on'] ?? null,
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
