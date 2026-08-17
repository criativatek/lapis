<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\InterimAssessment;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildClassStatistics;
use App\Services\Assessment\CaptureInterimAssessment;
use App\Support\Assessment\AssessmentCutoff;
use App\Support\Assessment\DecisionScale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Estatística: the class read as a group.
 *
 * ONE call to the read model and nothing else, exactly as the Quadro Síntese
 * does. Everything on the page — the averages, the distribution across the
 * scale, the movement between periods, the per-domain figures — is aggregated
 * there from the same canonical results the other two screens show. A number
 * computed here, or in the browser, would be a second opinion about a figure
 * that already has one (§0).
 *
 * No capability of its own. Reading a class you already have results for is not
 * a separate product from having them, and inventing an entitlement to charge
 * for it would be taking something away rather than adding it (§43).
 */
class ClassStatisticsController extends Controller
{
    public function __construct(
        protected BuildClassStatistics $statistics,
        protected CaptureInterimAssessment $capture,
    ) {}

    public function show(Request $request, SchoolClass $class, ?AcademicPeriod $period = null): Response
    {
        Gate::authorize('view', $class);

        $scale = $class->profileVersion?->scale()->with('levels')->first();

        // «Dados até». A free query, and deliberately nothing more: choosing a
        // date changes what this page shows and records nothing. Keeping a
        // moment is a separate, explicit act (§3).
        $validated = $request->validate([
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $cutoff = AssessmentCutoff::on($validated['ate'] ?? null);
        $statistics = $this->statistics->for($class, $period, $cutoff);

        // The read model decides which period is being read — including when
        // the URL named none — so the name suggested for a photograph of it is
        // asked of that same period rather than of a second guess.
        $selectedPeriod = $statistics['selected_period'] === null
            ? null
            : AcademicPeriod::query()->whereKey($statistics['selected_period']['id'])->first();

        return Inertia::render('results/Statistics', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            // The same words the other two screens use for what a class is
            // graded on, from the one place that decides them.
            'decision' => DecisionScale::for($scale)->toPayload(),
            // What the reader is looking at: nothing when it is today's picture,
            // and the date itself when it is not. The page never lets a temporal
            // cut go unannounced (§4).
            'cutoff' => [
                'date' => $cutoff->toIso(),
                'label' => $cutoff->label(),
                'is_open' => $cutoff->isOpen(),
            ],
            // Offered in the form, never applied behind the teacher's back.
            'suggestedInterimName' => $selectedPeriod === null
                ? null
                : $this->capture->suggestedName($class, $selectedPeriod),
            // What has already been kept, oldest first: a class's own timeline
            // of moments, which Relatórios will later read as one (§28).
            'interimAssessments' => InterimAssessment::query()
                ->where('class_id', $class->id)
                ->with('academicPeriod')
                ->orderBy('reference_date')
                ->get()
                ->map(fn (InterimAssessment $interim): array => [
                    'ulid' => $interim->ulid,
                    'name' => $interim->name,
                    'reference_date' => $interim->reference_date->toDateString(),
                    'reference_date_label' => $interim->reference_date->format('d/m/Y'),
                    'period_label' => $interim->academicPeriod->label,
                    'academic_period_id' => $interim->academic_period_id,
                ])->all(),
            'statistics' => $statistics,
        ]);
    }
}
