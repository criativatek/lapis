<?php

namespace App\Http\Controllers;

use App\Actions\Timetable\ReconcileActivitySessions;
use App\Actions\Timetable\RemoveTimetableActivity;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Timetable\RemoveTimetableActivityRequest;
use App\Http\Requests\Timetable\TimetableActivityRequest;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Services\Lessons\ScheduleChangeNotice;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * «Outras atividades» do horário do professor (0.168.0; eram «não letivas»).
 * Escrevem uma tabela só delas: não tocam em `recurring_lesson_slots` nem em
 * `lessons`, e por isso nunca criam uma aula nem um número de lição. O registo
 * de cada sessão (0.170.0) é de `ActivitySessionController`.
 *
 * Editar o bloco nunca apaga nem desloca uma sessão com registos; eliminá-lo,
 * havendo sessões com registos, fecha-o em vez de o apagar.
 */
class TimetableActivityController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected ReconcileActivitySessions $reconcile,
        protected RemoveTimetableActivity $remove,
    ) {}

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

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Atividade adicionada ao horário.']);

        return back();
    }

    public function update(TimetableActivityRequest $request, TimetableActivity $timetableActivity): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        // O bloco continua NO LUGAR; as sessões acompanham-no só quando não têm
        // registos. As que têm ficam onde estavam, e o professor é avisado.
        $preserved = DB::transaction(function () use ($request, $timetableActivity): Collection {
            $timetableActivity->update($this->attributes($request, $timetableActivity));

            return $this->reconcile->afterEdit($timetableActivity);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Atividade atualizada.']);

        $firstPreserved = $preserved->first();
        $notice = ScheduleChangeNotice::describeActivitySessions(
            $preserved,
            $firstPreserved instanceof TimetableActivitySession ? $firstPreserved->activity_title : $timetableActivity->title,
            'Horário guardado.',
        );

        if ($notice !== null) {
            Inertia::flash('scheduleNotice', ['message' => $notice]);
        }

        return back();
    }

    public function destroy(RemoveTimetableActivityRequest $request, TimetableActivity $timetableActivity): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $title = $timetableActivity->title;
        $result = $this->remove->execute($timetableActivity, $request->effectiveFrom());

        if ($result['outcome'] === 'deleted') {
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Atividade removida do horário.']);

            return back();
        }

        // Com histórico não se apaga: fecha-se. O professor lê o que ficou.
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Atividade terminada no horário.']);
        Inertia::flash('scheduleNotice', ['message' => sprintf(
            '«%s» deixa de vigorar a partir de %s. O histórico fica guardado: %s com registos continua%s a poder ser consultad%s.',
            $title,
            CarbonImmutable::parse((string) $result['effective_from'])->format('d/m/Y'),
            $result['records'] === 1 ? '1 sessão' : $result['records'].' sessões',
            $result['records'] === 1 ? '' : 'm',
            $result['records'] === 1 ? 'a' : 'as',
        )]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(TimetableActivityRequest $request, ?TimetableActivity $current = null): array
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
            // A numeração das sessões é opcional (0.170.0): desligada, só a data.
            // Um pedido sem estes campos não desliga a numeração que já existe.
            'numbering_enabled' => (bool) ($validated['numbering_enabled'] ?? ($current === null ? false : $current->numbering_enabled)),
            'numbering_start' => (int) ($validated['numbering_start'] ?? ($current === null ? 1 : $current->numbering_start)),
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
