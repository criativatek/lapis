<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\ApplyLessonSequence;
use App\Actions\Lessons\ApplyLessonSequenceResult;
use App\Actions\Lessons\LessonCopyOptions;
use App\Actions\Lessons\SaveLessonSequence;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonSequenceApplyRequest;
use App\Http\Requests\Lessons\LessonSequenceRequest;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\LessonSummary;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Lessons\LessonSequenceApplicationException;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reusable lesson sequences (Fatia 4) — deliberately secondary to the weekly
 * /lessons view, not a replacement for it. A single page lists, creates,
 * edits and applies a teacher's own sequences; there is no separate wizard.
 */
class LessonSequenceController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    /**
     * @return list<string>
     */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', LessonSequence::class);

        $user = $this->user($request);

        $sequences = LessonSequence::query()
            ->where('user_id', $user->getKey())
            ->with(['subject', 'academicYear', 'items'])
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (LessonSequence $sequence) => $this->sequenceRow($sequence, $user));

        return Inertia::render('lessons/sequences/Index', [
            'sequences' => $sequences,
            'subjects' => Subject::orderBy('name')->get(['id', 'name'])
                ->map(fn (Subject $subject) => ['id' => $subject->id, 'label' => $subject->name]),
            'academicYears' => AcademicYear::orderByDesc('starts_on')->get(['id', 'label'])
                ->map(fn (AcademicYear $year) => ['id' => $year->id, 'label' => $year->label]),
        ]);
    }

    public function store(LessonSequenceRequest $request, SaveLessonSequence $saveLessonSequence): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $saveLessonSequence->execute(
            null,
            $request->safe()->only(['name', 'subject_id', 'academic_year_id', 'grade_level']),
            $request->validated('items'),
            $this->user($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sequência guardada.']);

        return to_route('lessons.sequences.index');
    }

    public function update(
        LessonSequenceRequest $request,
        LessonSequence $lessonSequence,
        SaveLessonSequence $saveLessonSequence,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $saveLessonSequence->execute(
            $lessonSequence,
            $request->safe()->only(['name', 'subject_id', 'academic_year_id', 'grade_level']),
            $request->validated('items'),
            $this->user($request),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sequência guardada.']);

        return to_route('lessons.sequences.index');
    }

    public function destroy(Request $request, LessonSequence $lessonSequence): RedirectResponse
    {
        Gate::authorize('delete', $lessonSequence);
        $this->refuseDuringImpersonation($request);

        $lessonSequence->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Sequência eliminada.']);

        return to_route('lessons.sequences.index');
    }

    /**
     * O que «Aplicar ao calendário» faria, sem gravar nada — o MESMO plano que
     * `apply` executa, calculado dentro de uma transação que é revertida.
     */
    public function preview(
        LessonSequenceApplyRequest $request,
        LessonSequence $lessonSequence,
        ApplyLessonSequence $applyLessonSequence,
    ): JsonResponse {
        $class = SchoolClass::query()->findOrFail($request->integer('class_id'));

        try {
            $preview = $applyLessonSequence->preview(
                $lessonSequence,
                $class,
                $request->classGroupId(),
                $request->fromDate(),
                LessonCopyOptions::fromArray($request->validated()),
                $request->replaceUlids(),
                $this->user($request),
            );
        } catch (LessonSequenceApplicationException $exception) {
            throw ValidationException::withMessages(['class_id' => $exception->getMessage()]);
        }

        return response()->json($preview);
    }

    public function apply(
        LessonSequenceApplyRequest $request,
        LessonSequence $lessonSequence,
        ApplyLessonSequence $applyLessonSequence,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $class = SchoolClass::query()->findOrFail($request->integer('class_id'));

        try {
            $result = $applyLessonSequence->execute(
                $lessonSequence,
                $class,
                $request->classGroupId(),
                $request->fromDate(),
                LessonCopyOptions::fromArray($request->validated()),
                $request->replaceUlids(),
                $request->boolean('confirm_replace'),
                (string) $request->validated('plan_token'),
                $this->user($request),
            );
        } catch (LessonSequenceApplicationException $exception) {
            return back()->withErrors(['class_id' => $exception->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $this->appliedMessage($result)]);

        return back();
    }

    /**
     * As contagens REAIS da execução, nunca uma frase genérica.
     */
    protected function appliedMessage(ApplyLessonSequenceResult $result): string
    {
        $plural = fn (int $count, string $one, string $many): string => $count === 1 ? "1 {$one}" : "{$count} {$many}";

        $parts = [$plural($result->applied, 'aula preparada', 'aulas preparadas')];

        if ($result->preserved > 0) {
            $parts[] = $plural($result->preserved, 'aula preservada', 'aulas preservadas');
        }

        $untouched = $result->counts['unchanged'] + $result->counts['keep'];

        if ($untouched > 0) {
            $parts[] = $plural($untouched, 'aula sem alterações', 'aulas sem alterações');
        }

        if ($result->counts['release'] > 0) {
            $parts[] = $plural($result->counts['release'], 'aula retirada', 'aulas retiradas');
        }

        return sprintf(
            'Sequência aplicada a partir de %s: %s.',
            $result->from->format('d/m'),
            implode(', ', $parts),
        );
    }

    /**
     * @return array{
     *     ulid: string,
     *     name: string,
     *     subject: array{id: int, label: string},
     *     academic_year: array{id: int, label: string},
     *     grade_level: string|null,
     *     items: list<array{ulid: string, summary: string, private_notes: string|null, resources: string|null, homework: string|null}>,
     *     applicable_classes: list<array{id: int, label: string, groups: list<array{id: int, label: string}>}>,
     *     applications: list<array{class_id: int, class_label: string, class_group_id: int|null, group_label: string|null, lessons_count: int, first_starts_at: string, last_starts_at: string}>,
     * }
     */
    protected function sequenceRow(LessonSequence $sequence, User $user): array
    {
        return [
            'ulid' => $sequence->ulid,
            'name' => $sequence->name,
            'subject' => ['id' => $sequence->subject_id, 'label' => $sequence->subject->name],
            'academic_year' => ['id' => $sequence->academic_year_id, 'label' => $sequence->academicYear->label],
            'grade_level' => $sequence->grade_level,
            'items' => array_values($sequence->items->map(fn (LessonSequenceItem $item) => [
                'ulid' => $item->ulid,
                'summary' => $item->summary,
                'private_notes' => $item->private_notes,
                'resources' => $item->resources,
                'homework' => $item->homework,
            ])->all()),
            'applicable_classes' => $this->applicableClassesFor($user, $sequence),
            'applications' => $this->applicationsOf($sequence),
        ];
    }

    /**
     * The classes this teacher may apply the sequence to, pre-filtered
     * server-side by the same compatibility rule ApplyLessonSequence itself
     * enforces (subject, and grade_level when the sequence declares one,
     * not archived) — so the picker never offers a choice the action would
     * then reject. Each class carries its ACTIVE groups: a group has its own
     * sequence of lessons, and applying to "Turma inteira" never touches it.
     *
     * @return list<array{id: int, label: string, groups: list<array{id: int, label: string}>}>
     */
    protected function applicableClassesFor(User $user, LessonSequence $sequence): array
    {
        return array_values(SchoolClass::query()
            ->notArchived()
            ->where('subject_id', $sequence->subject_id)
            ->when($sequence->grade_level !== null, fn ($query) => $query->where('grade_level', $sequence->grade_level))
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->with(['classGroups' => fn ($query) => $query->active()])
            ->orderBy('label')
            ->get()
            ->map(fn (SchoolClass $class) => [
                'id' => $class->id,
                'label' => $class->label,
                'groups' => array_values($class->classGroups->map(fn (ClassGroup $group) => [
                    'id' => $group->id,
                    'label' => $group->label,
                ])->all()),
            ])
            ->all());
    }

    /**
     * Onde esta sequência está aplicada, lido da proveniência dos sumários —
     * por (turma, grupo). É o que deixa o ecrã oferecer «Aplicar alterações»
     * sem o professor ter de se lembrar da data a que aplicou.
     *
     * @return list<array{class_id: int, class_label: string, class_group_id: int|null, group_label: string|null, lessons_count: int, first_starts_at: string, last_starts_at: string}>
     */
    protected function applicationsOf(LessonSequence $sequence): array
    {
        $rows = LessonSummary::query()
            ->join('lessons', 'lessons.id', '=', 'lesson_summaries.lesson_id')
            ->where('lesson_summaries.lesson_sequence_id', $sequence->getKey())
            ->groupBy('lessons.class_id', 'lessons.class_group_id')
            ->selectRaw('lessons.class_id as class_id, lessons.class_group_id as class_group_id, count(*) as lessons_count, min(lessons.starts_at) as first_starts_at, max(lessons.starts_at) as last_starts_at')
            ->orderBy('first_starts_at')
            ->toBase()
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $classes = SchoolClass::query()->notArchived()->whereIn('id', $rows->pluck('class_id'))->get()->keyBy('id');
        $groups = ClassGroup::query()->whereIn('id', $rows->pluck('class_group_id')->filter())->get()->keyBy('id');

        $applications = [];

        foreach ($rows as $row) {
            $class = $classes->get($row->class_id);

            if ($class === null) {
                continue;
            }

            $group = $row->class_group_id === null ? null : $groups->get($row->class_group_id);

            $applications[] = [
                'class_id' => (int) $row->class_id,
                'class_label' => $class->label,
                'class_group_id' => $row->class_group_id === null ? null : (int) $row->class_group_id,
                'group_label' => $group?->label,
                'lessons_count' => (int) $row->lessons_count,
                'first_starts_at' => CarbonImmutable::parse($row->first_starts_at, 'Europe/Lisbon')->toIso8601String(),
                'last_starts_at' => CarbonImmutable::parse($row->last_starts_at, 'Europe/Lisbon')->toIso8601String(),
            ];
        }

        return $applications;
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
