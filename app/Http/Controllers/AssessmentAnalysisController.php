<?php

namespace App\Http\Controllers;

use App\Models\SchoolClass;
use App\Services\Assessment\Advanced\AdvancedAnalyticsUnavailable;
use App\Services\Assessment\Advanced\BuildAssessmentComparison;
use App\Services\Assessment\Advanced\BuildAssessmentTimeline;
use App\Services\Assessment\Advanced\BuildClassStrengths;
use App\Support\Entitlements\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Análise da Avaliação — a class's assessment read ACROSS time (Pro).
 *
 * ONE PAGE, GROWING BY SECTION. It holds the comparison between two moments (D1) and the strengths
 * and difficulties of one moment (D3); the trends and the document come in as further sections of the
 * same page, over the same timeline. Nothing here reads anything the timeline does not, and
 * nothing is computed here: the controller authorises, validates the two moments asked for,
 * asks for the timeline and for the comparison, and hands them to the page.
 *
 * PRO, AT THREE LEVELS. The route carries `module:advanced_analytics`. The route's gate lets a
 * SAFE request through for a read-only (suspended) organization — that is right for the data
 * that stays readable and wrong for a new reading — so the controller asks the entitlement
 * itself and answers like the gate does (a 403 «não incluída»), and the services refuse in turn.
 * A Base organization never gets as far as the controller; a suspended one never gets as far as
 * the timeline.
 *
 * NAMES, FOR WHO MAY SEE THEM. The page lists the class's students by name, like Estatística
 * and Evolução do Aluno do: whoever may `view` the class (its teachers) and nobody else. The
 * organization's tenancy is the global scope on the bound class.
 */
class AssessmentAnalysisController extends Controller
{
    /** A point key as the timeline writes it: `period:<ulid>` or `moment:<ulid>`. */
    private const POINT_KEY = '/^(period|moment):[0-9a-z]{26}$/';

    public function __construct(
        protected BuildAssessmentTimeline $timeline,
        protected BuildAssessmentComparison $comparison,
        protected BuildClassStrengths $strengths,
        protected Entitlements $entitlements,
    ) {}

    public function show(Request $request, SchoolClass $class): Response|HttpResponse
    {
        Gate::authorize('view', $class);

        $validated = $request->validate([
            'de' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
            'para' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
            // Pontos fortes e dificuldades: um único momento, sem depender da comparação.
            'momento' => ['nullable', 'string', 'regex:'.self::POINT_KEY],
        ]);

        if (! $this->entitlements->allows(BuildAssessmentTimeline::ANALYTICS_MODULE)) {
            return $this->unavailable($request);
        }

        try {
            $timeline = $this->timeline->for($class);
            $comparison = $this->comparison->between($timeline, $validated['de'] ?? null, $validated['para'] ?? null);
            $strengths = $this->strengths->for($timeline, $validated['momento'] ?? null);
        } catch (AdvancedAnalyticsUnavailable) {
            return $this->unavailable($request);
        }

        $scale = $class->profileVersion?->scale;

        return Inertia::render('assessment-analysis/Show', [
            'schoolClass' => [
                'ulid' => $class->ulid,
                'label' => $class->label,
                'subject' => $class->subject->name,
                'has_profile' => $class->assessment_profile_version_id !== null,
                'scale_name' => $scale?->name,
            ],
            'availability' => $timeline['availability'],
            'comparison' => $comparison,
            'strengths' => $strengths,
        ]);
    }

    /** The same answer `RequireModule` gives to a plan boundary: a real page, still a 403. */
    private function unavailable(Request $request): HttpResponse
    {
        return Inertia::render('ModuleUnavailable', [
            'canManagePlan' => $request->user()?->ownsCurrentOrganization() ?? false,
        ])->toResponse($request)->setStatusCode(HttpResponse::HTTP_FORBIDDEN);
    }
}
