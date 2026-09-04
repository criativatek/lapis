<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\EvaluationSheetExport;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Assessment\CaptureEvaluationSheet;
use App\Support\Assessment\EvaluationSheetException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Keeping a Pauta de Avaliação, and opening it again later.
 *
 * A CONTROLLER OF ITS OWN, DELIBERATELY. `EvaluationSheetController` is
 * constructed with `BuildEvaluationSheet` and exists to show the live sheet;
 * this one is constructed without it. The rule that a snapshot is READ and
 * never rebuilt is therefore structural rather than a habit: `snapshot()` here
 * has nothing to rebuild it with, and a future edit cannot quietly reach for
 * the calculator because the calculator is not in the room.
 *
 * `store()` is the one method that looks at live state — that is precisely what
 * taking a photograph is — and it does so through CaptureEvaluationSheet.
 */
class EvaluationSheetHistoryController extends Controller
{
    public function __construct(
        protected CaptureEvaluationSheet $capture,
    ) {}

    public function store(Request $request, SchoolClass $class, AcademicPeriod $period): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            // The column is 200; validating to the same number means the
            // teacher is told, rather than the database truncating in silence.
            'moment_label' => ['required', 'string', 'max:200'],
            'effective_at' => ['required', 'date_format:Y-m-d'],
            'scope' => ['nullable', 'string', 'in:period,accumulated'],
        ], [], [
            'moment_label' => 'título do momento',
            'effective_at' => 'data de referência',
        ]);

        $scope = ClassificationScope::from($validated['scope'] ?? ClassificationScope::Period->value);

        try {
            $this->capture->capture(
                $class,
                $period,
                $scope,
                $this->capture->labelFor($validated['moment_label'], $period),
                Carbon::parse($validated['effective_at']),
                $this->user($request),
            );
        } catch (EvaluationSheetException $exception) {
            // The reason names the real boundary — the fix is always a
            // different date, and the teacher needs to know which one.
            throw ValidationException::withMessages(['effective_at' => $exception->getMessage()]);
        }

        return redirect()
            ->route('evaluation-sheets.history', [$class->ulid])
            ->with('success', 'Pauta guardada no histórico.');
    }

    public function history(SchoolClass $class): Response
    {
        Gate::authorize('view', $class);

        // Most recent first, `id` breaking the tie: two snapshots kept in the
        // same second are still two snapshots, and the order between them must
        // not depend on whatever order the database felt like returning.
        //
        // The whole row is fetched, payload included, because the temporal
        // labels are a historical statement and live only in the payload. A
        // JSON-path select would save bytes at the cost of a driver-specific
        // expression, and a class's history is bounded by how many times a
        // teacher pressed a button.
        $exports = EvaluationSheetExport::query()
            ->where('class_id', $class->id)
            ->with('exporter')
            ->orderByDesc('exported_at')
            ->orderByDesc('id')
            ->get();

        return Inertia::render('evaluation-sheets/History', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ],
            'entries' => $exports->map(fn (EvaluationSheetExport $export): array => $this->entry($export))->all(),
        ]);
    }

    public function snapshot(SchoolClass $class, EvaluationSheetExport $export): Response
    {
        Gate::authorize('view', $class);

        // A photograph of another class is not this class's to open, even
        // inside the same organization — without this the ulid in the URL is
        // an IDOR between colleagues.
        abort_unless((int) $export->class_id === (int) $class->id, 404);

        // FAIL CLOSED. A document that no longer matches its own hash is not
        // evidence of anything, and showing it would let a tampered pauta be
        // read as the real one. The record itself stays — the evidence that
        // something happened to it is the point.
        if (! $export->isIntact()) {
            Log::warning('Evaluation sheet snapshot failed its integrity check.', [
                'evaluation_sheet_export_ulid' => $export->ulid,
                'class_id' => (int) $export->class_id,
            ]);

            return Inertia::render('evaluation-sheets/Snapshot', [
                'schoolClass' => [
                    'ulid' => $class->ulid,
                    'label' => $class->label,
                    'subject' => $class->subject->name,
                ],
                'entry' => $this->entry($export),
                'snapshot' => null,
                'integrityFailure' => 'Esta pauta guardada já não corresponde ao registo original e por isso não é '
                    .'mostrada. O conteúdo foi alterado fora da aplicação. Guarde uma nova pauta e comunique a '
                    .'ocorrência ao suporte.',
            ]);
        }

        return Inertia::render('evaluation-sheets/Snapshot', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ],
            'entry' => $this->entry($export),
            // READ, never rebuilt. Nothing on this screen consults
            // BuildEvaluationSheet, the classifications, or today's domains.
            'snapshot' => $export->payload,
            'integrityFailure' => null,
        ]);
    }

    /**
     * One row of history, said the way the snapshot says it.
     *
     * The temporal labels come from the PAYLOAD and never from the live
     * AcademicPeriod: renaming a period afterwards must not rewrite what the
     * history claims about the past.
     *
     * @return array<string, mixed>
     */
    protected function entry(EvaluationSheetExport $export): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $export->payload;
        /** @var array{label?: string, kind_label?: string} $period */
        $period = $payload['period'] ?? [];
        /** @var array{name?: string} $author */
        $author = $payload['author'] ?? [];
        // Not typed as a list: this comes back out of a JSON column, and what
        // the column holds is only as well-shaped as whatever wrote it. The
        // array_values() below is the normalisation, not a formality.
        /** @var array<array-key, string> $warnings */
        $warnings = $payload['warnings'] ?? [];

        return [
            'ulid' => $export->ulid,
            'moment_label' => $export->moment_label,
            'period_label' => $period['label'] ?? null,
            'period_kind_label' => $period['kind_label'] ?? null,
            'scope' => $export->scope->value,
            'scope_label' => $export->scope->label(),
            'effective_at' => $export->effective_at?->toDateString(),
            'exported_at' => $export->exported_at->toIso8601String(),
            'author' => $author['name'] ?? $export->exporter?->name,
            'status_label' => $export->statusLabel(),
            'has_file' => $export->file_path !== null,
            'warning_count' => (int) $export->warning_count,
            'warnings' => array_values($warnings),
        ];
    }

    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
