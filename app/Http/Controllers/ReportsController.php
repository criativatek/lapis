<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Classification;
use App\Models\ClassificationScope;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The classification sheet (pauta) — the artifact that closes the cycle. It shows
 * the DECIDED grades (confirmed or published), never proposals: a report reflects
 * what the teacher settled, per student and period, and can be printed or exported.
 *
 * This reads from `classifications`; the fuller report aggregate (§13 — draft →
 * reviewed → approved → exported, pulling self-assessments and evidence) is a
 * later phase and lives outside this slice.
 */
class ReportsController extends Controller
{
    public function __construct(protected AuditLog $audit) {}

    public function index(): Response
    {
        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($this->user()->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
            ]);

        return Inertia::render('reports/Index', ['classes' => $classes]);
    }

    public function show(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        $pauta = $this->pauta($class);

        return Inertia::render('reports/Pauta', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
            ],
            'periods' => $pauta['periods'],
            'rows' => $pauta['rows'],
        ]);
    }

    public function export(SchoolClass $class): HttpResponse
    {
        Gate::authorize('view', $class);

        $pauta = $this->pauta($class);

        // Audit (§22.5): a bulk export of student grades leaves a trail.
        $this->audit->record('report.exported', $class, summary: "Pauta de {$class->label} exportada em CSV.");

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Não foi possível gerar o ficheiro CSV.');
        }
        fputcsv($handle, ['Nº', 'Aluno', ...$pauta['periods']]);

        foreach ($pauta['rows'] as $row) {
            fputcsv($handle, [
                $row['class_number'] ?? '',
                $row['name'],
                ...array_map(fn (array $cell) => $cell['value'] ?? '', $row['cells']),
            ]);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // A BOM so Excel opens the Portuguese accents in UTF-8 correctly.
        $filename = 'pauta_'.str($class->label)->slug().'_'.str($class->academicYear->label)->slug().'.csv';

        return response("\xEF\xBB\xBF".$csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * @return array{periods: array<int, string>, rows: array<int, array{name: string, class_number: int|null, cells: array<int, array{value: string|null, status: string|null}>}>}
     */
    protected function pauta(SchoolClass $class): array
    {
        $periods = AcademicPeriod::query()
            ->where('academic_year_id', $class->academic_year_id)
            ->orderBy('sequence')->get();

        $enrollments = $class->enrollments()->with('student.identity')->orderBy('class_number')->get();

        // Only decided classifications enter a report — a proposal is not a grade.
        $decided = Classification::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->where('scope', ClassificationScope::Period)
            ->whereIn('status', [ClassificationStatus::Confirmed->value, ClassificationStatus::Published->value])
            ->get()
            ->keyBy(fn (Classification $classification) => $classification->enrollment_id.':'.$classification->academic_period_id);

        $rows = $enrollments->map(fn (Enrollment $enrollment) => [
            'name' => optional($enrollment->student->identity)->display_name ?? '(sem identidade)',
            'class_number' => $enrollment->class_number,
            'cells' => $periods->map(function (AcademicPeriod $period) use ($decided, $enrollment): array {
                /** @var Classification|null $classification */
                $classification = $decided->get($enrollment->id.':'.$period->id);

                return [
                    'value' => $classification?->final_value,
                    'status' => $classification?->status->value,
                ];
            })->all(),
        ])->all();

        return ['periods' => $periods->map(fn (AcademicPeriod $period) => (string) $period->label)->all(), 'rows' => $rows];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
