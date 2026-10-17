<?php

namespace App\Http\Controllers;

use App\Actions\Timetable\SaveActivitySession;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Timetable\ActivitySessionRequest;
use App\Models\TimetableActivity;
use App\Models\TimetableActivitySession;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Timetable\ActivitySessionHistory;
use App\Services\Timetable\ActivitySessionPresenter;
use App\Services\Timetable\ActivitySessionsXlsxWriter;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O registo por sessão das «outras atividades» do horário (0.170.0): guardar o
 * sumário de cada sessão e consultar o histórico.
 *
 * NÃO TOCA EM AULAS. Escreve só `timetable_activity_sessions`: nunca cria um
 * `Lesson`, nunca numera como lição, nunca mexe nas aulas de uma turma.
 *
 * É do professor que escreve: a política responde 404 a todos os outros, os
 * coprofessores da turma associada incluídos. Em acesso técnico (ADR-0014) a
 * leitura — a página e a exportação — é permitida; toda a escrita é recusada.
 */
class ActivitySessionController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    private const PER_PAGE = 40;

    public function __construct(
        protected CurrentOrganization $currentOrganization,
        protected SaveActivitySession $save,
        protected ActivitySessionHistory $history,
        protected ActivitySessionPresenter $presenter,
        protected AuditLog $audit,
    ) {}

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    /**
     * O histórico: as sessões com registos, filtradas por atividade e período.
     */
    public function index(Request $request): Response
    {
        $teacher = $this->user($request);
        [$activity, $from, $to] = $this->filters($request);

        $page = $this->history->query($teacher, $activity, $from, $to)->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('activity-sessions/Index', [
            'sessions' => collect($page->items())
                ->map(fn (TimetableActivitySession $session): array => $this->presenter->session($session))
                ->values(),
            'filters' => ['activity' => $activity, 'from' => $from, 'to' => $to],
            'activityOptions' => $this->history->options($teacher),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'previous_url' => $page->previousPageUrl(),
                'next_url' => $page->nextPageUrl(),
            ],
            'exportUrl' => '/activity-sessions/export?'.http_build_query(array_filter(
                ['activity' => $activity, 'from' => $from, 'to' => $to],
                fn (?string $value): bool => $value !== null && $value !== '',
            )),
        ]);
    }

    /**
     * O histórico em Excel — o MESMO filtro e a MESMA consulta do ecrã. Só do
     * dono, deixa rasto de auditoria (só contagens) e não passa por disco.
     */
    public function export(Request $request, ActivitySessionsXlsxWriter $xlsx): HttpResponse
    {
        $teacher = $this->user($request);
        [$activity, $from, $to] = $this->filters($request);

        $sessions = $this->history->query($teacher, $activity, $from, $to)->get();

        $this->audit->record(
            'activity-sessions.exported',
            null,
            $teacher,
            'Sessões das outras atividades exportadas para Excel.',
            ['format' => 'xlsx', 'sessions' => $sessions->count()],
        );

        // Nome fixo mais a data: nada que um professor escreveu entra no
        // cabeçalho da resposta.
        $stem = 'outras-atividades-'.CarbonImmutable::now()->format('Ymd');

        return response($xlsx->write($sessions), 200, [
            'Content-Type' => $xlsx->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$stem.'.xlsx"',
        ]);
    }

    /**
     * Guarda a sessão de uma ocorrência do bloco (por atividade e data),
     * criando-a se ainda não existir. Idempotente.
     */
    public function store(ActivitySessionRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        /** @var TimetableActivity $activity */
        $activity = $request->activity();

        $this->save->forOccurrence(
            $activity,
            (string) $request->validated('occurs_on'),
            $request->summary(),
            $request->summaryGiven(),
            $request->sessionAction(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sessão guardada.']);

        return back();
    }

    /**
     * Edita uma sessão que já existe — sempre possível, esteja ou não no
     * horário atual.
     */
    public function update(ActivitySessionRequest $request, TimetableActivitySession $timetableActivitySession): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $this->save->forSession(
            $timetableActivitySession,
            $request->summary(),
            $request->summaryGiven(),
            $request->sessionAction(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sessão guardada.']);

        return back();
    }

    /**
     * @return array{0: string|null, 1: string|null, 2: string|null}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'activity' => ['sometimes', 'nullable', 'string', 'max:100'],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        // O período por omissão é o ano letivo de hoje; um período vazio
        // pedido explicitamente (`from=`/`to=`) não é substituído.
        [$defaultFrom, $defaultTo] = $this->history->defaultPeriod(
            CarbonImmutable::now($this->currentOrganization->get()->timezone),
        );

        $activity = $validated['activity'] ?? null;

        return [
            is_string($activity) && $activity !== '' ? $activity : null,
            array_key_exists('from', $validated) ? $validated['from'] : $defaultFrom,
            array_key_exists('to', $validated) ? $validated['to'] : $defaultTo,
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
