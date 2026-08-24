<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\ApplyLessonSequence;
use App\Actions\Lessons\LessonCopyOptions;
use App\Actions\Lessons\SaveLessonSequence;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\LessonSequenceApplyRequest;
use App\Http\Requests\Lessons\LessonSequenceRequest;
use App\Models\AcademicYear;
use App\Models\LessonSequence;
use App\Models\LessonSequenceItem;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\Lessons\LessonSequenceApplicationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Gate;
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

    public function apply(
        LessonSequenceApplyRequest $request,
        LessonSequence $lessonSequence,
        ApplyLessonSequence $applyLessonSequence,
    ): RedirectResponse {
        $this->refuseDuringImpersonation($request);

        $class = SchoolClass::query()->findOrFail($request->integer('class_id'));
        $options = LessonCopyOptions::fromArray($request->validated());

        try {
            $result = $applyLessonSequence->execute($lessonSequence, $class, $this->user($request), $options);
        } catch (LessonSequenceApplicationException $exception) {
            return back()->withErrors(['class_id' => $exception->getMessage()]);
        }

        $message = $result->applied === 1
            ? 'Sequência aplicada a 1 aula.'
            : "Sequência aplicada a {$result->applied} aulas.";

        if ($result->preserved > 0) {
            $message .= $result->preserved === 1
                ? ' 1 aula já tinha conteúdo próprio e foi preservada.'
                : " {$result->preserved} aulas já tinham conteúdo próprio e foram preservadas.";
        }

        if ($result->unavailable > 0) {
            $message .= $result->unavailable === 1
                ? ' 1 item sem aula disponível.'
                : " {$result->unavailable} itens sem aula disponível.";
        }

        $hasWarning = $result->preserved > 0 || $result->unavailable > 0;

        Inertia::flash('toast', ['type' => $hasWarning ? 'warning' : 'success', 'message' => $message]);

        return back();
    }

    /**
     * @return array{
     *     ulid: string,
     *     name: string,
     *     subject: array{id: int, label: string},
     *     academic_year: array{id: int, label: string},
     *     grade_level: string|null,
     *     items: list<array{ulid: string, summary: string, private_notes: string|null, resources: string|null, homework: string|null}>,
     *     applicable_classes: list<array{id: int, label: string}>,
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
        ];
    }

    /**
     * The classes this teacher may apply the sequence to, pre-filtered
     * server-side by the same compatibility rule ApplyLessonSequence itself
     * enforces (subject, and grade_level when the sequence declares one) —
     * so the picker never offers a choice the action would then reject.
     *
     * @return list<array{id: int, label: string}>
     */
    protected function applicableClassesFor(User $user, LessonSequence $sequence): array
    {
        return array_values(SchoolClass::query()
            ->where('subject_id', $sequence->subject_id)
            ->when($sequence->grade_level !== null, fn ($query) => $query->where('grade_level', $sequence->grade_level))
            ->whereHas('teachers', fn ($query) => $query->whereKey($user->getKey()))
            ->orderBy('label')
            ->get(['id', 'label'])
            ->map(fn (SchoolClass $class) => ['id' => $class->id, 'label' => $class->label])
            ->all());
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
