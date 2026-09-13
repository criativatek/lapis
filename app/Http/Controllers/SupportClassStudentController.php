<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Classes\ReusableStudents;
use App\Services\Classes\StudentAlreadyEnrolled;
use App\Services\StudentEnrollmentService;
use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Adicionar aluno existente» — só numa turma de apoio.
 *
 * A PESQUISA NÃO É A AUTORIZAÇÃO. O ULID que o browser devolve é verificado de
 * novo, do zero, por ReusableStudents::find(): um ULID de outra organização,
 * de uma turma de um colega ou forjado à mão recebe a mesma resposta que um que
 * não existe.
 *
 * NUMA TURMA NORMAL, 404. A inscrição de alunos existentes é o comportamento
 * que o professor liga ao marcar «Turma de apoio»; sem essa marca a turma
 * continua com os fluxos de sempre e esta porta não existe.
 */
class SupportClassStudentController extends Controller
{
    public function __construct(
        protected ReusableStudents $students,
        protected StudentEnrollmentService $service,
        protected CurrentOrganization $currentOrganization,
    ) {}

    public function search(Request $request, SchoolClass $class): JsonResponse
    {
        Gate::authorize('update', $class);
        abort_unless($class->is_support_class, 404);

        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->students->search($class, $this->user($request), (string) ($data['q'] ?? '')),
        ]);
    }

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);
        abort_unless($class->is_support_class, 404);

        $data = $request->validate([
            'student_ulid' => ['required', 'string', 'max:26'],
        ]);

        $student = $this->students->find($class, $this->user($request), $data['student_ulid']);

        if ($student === null) {
            return back()->withErrors(['student_ulid' => 'Aluno não encontrado nas suas turmas deste ano letivo.']);
        }

        try {
            // A DATA DE ENTRADA É HOJE, não o início do ano. Um aluno que
            // começa o apoio em dezembro não esteve lá em outubro: com a data
            // de setembro, os instrumentos e as aulas anteriores passariam a
            // contar-lhe (§11.4). Limitada ao ano letivo da turma, e corrigível
            // depois em «Corrigir dados», como qualquer outra inscrição.
            $this->service->enrollExisting($class, $student, $this->entryDateFor($class));
        } catch (StudentAlreadyEnrolled $refusal) {
            return back()->withErrors(['student_ulid' => $refusal->getMessage()]);
        }

        // SEM TOAST, de propósito: a confirmação é dada no próprio campo de
        // pesquisa (ExistingStudentPicker). No telemóvel o toast caía em cima
        // da lista de resultados e engolia o toque seguinte do professor.
        return back();
    }

    protected function entryDateFor(SchoolClass $class): string
    {
        $today = CarbonImmutable::now($this->currentOrganization->get()->timezone)->startOfDay();
        $year = $class->academicYear;

        return match (true) {
            $today->lessThan($year->starts_on) => $year->starts_on->toDateString(),
            $today->greaterThan($year->ends_on) => $year->ends_on->toDateString(),
            default => $today->toDateString(),
        };
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
