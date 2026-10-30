<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\CalendarReconciliationPlan;
use App\Actions\Lessons\CalendarReconciliationScope;
use App\Actions\Lessons\ReconcileLessonsWithCalendarExceptions;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\CalendarConflictRequest;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\CalendarReconciliationPresenter;
use App\Services\Lessons\NonTeachingDayConflicts;
use App\Support\Entitlements\Entitlements;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\ValidationException;

/**
 * As aulas do PROFESSOR que caem em dias não letivos (feriados, interrupções,
 * dias não letivos criados DEPOIS de as aulas existirem) — o aviso, a
 * pré-visualização e a regularização.
 *
 * SÓ AS SUAS TURMAS, SEMPRE: as turmas que o utilizador leciona no ano. Um
 * colega de outra turma não vê nem regulariza nada daqui, e nenhum caminho
 * deste controlador escreve uma exceção do calendário — esse é o responsável,
 * em AcademicCalendarExceptionController.
 *
 * Três pedidos:
 *  - `index`: LEVE, só a classificação A/B/C (NonTeachingDayConflicts), sem
 *    escrever nada. É a classificação FINAL — a mesma da pré-visualização, com
 *    a cadeia de cada B, a lição partilhada e o limite por execução —, por isso
 *    as contagens do aviso e do diálogo coincidem. Alimenta o aviso e a lista
 *    das aulas C (revisão individual).
 *  - `preview`: o plano completo (ReconcileLessonsWithCalendarExceptions em
 *    transação revertida) com o `plan_token`.
 *  - `regularize`: executa A e B das suas turmas, se o token ainda coincidir.
 *
 * NUNCA devolve o texto de sumários, planos ou notas privadas — ver
 * CalendarReconciliationPresenter.
 */
class LessonCalendarConflictController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected ReconcileLessonsWithCalendarExceptions $reconcile,
        protected NonTeachingDayConflicts $conflicts,
        protected CalendarReconciliationPresenter $presenter,
        protected ResolveSelectedAcademicYear $resolveAcademicYear,
        protected Entitlements $entitlements,
    ) {}

    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function index(CalendarConflictRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $year = $this->year($request);

        if ($year === null) {
            return $this->json([
                'academic_year' => null,
                'can_regularize' => false,
                'regularize_blocked' => null,
                'counts' => ['A' => 0, 'B' => 0, 'C' => 0],
                'exception_titles' => [],
                'classes' => [],
                'review_lessons' => [],
            ]);
        }

        $classes = SchoolClass::query()
            ->where('academic_year_id', $year->getKey())
            ->notArchived()
            ->taughtBy($user)
            ->orderBy('id')
            ->get();

        $exceptions = $year->exceptions()->get();
        $rows = $this->conflicts->classify($classes, $year);

        $counts = ['A' => 0, 'B' => 0, 'C' => 0];
        $perClass = [];
        $review = [];
        $titles = [];

        foreach ($classes as $class) {
            $perClass[(int) $class->getKey()] = ['class' => $class->ulid, 'class_label' => $class->label, 'A' => 0, 'B' => 0, 'C' => 0];
        }

        foreach ($rows as $row) {
            $lesson = $row['lesson'];
            $kind = $row['kind'];
            $counts[$kind]++;
            $perClass[(int) $lesson->class_id][$kind]++;

            // As interrupções de TODAS as aulas contadas (A, B e C), e não só
            // das que ficam para rever: o cabeçalho do aviso diz o nome delas
            // mesmo quando tudo se corrige daqui.
            foreach ($this->presenter->titlesCovering($exceptions, CarbonImmutable::instance($lesson->starts_at)->setTimezone('Europe/Lisbon')->toDateString()) as $title) {
                $titles[$title] = true;
            }

            if ($kind === NonTeachingDayConflicts::REVIEW) {
                $lesson->loadMissing('classGroup');
                $startsAt = CarbonImmutable::instance($lesson->starts_at)->setTimezone('Europe/Lisbon');
                $class = $classes->firstWhere('id', $lesson->class_id);

                $review[] = [
                    'ulid' => $lesson->ulid,
                    'date' => $startsAt->toDateString(),
                    'time' => $startsAt->format('H:i'),
                    'class' => $class?->ulid,
                    'class_label' => $class?->label,
                    'group' => $lesson->classGroup?->label,
                    'reasons' => $this->presenter->reasons($row['reasons']),
                    'exception_titles' => $this->presenter->titlesCovering($exceptions, $startsAt->toDateString()),
                ];
            }
        }

        $withConflicts = [];

        foreach ($perClass as $summary) {
            if ($summary['A'] + $summary['B'] + $summary['C'] > 0) {
                $withConflicts[] = $summary;
            }
        }

        $blocked = $this->regularizeBlocked($request, $year);

        return $this->json([
            'academic_year' => ['ulid' => $year->ulid, 'label' => $year->label],
            'can_regularize' => $blocked === null,
            // Porque é que não se pode corrigir daqui — para o ecrã não mostrar
            // «Ver e corrigir» a quem vai receber uma recusa.
            'regularize_blocked' => $blocked,
            'counts' => $counts,
            'exception_titles' => array_keys($titles),
            'classes' => $withConflicts,
            'review_lessons' => $review,
        ]);
    }

    public function preview(CalendarConflictRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $year = $this->requiredYear($request);

        $plan = $this->reconcile->preview(CalendarReconciliationScope::forTeacher($user, $year), $user);

        return $this->json($this->presenter->teacher($plan, $year->exceptions()->get()));
    }

    public function regularize(CalendarConflictRequest $request): JsonResponse
    {
        $this->refuseDuringImpersonation($request);

        $user = $this->user($request);
        $year = $this->requiredYear($request);

        $plan = $this->reconcile->execute(
            CalendarReconciliationScope::forTeacher($user, $year),
            (string) $request->validated('plan_token'),
            $user,
        );

        $retired = $plan->totals['retired'];
        $shifted = $plan->totals['shifted'];
        $flagged = $plan->totals['flagged'];
        ['pending' => $pending, 'later' => $later] = $this->outcomeDetails($plan);

        $message = $retired + $shifted === 0
            ? __('Nenhuma aula foi alterada.')
            : trim(
                ($retired > 0 ? trans_choice('{1} Foi retirada 1 aula vazia.|[2,*] Foram retiradas :count aulas vazias.', $retired).' ' : '')
                // O PLANEAMENTO PRIMEIRO, e dito como o que é: passou para a
                // aula seguinte. «Aulas com planeamento foram retiradas»
                // sozinho lia-se como planeamento apagado.
                .($shifted > 0 ? trans_choice('{1} O planeamento de 1 aula em dia sem aulas passou para a aula seguinte, sem se perder nada, e essa aula foi retirada.|[2,*] O planeamento de :count aulas em dias sem aulas passou para a aula seguinte, sem se perder nada, e essas aulas foram retiradas.', $shifted) : ''),
            );

        // Quando o horário acaba antes do planeamento, o último fica como
        // pendência — não «passou para a aula seguinte».
        if ($pending > 0) {
            $message .= ' '.trans_choice('{1} 1 planeamento ficou guardado como planeamento pendente na última aula, porque não há mais aulas no horário.|[2,*] :count planeamentos ficaram guardados como planeamento pendente na última aula, porque não há mais aulas no horário.', $pending);
        }

        if ($later > 0) {
            $message .= ' '.trans_choice('{1} 1 aula com planeamento fica para a próxima correção: volta a «Ver e corrigir».|[2,*] :count aulas com planeamento ficam para a próxima correção: volta a «Ver e corrigir».', $later);
        }

        if ($flagged - $later > 0) {
            $message .= ' '.trans_choice('{1} 1 aula fica como está, para a reveres.|[2,*] :count aulas ficam como estão, para as reveres.', $flagged - $later);
        }

        return $this->json($this->presenter->result($plan) + ['message' => $message]);
    }

    /**
     * Quantos planeamentos ficaram como pendência, e quantas B ficaram para a
     * execução seguinte (`batch_limit`) — do detalhe do plano do professor.
     *
     * @return array{pending: int, later: int}
     */
    private function outcomeDetails(CalendarReconciliationPlan $plan): array
    {
        $pending = 0;
        $later = 0;

        foreach ($plan->classes as $class) {
            foreach ($class['lessons'] ?? [] as $lesson) {
                if (($lesson['pending'] ?? false) === true && ($lesson['retired'] ?? false) === true) {
                    $pending++;
                }

                if (($lesson['kind'] ?? null) === NonTeachingDayConflicts::REVIEW && ($lesson['reasons'] ?? []) === ['batch_limit']) {
                    $later++;
                }
            }
        }

        return ['pending' => $pending, 'later' => $later];
    }

    /**
     * Porque é que o professor não pode corrigir daqui, ou NULL: o ano letivo
     * fechado, o módulo «Aulas e Sumários» só para consulta, ou uma sessão de
     * suporte (a regularização recusa-as a todas).
     *
     * @return 'year_closed'|'read_only'|'support_session'|null
     */
    private function regularizeBlocked(Request $request, AcademicYear $year): ?string
    {
        if (! $year->isEditable()) {
            return 'year_closed';
        }

        if (! $this->entitlements->accessState('lessons')->permitsWrite()) {
            return 'read_only';
        }

        return $request->session()->has('impersonator_id') ? 'support_session' : null;
    }

    /**
     * O ano pedido (`academic_year`, ulid) ou, por omissão, o ano corrente da
     * organização (o ativo; o mesmo critério do resto das aulas).
     */
    private function year(Request $request): ?AcademicYear
    {
        $ulid = $request->input('academic_year');

        if (is_string($ulid) && $ulid !== '') {
            return AcademicYear::query()->where('ulid', $ulid)->firstOrFail();
        }

        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');

        return $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);
    }

    private function requiredYear(Request $request): AcademicYear
    {
        return $this->year($request) ?? throw ValidationException::withMessages([
            'academic_year' => __('Não há nenhum ano letivo para verificar.'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload): JsonResponse
    {
        return response()->json($payload)->header('Cache-Control', 'private, no-store');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
