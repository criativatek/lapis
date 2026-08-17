<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\InterimAssessment;
use App\Models\SchoolClass;
use App\Rules\BelongsToCurrentOrganization;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Services\Assessment\CompareInterimToPeriodFinal;
use App\Support\Assessment\DecisionScale;
use App\Support\Assessment\InterimAssessmentException;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Keeping a moment, and opening it again later.
 *
 * The photograph is taken by CaptureInterimAssessment and read straight back
 * out of the stored document — never rebuilt. Rebuilding it would mean asking
 * today's data what November looked like, which is the one thing this whole
 * feature exists to avoid.
 */
class InterimAssessmentController extends Controller
{
    public function __construct(protected CaptureInterimAssessment $capture) {}

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $validated = $request->validate([
            // The period must be one of THIS organization's, and the rule that
            // knows that is the tenant-aware one — a bare `exists:` never sees
            // the global scope and would accept another school's period.
            'academic_period_id' => ['required', new BelongsToCurrentOrganization(AcademicPeriod::class)],
            'reference_date' => ['required', 'date_format:Y-m-d'],
            // REQUIRED, and trimmed before it is judged: a name of three
            // spaces is not a name. Accents and punctuation survive untouched —
            // «Avaliação intercalar — Conselho de Turma» is a perfectly good
            // thing to call this.
            'name' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        // `whereKey(...)->firstOrFail()` rather than `findOrFail`, which is
        // typed as possibly returning a collection when handed a mixed value.
        $period = AcademicPeriod::query()->whereKey($validated['academic_period_id'])->firstOrFail();

        try {
            $interim = $this->capture->capture(
                $class,
                $period,
                Carbon::parse($validated['reference_date']),
                $request->user(),
                ['name' => $validated['name'], 'note' => $validated['note'] ?? null],
            );
        } catch (InterimAssessmentException $exception) {
            // The reason names the real boundary — the fix is always a
            // different date, and the teacher needs to know which one.
            throw ValidationException::withMessages(['reference_date' => $exception->getMessage()]);
        }

        return redirect()
            ->route('interim-assessments.show', [$class->ulid, $interim->ulid])
            ->with('success', 'Avaliação intercalar guardada.');
    }

    public function show(SchoolClass $class, InterimAssessment $interimAssessment): Response
    {
        Gate::authorize('view', $class);

        // A photograph of another class is not this class's to open, even
        // inside the same organization.
        abort_unless((int) $interimAssessment->class_id === (int) $class->id, 404);

        $scale = $class->profileVersion?->scale()->with('levels')->first();

        return Inertia::render('results/InterimAssessment', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ],
            'decision' => DecisionScale::for($scale)->toPayload(),
            // Presentation only: the export route is gated by the same
            // capability, and hiding a link is never what keeps anybody out.
            'canExportToInovar' => app(Entitlements::class)->allows('inovar_export'),
            'periodUlid' => $interimAssessment->academicPeriod->ulid,
            'interim' => [
                'ulid' => $interimAssessment->ulid,
                'name' => $interimAssessment->name,
                'reference_date' => $interimAssessment->reference_date->toDateString(),
                'reference_date_label' => $interimAssessment->reference_date->format('d/m/Y'),
                'note' => $interimAssessment->note,
                'created_at' => $interimAssessment->created_at->toIso8601String(),
                'snapshot_version' => $interimAssessment->snapshot_version,
                // A document that no longer matches its own hash is still shown,
                // and said to be suspect. Hiding it would lose the evidence.
                'is_intact' => $interimAssessment->isIntact(),
            ],
            // READ, never rebuilt.
            'snapshot' => $interimAssessment->snapshot,
        ]);
    }

    /**
     * Renaming, and nothing else.
     *
     * A NAME IS NOT HISTORY. What must never move is what the class looked like
     * — the snapshot, the date it was read at, the period it belongs to. What a
     * school calls that moment is a label on the outside, and fixing a typo in
     * it changes nothing anybody compared or exported. The model enforces the
     * line: anything but name and note still throws.
     */
    public function update(Request $request, SchoolClass $class, InterimAssessment $interimAssessment): RedirectResponse
    {
        Gate::authorize('update', $class);
        abort_unless((int) $interimAssessment->class_id === (int) $class->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $interimAssessment->update([
            'name' => trim(preg_replace('/\s+/u', ' ', $validated['name']) ?? ''),
            'note' => $validated['note'] ?? null,
        ]);

        return back()->with('success', 'Nome atualizado. A fotografia não mudou.');
    }

    /**
     * The photograph against where the period ended up.
     *
     * The interim side is READ; the final side is the canonical read model. The
     * asymmetry is stated on screen, because a period still open will move and
     * a teacher should know which of the two numbers can change.
     */
    public function compare(
        SchoolClass $class,
        InterimAssessment $interimAssessment,
        CompareInterimToPeriodFinal $comparison,
    ): Response {
        Gate::authorize('view', $class);
        abort_unless((int) $interimAssessment->class_id === (int) $class->id, 404);

        return Inertia::render('results/InterimComparison', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
            ],
            'comparison' => $comparison->compare($class, $interimAssessment),
        ]);
    }

    public function destroy(SchoolClass $class, InterimAssessment $interimAssessment): RedirectResponse
    {
        Gate::authorize('update', $class);
        abort_unless((int) $interimAssessment->class_id === (int) $class->id, 404);

        // Nothing references an interim assessment yet, so deleting one loses
        // only itself. The day Relatórios cite them, this becomes a revocation
        // rather than a delete — the model is already immutable, which is the
        // half that would be hard to add later (§44).
        $interimAssessment->delete();

        return redirect()
            ->route('results.statistics', [$class->ulid])
            ->with('success', 'Avaliação intercalar eliminada.');
    }
}
