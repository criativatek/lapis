<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Lessons\TeacherLessonServiceReport;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Serviço letivo» — o relatório pessoal do professor sobre as aulas de UMA
 * turma: previstas, dadas, não realizadas, por registar, a percentagem de
 * aulas dadas, as causas e a evolução mensal. Só leitura.
 *
 * Só as turmas que o professor leciona (`taughtBy`); outra turma não existe
 * para ele. O bloco pessoal só leva o que ele próprio registou.
 */
class LessonServiceReportController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly TeacherLessonServiceReport $report,
        private readonly ResolveSelectedAcademicYear $resolveAcademicYear,
    ) {}

    /** @return list<string> */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'class' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        /** @var User $teacher */
        $teacher = $request->user();
        $years = AcademicYear::query()->orderByDesc('starts_on')->get();
        $selectedId = $request->session()->get('academic_year_id');
        $year = $this->resolveAcademicYear->for($years, is_int($selectedId) ? $selectedId : null);

        $classes = $year === null ? collect() : SchoolClass::query()
            ->where('academic_year_id', $year->getKey())
            ->taughtBy($teacher)
            ->with('subject')
            ->orderBy('label')
            ->get();

        $selected = $classes->firstWhere('ulid', $validated['class'] ?? null) ?? $classes->first();
        $from = $validated['from'] ?? $year?->starts_on->toDateString();
        $to = $validated['to'] ?? $year?->ends_on->toDateString();

        return Inertia::render('lessons/ServiceReport', [
            'classes' => $classes->map(fn (SchoolClass $class): array => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ])->values()->all(),
            'selectedClass' => $selected?->ulid,
            'filters' => ['from' => $from, 'to' => $to],
            'academicYear' => $year?->label,
            'report' => $selected === null ? null : $this->report->for($teacher, $selected, $from, $to),
        ]);
    }
}
