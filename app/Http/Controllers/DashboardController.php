<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\AcademicYearStatus;
use App\Models\AssessmentProfile;
use App\Models\Classification;
use App\Models\ClassificationStatus;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\SchoolClass;
use App\Models\StudentItemScore;
use App\Models\Subject;
use App\Models\User;
use App\Services\Evidence\Followup\AssessmentPeriodTimeline;
use App\Services\Evidence\Followup\RecordFollowupCaseBook;
use App\Support\Entitlements\Entitlements;
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
    public function __invoke(AcademicYearRetentionClassifier $retentionClassifier, RecordFollowupCaseBook $followupCases, Entitlements $entitlements): Response
    {
        $teacher = $this->user();

        $academicYears = AcademicYear::query()->get();
        $currentAcademicYear = $retentionClassifier->currentYearFor($academicYears);
        $hasSubjects = Subject::query()->exists();

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

        // Só as turmas que continuam nas listas entram nos cartões e nos totais:
        // uma turma arquivada com propostas antigas por confirmar não é trabalho
        // de hoje, e contá-la punha-a no «Agora» (0.179.0). A prontidão do ano,
        // os primeiros passos e os alertas continuam a ler todas as turmas.
        $classCards = $classes->filter(fn (SchoolClass $class): bool => ! $class->isArchived())->values()->map(function (SchoolClass $class) use ($counts) {
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
                $hasSubjects,
            ),
            'firstSteps' => $this->firstSteps($teacher, $currentAcademicYearClasses, $academicYears->isNotEmpty(), $hasSubjects),
            'recordAlerts' => $this->recordAlerts($followupCases, $entitlements, $currentAcademicYear, $currentAcademicYearClasses),
            'totals' => [
                'classes' => $classCards->count(),
                'pending_confirmation' => $classCards->sum('pending_confirmation'),
                'pending_publication' => $classCards->sum('pending_publication'),
            ],
        ]);
    }

    /**
     * The accumulation alerts still «por tratar» in this year's classes — the
     * persistent notice of the logbook follow-up (spec 2026-10-12). Only the
     * alerts an operation actually raised: cases that predate alerts are found
     * in Registos, never pushed here.
     *
     * @param  Collection<int, SchoolClass>  $currentAcademicYearClasses
     * @return array{pending: int, href: string}|null
     */
    private function recordAlerts(RecordFollowupCaseBook $followupCases, Entitlements $entitlements, ?AcademicYear $currentAcademicYear, Collection $currentAcademicYearClasses): ?array
    {
        if ($currentAcademicYear === null || $currentAcademicYearClasses->isEmpty() || ! $entitlements->canRead('records')) {
            return null;
        }

        $pending = $followupCases->pendingAlerts(
            array_values($currentAcademicYearClasses->map(fn (SchoolClass $class): int => $class->id)->all()),
            AssessmentPeriodTimeline::forYear($currentAcademicYear->id),
            withDetails: false,
        )->count();

        return $pending === 0 ? null : ['pending' => $pending, 'href' => '/records?year='.$currentAcademicYear->ulid];
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
        bool $hasSubjects,
    ): array {
        $hasActiveAcademicYear = $academicYears->contains(
            fn (AcademicYear $academicYear): bool => $academicYear->status === AcademicYearStatus::Active
        );
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

    /**
     * "Primeiros passos" (A1a, Onboarding & Help) — whether this teacher has
     * actually STARTED USING the app, not whether the account is configured.
     * A deliberately different question from readiness() above, kept in its
     * own method/prop/ids so the two never influence one another — item 1
     * happens to read the same underlying fact as readiness()'s own `class`
     * item (reusing the collection already computed in __invoke(), not a
     * second query), but that is the only thing they share.
     *
     * Same principle as readiness(): every item is derived live from whether
     * the real thing already exists, never a stored checkbox. The one piece
     * of state this feature persists at all — `onboarding_dismissed_at` — only
     * ever says whether the card is hidden; it is never read to decide
     * whether a step is complete.
     *
     * @param  Collection<int, SchoolClass>  $currentAcademicYearClasses
     * @return array{dismissed: bool, all_done: bool, items: list<array{id: string, name: string, description: string, completed: bool, cta: array{label: string, href: string}|null}>}
     */
    private function firstSteps(User $teacher, Collection $currentAcademicYearClasses, bool $hasAcademicYear, bool $hasSubjects): array
    {
        $hasClass = $currentAcademicYearClasses->isNotEmpty();
        $hasStudent = Enrollment::query()->active()->exists();
        $hasInstrument = Instrument::query()->exists();
        $hasResult = StudentItemScore::query()->exists();

        // Only fetched when actually needed for a CTA target below — never to
        // decide completeness, which the exists() checks above already settled.
        $classForEnrollment = $hasClass && ! $hasStudent ? $currentAcademicYearClasses->first() : null;
        $instrumentForResult = $hasInstrument && ! $hasResult ? Instrument::query()->latest('id')->first() : null;

        [$classDescription, $classCta] = $hasClass
            ? ['Já criou a sua primeira turma.', null]
            : $this->firstClassStep($teacher, $hasAcademicYear, $hasSubjects);

        $items = [
            [
                'id' => 'class',
                'name' => 'Turma criada',
                'description' => $classDescription,
                'completed' => $hasClass,
                'cta' => $classCta,
            ],
            [
                'id' => 'student',
                'name' => 'Aluno inscrito',
                'description' => $hasStudent ? 'Já tem pelo menos um aluno inscrito.' : 'Inscreva o primeiro aluno numa turma.',
                'completed' => $hasStudent,
                'cta' => $classForEnrollment === null
                    ? null
                    : ['label' => 'Inscrever aluno', 'href' => route('classes.show', $classForEnrollment)],
            ],
            [
                'id' => 'instrument',
                'name' => 'Instrumento criado',
                'description' => $hasInstrument ? 'Já criou um elemento de avaliação.' : 'Crie o primeiro elemento de avaliação.',
                'completed' => $hasInstrument,
                'cta' => $hasInstrument ? null : ['label' => 'Criar elemento de avaliação', 'href' => route('instruments.create-picker')],
            ],
            [
                'id' => 'result',
                'name' => 'Resultado registado',
                'description' => $hasResult ? 'Já registou pelo menos um resultado.' : 'Registe o primeiro resultado na grelha de correção.',
                'completed' => $hasResult,
                'cta' => $instrumentForResult === null
                    ? null
                    : ['label' => 'Registar resultado', 'href' => route('instruments.show', $instrumentForResult)],
            ],
        ];

        return [
            'dismissed' => $teacher->hasDismissedOnboarding(),
            'all_done' => collect($items)->every(fn (array $item): bool => $item['completed']),
            'items' => $items,
        ];
    }

    /**
     * A class belongs to an academic year and a subject, so «Criar turma» is
     * only the right next step once both exist — otherwise the form opens with
     * two empty required selects. Same order as readiness(): year, then
     * subjects. Only the organization's owner can create either (the policies),
     * so for anyone else the step says whom to ask instead of offering a
     * button that ends in a 403.
     *
     * @return array{0: string, 1: array{label: string, href: string}|null}
     */
    private function firstClassStep(User $teacher, bool $hasAcademicYear, bool $hasSubjects): array
    {
        if (! $hasAcademicYear) {
            return $teacher->can('create', AcademicYear::class)
                ? ['Antes da primeira turma, crie o ano letivo.', ['label' => 'Criar ano letivo', 'href' => route('academic-years.create')]]
                : ['Ainda não existe um ano letivo. Peça ao responsável da organização que o crie.', null];
        }

        if (! $hasSubjects) {
            return $teacher->can('create', Subject::class)
                ? ['Antes da primeira turma, adicione as disciplinas que leciona.', ['label' => 'Adicionar disciplina', 'href' => route('subjects.index')]]
                : ['Ainda não existem disciplinas. Peça ao responsável da organização que as adicione.', null];
        }

        return ['O primeiro passo: crie a sua primeira turma.', ['label' => 'Criar turma', 'href' => route('classes.create')]];
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
