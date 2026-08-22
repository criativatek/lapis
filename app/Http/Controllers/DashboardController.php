<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\AssessmentProfile;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Retention\AcademicYearRetentionClassifier;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher's home (§23.2): their classes, and what still needs a decision —
 * how many proposals are waiting to be confirmed and how many confirmed grades
 * are waiting to be published. It only surfaces work already created elsewhere;
 * it decides nothing.
 */
class DashboardController extends Controller
{
    public function __invoke(AcademicYearRetentionClassifier $retentionClassifier): Response
    {
        $teacher = $this->user();

        $academicYears = AcademicYear::query()->get();
        $currentAcademicYear = $retentionClassifier->currentYearFor($academicYears);

        $classes = SchoolClass::query()
            ->whereHas('teachers', fn ($query) => $query->whereKey($teacher->getKey()))
            ->with(['subject', 'academicYear'])
            ->orderByDesc('created_at')
            ->get();

        $currentAcademicYearClasses = $currentAcademicYear === null
            ? collect()
            : $classes->where('academic_year_id', $currentAcademicYear->id)->values();

        // Live classifications per (class, status) in one grouped query — the
        // enrollment join keeps it scoped to the teacher's classes; the global
        // scope keeps it scoped to the organization.
        $counts = Classification::query()
            ->join('enrollments', 'classifications.enrollment_id', '=', 'enrollments.id')
            ->whereIn('enrollments.class_id', $classes->pluck('id'))
            ->whereNot('classifications.status', ClassificationStatus::Superseded->value)
            ->selectRaw('enrollments.class_id, classifications.status, count(*) as total')
            ->groupBy('enrollments.class_id', 'classifications.status')
            ->get()
            ->groupBy('class_id');

        $classCards = $classes->map(function (SchoolClass $class) use ($counts) {
            // Keyed by the enum's string value — status is cast to an enum, so it
            // cannot be a pluck() array key directly.
            $byStatus = $counts->get($class->id, collect())
                ->mapWithKeys(fn ($row) => [$row->status->value => (int) $row->total]);

            return [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'academic_year' => $class->academicYear->label,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'pending_confirmation' => (int) $byStatus->get(ClassificationStatus::Proposed->value, 0),
                'pending_publication' => (int) $byStatus->get(ClassificationStatus::Confirmed->value, 0),
            ];
        });

        return Inertia::render('Dashboard', [
            'teacherName' => $teacher->name,
            'classes' => $classCards,
            'readiness' => $this->readiness(
                $academicYears,
                $currentAcademicYear,
                $currentAcademicYearClasses,
            ),
            'totals' => [
                'classes' => $classCards->count(),
                'pending_confirmation' => $classCards->sum('pending_confirmation'),
                'pending_publication' => $classCards->sum('pending_publication'),
            ],
        ]);
    }

    /**
     * @param  Collection<int, AcademicYear>  $academicYears
     * @param  Collection<int, SchoolClass>  $currentAcademicYearClasses
     * @return array{is_ready: bool, items: list<array{id: string, name: string, description: string, completed: bool, is_next: bool, cta: array{label: string, href: string}|null}>}
     */
    private function readiness(
        Collection $academicYears,
        ?AcademicYear $currentAcademicYear,
        Collection $currentAcademicYearClasses,
    ): array {
        $hasActiveAcademicYear = $academicYears->contains(
            fn (AcademicYear $academicYear): bool => $academicYear->status === AcademicYearStatus::Active
        );
        $hasSubjects = Subject::query()->exists();
        $hasAssessmentProfile = $currentAcademicYear !== null
            && AssessmentProfile::query()->where('academic_year_id', $currentAcademicYear->id)->exists();
        $hasClass = $currentAcademicYearClasses->isNotEmpty();
        $classWithProfile = $currentAcademicYearClasses->first(
            fn (SchoolClass $class): bool => $class->assessment_profile_version_id !== null
        );

        $draftAcademicYear = $currentAcademicYear?->status === AcademicYearStatus::Draft
            ? $currentAcademicYear
            : $academicYears->first(fn (AcademicYear $academicYear): bool => $academicYear->status === AcademicYearStatus::Draft);
        $classForProfile = $currentAcademicYearClasses->first();

        $items = collect([
            [
                'id' => 'academic_year',
                'name' => 'Ano letivo ativo',
                'description' => $hasActiveAcademicYear ? 'O ano letivo está ativo.' : 'Crie ou ative o ano letivo.',
                'completed' => $hasActiveAcademicYear,
                'cta' => $draftAcademicYear === null
                    ? ['label' => 'Criar ano letivo', 'href' => route('academic-years.create')]
                    : ['label' => 'Ativar ano letivo', 'href' => route('academic-years.edit', $draftAcademicYear)],
            ],
            [
                'id' => 'subjects',
                'name' => 'Disciplinas configuradas',
                'description' => $hasSubjects ? 'As disciplinas estão configuradas.' : 'Adicione as disciplinas que leciona.',
                'completed' => $hasSubjects,
                'cta' => ['label' => 'Configurar disciplinas', 'href' => route('subjects.index')],
            ],
            [
                'id' => 'assessment_profile',
                'name' => 'Perfil de avaliação para este ano',
                'description' => $hasAssessmentProfile ? 'Já existe um perfil para este ano.' : 'Crie ou reutilize um perfil para este ano.',
                'completed' => $hasAssessmentProfile,
                'cta' => ['label' => 'Criar ou reutilizar perfil', 'href' => route('assessment-profiles.index')],
            ],
            [
                'id' => 'class',
                'name' => 'Turma criada',
                'description' => $hasClass ? 'Já tem uma turma neste ano.' : 'Crie a primeira turma deste ano.',
                'completed' => $hasClass,
                'cta' => ['label' => 'Criar turma', 'href' => route('classes.create')],
            ],
            [
                'id' => 'class_profile',
                'name' => 'Perfil associado a uma turma',
                'description' => $classWithProfile !== null ? 'Uma turma já tem um perfil associado.' : 'Associe um perfil a uma das suas turmas.',
                'completed' => $classWithProfile !== null,
                'cta' => $classForProfile === null
                    ? null
                    : ['label' => 'Associar perfil', 'href' => route('classes.show', $classForProfile)],
            ],
        ]);

        $nextItemIndex = $items->search(fn (array $item): bool => ! $item['completed']);

        /** @param array{id: string, name: string, description: string, completed: bool, cta: array{label: string, href: string}|null} $item */
        $items = $items->map(function (array $item, int $index) use ($nextItemIndex): array {
            $isNext = $nextItemIndex !== false && $index === $nextItemIndex;

            return [
                ...$item,
                'is_next' => $isNext,
                'cta' => $isNext ? $item['cta'] : null,
            ];
        });

        return [
            'is_ready' => $nextItemIndex === false,
            'items' => array_values($items->all()),
        ];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
