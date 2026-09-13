<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\MaterializeLessonsForWeek;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\WeeklyLessonsRequest;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\WeeklyLessonsQuery;
use App\Support\Entitlements\AccessState;
use App\Support\Entitlements\Entitlements;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LessonWeekController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        private readonly WeeklyLessonsQuery $weeklyLessons,
        private readonly MaterializeLessonsForWeek $materializeLessons,
        private readonly ResolveSelectedAcademicYear $resolveAcademicYear,
        private readonly Entitlements $entitlements,
    ) {}

    /** @return list<string> */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function index(WeeklyLessonsRequest $request): Response
    {
        $academicYear = $this->selectedAcademicYear($request);
        $weekStart = CarbonImmutable::parse($request->validated('week', now()->toDateString()), 'Europe/Lisbon')->startOfWeek();

        // Abrir a semana passa a criar as suas próprias aulas, em vez de
        // exigir um passo manual antes de o horário aparecer. A ação é
        // idempotente (firstOrCreate sobre um índice único real) e está
        // estritamente limitada à semana pedida — nunca ao ano letivo
        // inteiro nem a qualquer outra semana. Uma sessão de suporte
        // continua a não escrever nada em nome do professor.
        //
        // E NÃO ESCREVE NADA QUANDO O MÓDULO ESTÁ `ReadOnly` (§Lote 2): uma
        // assinatura suspensa deixa consultar as aulas já existentes da
        // semana, mas `RequireModule` já deixa este GET passar precisamente
        // porque é uma leitura — sem esta verificação extra, abrir a semana
        // continuaria, incorretamente, a criar aulas novas durante a
        // suspensão. `Allowed` continua a materializar exatamente como antes.
        if ($academicYear !== null
            && ! $request->session()->has('impersonator_id')
            && $this->entitlements->accessState('lessons') === AccessState::Allowed) {
            $this->materializeLessons->execute(
                $this->user($request),
                $academicYear,
                $weekStart,
                $weekStart->endOfWeek()->startOfDay(),
            );
        }

        return Inertia::render('lessons/Index', [
            'lessons' => $academicYear === null ? [] : $this->weeklyLessons->for($this->user($request), $academicYear, $weekStart),
            'week' => ['start' => $weekStart->toDateString(), 'end' => $weekStart->endOfWeek()->toDateString()],
            'academicYear' => $academicYear?->label,
            'configuredClassesCount' => $academicYear === null ? 0 : SchoolClass::query()
                ->where('academic_year_id', $academicYear->getKey())
                ->whereHas('teachers', fn ($query) => $query->whereKey($this->user($request)->getKey()))
                ->whereHas('recurringLessonSlots')
                ->count(),
            // «Hoje» vem do SERVIDOR, no fuso da organização, e não do relógio
            // do portátil do professor: é o mesmo dia que o lote de «Hoje» vai
            // usar do lado de lá, e um botão que diga um dia e marque outro é
            // pior do que não existir.
            'today' => CarbonImmutable::now('Europe/Lisbon')->toDateString(),
            // As turmas onde é possível inserir uma aula, com os seus grupos:
            // a sequência em que se insere é (turma, grupo), e sem os grupos o
            // formulário não conseguiria distinguir T1 de T2.
            'insertableClasses' => $academicYear === null ? [] : $this->insertableClasses($request, $academicYear),
        ]);
    }

    public function materialize(Request $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);
        $validated = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $from = CarbonImmutable::parse($validated['from'], 'Europe/Lisbon')->startOfDay();
        $to = CarbonImmutable::parse($validated['to'], 'Europe/Lisbon')->startOfDay();

        if ($from->dayOfWeekIso !== 1 || $to->dayOfWeekIso !== 7 || (int) $from->diffInDays($to) !== 6) {
            throw ValidationException::withMessages([
                'range' => __('O intervalo tem de corresponder a uma semana, de segunda-feira a domingo.'),
            ]);
        }

        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            throw ValidationException::withMessages(['academic_year' => __('Selecione um ano letivo.')]);
        }

        $this->materializeLessons->execute(
            $this->user($request),
            $academicYear,
            $from,
            $to,
        );

        return back();
    }

    /**
     * @return list<array{ulid: string, label: string, subject: string, groups: list<array{id: int, label: string}>}>
     */
    private function insertableClasses(Request $request, AcademicYear $academicYear): array
    {
        return array_values(SchoolClass::query()
            ->where('academic_year_id', $academicYear->getKey())
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user($request)->getKey()))
            // Só turmas COM horário configurado: inserir uma aula é ocupar a
            // próxima ocorrência válida do horário, e numa turma sem horário
            // não existe nenhuma para ocupar.
            ->whereHas('recurringLessonSlots')
            // `active()` e não a relação inteira: `classGroups()` devolve
            // também os arquivados, de propósito (uma aula de novembro continua
            // a dizer o grupo que tinha), mas oferecer um grupo arquivado para
            // uma aula NOVA seria o mesmo beco que RecurringLessonSlotRequest
            // já recusa abrir.
            ->with(['subject', 'classGroups' => fn ($query) => $query->active()])
            ->orderBy('label')
            ->get()
            ->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'groups' => array_values($class->classGroups
                    ->map(fn (ClassGroup $group): array => [
                        'id' => (int) $group->getKey(),
                        'label' => (string) $group->label,
                    ])
                    ->all()),
            ])
            ->all());
    }

    private function selectedAcademicYear(Request $request): ?AcademicYear
    {
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');

        return $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
