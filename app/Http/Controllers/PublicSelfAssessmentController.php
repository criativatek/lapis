<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\SelfAssessmentFilledBy;
use App\Services\Assessment\SelfAssessmentRecorder;
use App\Support\Entitlements\Entitlements;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The student's own, no-login entry point into a self-assessment (§15) — reached
 * only via a signed, expiring link the teacher generates and hands out (projected,
 * or pasted into Teams). There is no student account: the signature on the URL
 * *is* the authorization, so every action here must stand on its own — nothing
 * assumes an authenticated user or a resolved tenant the way the rest of the app
 * does, both of which we have to establish by hand from the class in the URL.
 */
class PublicSelfAssessmentController extends Controller
{
    public function __construct(protected SelfAssessmentRecorder $recorder) {}

    public function edit(Request $request, string $classUlid, string $periodUlid, string $enrollmentUlid): Response
    {
        return $this->withResolvedContext($classUlid, $periodUlid, $enrollmentUlid, function ($class, $period, $enrollment) use ($request) {
            return Inertia::render('self-assessments/PublicEdit', [
                ...$this->recorder->formProps($class, $period, $enrollment),
                // The exact signed URL (path + expires + signature) the form
                // posts back to — a signature covers the path and query
                // string, not the verb, so re-submitting it as a POST is
                // itself a validly signed request.
                'submitUrl' => $request->fullUrl(),
            ]);
        });
    }

    public function store(Request $request, string $classUlid, string $periodUlid, string $enrollmentUlid): RedirectResponse
    {
        $validated = $request->validate([
            'answers' => ['array'],
            'answers.*' => ['nullable', 'integer'],
            'texts' => ['array'],
            'texts.*' => ['nullable', 'string', 'max:5000'],
        ]);

        return $this->withResolvedContext($classUlid, $periodUlid, $enrollmentUlid, function ($class, $period, $enrollment) use ($request, $validated) {
            $this->recorder->save($class, $period, $enrollment, $validated, SelfAssessmentFilledBy::Student);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('Autoavaliação guardada.')]);

            // Back to this exact signed URL (same path + expires + signature),
            // never to an authenticated route the student has no access to.
            return redirect()->to($request->fullUrl());
        });
    }

    /**
     * Resolves the class by ULID with no tenant scope applied yet — there is
     * none to apply until we know which organization this class belongs to —
     * then enters that tenant for the rest of the request. The enrollment is
     * looked up *through* the class's own relation, not as a bare model, so a
     * URL cannot mix an enrollment from a different class into this one even
     * if it somehow carried a valid signature for that mismatched pairing.
     *
     * @template TReturn
     *
     * @param  callable(SchoolClass, AcademicPeriod, Enrollment): TReturn  $callback
     * @return TReturn
     */
    protected function withResolvedContext(string $classUlid, string $periodUlid, string $enrollmentUlid, callable $callback): mixed
    {
        $class = SchoolClass::withoutGlobalScope('organization')->where('ulid', $classUlid)->firstOrFail();

        return app(CurrentOrganization::class)->runFor($class->organization, function () use ($class, $periodUlid, $enrollmentUlid, $callback) {
            // Re-checked here, not just on the link-generation page: a plan
            // downgrade or a removed override must stop an already-shared
            // link from working, not just future ones from being generated.
            abort_unless(app(Entitlements::class)->allowsFor($class->organization, 'self_assessment_links'), 403);

            $period = AcademicPeriod::where('academic_year_id', $class->academic_year_id)
                ->where('ulid', $periodUlid)->firstOrFail();

            $enrollment = $class->enrollments()->where('ulid', $enrollmentUlid)->firstOrFail();

            return $callback($class, $period, $enrollment);
        });
    }
}
