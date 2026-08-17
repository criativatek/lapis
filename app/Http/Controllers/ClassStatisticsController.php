<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildClassStatistics;
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
    public function __construct(protected BuildClassStatistics $statistics) {}

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
            'statistics' => $this->statistics->for($class, $period, $cutoff),
        ]);
    }
}
