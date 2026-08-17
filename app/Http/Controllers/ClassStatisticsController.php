<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildClassStatistics;
use App\Support\Assessment\DecisionScale;
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

    public function show(SchoolClass $class, ?AcademicPeriod $period = null): Response
    {
        Gate::authorize('view', $class);

        $scale = $class->profileVersion?->scale()->with('levels')->first();

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
            'statistics' => $this->statistics->for($class, $period),
        ]);
    }
}
