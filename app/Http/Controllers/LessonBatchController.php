<?php

namespace App\Http\Controllers;

use App\Actions\Lessons\MarkLessonsAsTaughtInBatch;
use App\Http\Controllers\Concerns\RefusesDuringImpersonation;
use App\Http\Requests\Lessons\BatchTaughtRequest;
use App\Models\AcademicYear;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Retention\ResolveSelectedAcademicYear;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\ValidationException;

/**
 * «Marcar como lecionadas» em lote — hoje, a semana apresentada, as aulas
 * selecionadas, ou outro intervalo.
 *
 * OS QUATRO MODOS RESOLVEM-SE TODOS NUM SÓ INTERVALO (ou numa lista de ULIDs),
 * e daí para baixo há um caminho único: as mesmas candidatas, a mesma
 * elegibilidade, a mesma transição. «Hoje» e «Semana apresentada» não são
 * lógicas diferentes — são duas maneiras de escrever as mesmas duas datas —, e
 * tratá-las como casos distintos seria convidá-las a divergir.
 *
 * «HOJE» É O DIA REAL, e não o dia que a vista tem aberto: o ecrã navega para
 * semanas passadas e futuras, e um botão «Hoje» que seguisse a vista marcaria
 * aulas de outra semana sem o dizer. «Semana apresentada» é a que segue a
 * vista, e chama-se assim precisamente para não haver dúvida sobre qual é qual.
 */
class LessonBatchController extends Controller implements HasMiddleware
{
    use RefusesDuringImpersonation;

    public function __construct(
        protected MarkLessonsAsTaughtInBatch $batch,
        protected ResolveSelectedAcademicYear $resolveAcademicYear,
    ) {}

    /** @return list<string> */
    public static function middleware(): array
    {
        return ['module:lessons'];
    }

    /**
     * O que o lote FARIA: quantas encontrou, quantas vai marcar, e quais ficam
     * de fora e porquê (§33, §36). Sem isto, «Marcar 6 aulas?» é um número sem
     * nada por trás.
     */
    public function preview(BatchTaughtRequest $request): JsonResponse
    {
        $candidates = $this->candidates($request);

        return response()->json([
            'found' => $candidates['eligible']->count() + count($candidates['ineligible']),
            'eligible' => $candidates['eligible']->count(),
            'range' => $this->rangeLabels($request),
            'lessons' => $candidates['eligible']
                ->map(fn (Lesson $lesson): array => [
                    'ulid' => $lesson->ulid,
                    'context_label' => $lesson->contextLabel(),
                    'lesson_number' => $lesson->lesson_number,
                    'starts_at' => $lesson->starts_at->setTimezone('Europe/Lisbon')->toIso8601String(),
                ])
                ->values()
                ->all(),
            'ineligible' => array_map(
                fn (array $entry): array => [
                    'ulid' => $entry['lesson']->ulid,
                    'context_label' => $entry['lesson']->contextLabel(),
                    'starts_at' => $entry['lesson']->starts_at->setTimezone('Europe/Lisbon')->toIso8601String(),
                    'reason' => $entry['reason'],
                ],
                $candidates['ineligible'],
            ),
        ]);
    }

    public function store(BatchTaughtRequest $request): RedirectResponse
    {
        $this->refuseDuringImpersonation($request);

        $candidates = $this->candidates($request);
        $eligible = $candidates['eligible'];

        if ($eligible->isEmpty()) {
            return back()->withErrors([
                'batch' => __('Não há aulas por marcar neste período.'),
            ]);
        }

        $marked = $this->batch->execute($eligible, $this->user($request));
        $skipped = count($candidates['ineligible']);

        return back()->with('success', sprintf(
            '%d aula%s marcada%s como lecionada%s.%s',
            $marked,
            $marked === 1 ? '' : 's',
            $marked === 1 ? '' : 's',
            $marked === 1 ? '' : 's',
            $skipped === 0 ? '' : sprintf(' %d não elegível%s.', $skipped, $skipped === 1 ? '' : 'is'),
        ));
    }

    /**
     * @return array{eligible: Collection<int, Lesson>, ineligible: list<array{lesson: Lesson, reason: string}>}
     */
    private function candidates(BatchTaughtRequest $request): array
    {
        $academicYear = $this->selectedAcademicYear($request);

        if ($academicYear === null) {
            throw ValidationException::withMessages(['academic_year' => __('Selecione um ano letivo.')]);
        }

        $ulids = $request->selectedUlids();

        if ($ulids !== null) {
            return $this->batch->candidates($this->user($request), $academicYear, ulids: $ulids);
        }

        [$from, $to] = $request->range();

        return $this->batch->candidates($this->user($request), $academicYear, $from, $to);
    }

    /**
     * @return array{from: string|null, to: string|null}
     */
    private function rangeLabels(BatchTaughtRequest $request): array
    {
        if ($request->selectedUlids() !== null) {
            return ['from' => null, 'to' => null];
        }

        [$from, $to] = $request->range();

        return ['from' => $from->toDateString(), 'to' => $to->toDateString()];
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
