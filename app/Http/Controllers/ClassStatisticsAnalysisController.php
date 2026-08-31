<?php

namespace App\Http\Controllers;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Models\User;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Assessment\Ai\ClassAnalyst;
use App\Services\Assessment\BuildClassStatistics;
use App\Support\Assessment\AssessmentCutoff;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Analisar com IA» on the Estatística page — one POST, one reading, nothing
 * written.
 *
 * A SIBLING OF `ClassStatisticsController`, NOT A METHOD ON IT, for the same
 * reason `HelpAssistantController` is a sibling of `HelpController`: that
 * controller's docblock promises ONE call to the read model and nothing else,
 * and it should go on being able to promise it.
 *
 * IT READS THE STATISTICS THE PAGE IS SHOWING, not a second opinion about
 * them. The same `BuildClassStatistics`, the same period resolution, the same
 * `ate` cutoff — so the sentence a teacher reads under the charts is a
 * sentence about the charts, and a reading requested with a date filter
 * applied cannot silently describe the unfiltered class.
 *
 * NO WRITE PATH EXISTS FROM HERE. The response is four blocks of text flashed
 * into the session and gone on the next visit. Nothing in this file, and
 * nothing in `ClassAnalyst`, touches a result, a classification, a weight or
 * a criterion — the AI suggests, the teacher decides, and the teacher decides
 * through the forms that already exist.
 */
class ClassStatisticsAnalysisController extends Controller
{
    public function __construct(
        protected BuildClassStatistics $statistics,
        protected ClassAnalyst $analyst,
    ) {}

    public function store(Request $request, SchoolClass $class, ?AcademicPeriod $period = null): RedirectResponse
    {
        Gate::authorize('view', $class);

        // The same free query the page itself validates, so «dados até» is
        // carried into the reading rather than dropped at the boundary.
        $validated = $request->validate([
            'ate' => ['nullable', 'date_format:Y-m-d'],
        ]);

        if (! $this->analyst->isAvailable()) {
            return $this->failed(self::unavailableMessage($this->analyst->unavailableReason()));
        }

        $statistics = $this->statistics->for($class, $period, AssessmentCutoff::on($validated['ate'] ?? null));

        // A year with no results anywhere has no period to read and nothing to
        // interpret. Told plainly rather than sent to an engine that would
        // have to invent something to say.
        if (($statistics['selected_period'] ?? null) === null
            || (int) ($statistics['summary']['students_with_result'] ?? 0) === 0) {
            return $this->failed('Ainda não há resultados registados suficientes para uma análise neste período.');
        }

        try {
            $analysis = $this->analyst->analyse($class, $statistics, $this->user());
        } catch (AiUnavailable $exception) {
            // `isAvailable()` was checked above; this only fires in a race no
            // ordinary request hits. Worded here rather than through
            // `publicMessage()`, which names «o apoio à redação».
            return $this->failed(self::unavailableMessage($exception->reason()));
        } catch (AiQuotaExceeded $exception) {
            // A ceiling, not a failure. `publicMessage()` already says whether
            // the exhausted window is the teacher's day or the school's month.
            return $this->failed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            return $this->failed($exception->publicMessage(), $exception->isRetryable());
        }

        return back()->with('aiAnalysis', [
            // Which reading this is OF, so a panel left open while the period
            // selector moves cannot present an old analysis as the new
            // period's.
            'period_id' => $statistics['selected_period']['id'] ?? null,
            'period_label' => $statistics['selected_period']['label'] ?? null,
            ...$analysis->toArray(),
        ]);
    }

    /**
     * A controlled failure: a sentence a teacher may read, on a page that
     * still works. Never a 500, never a raw exception, never a status code.
     *
     * THE BUTTON IS NOT ALWAYS STILL THERE, which is the one thing that has
     * changed. `retryable` is false when the engine failed for a reason that is
     * arithmetic rather than weather — an answer that did not fit in its token
     * budget, a rejected credential — and offering «Tentar novamente» for one
     * of those is the interface promising something it already knows will not
     * happen, at the school's expense. See `AiRequestFailed::isRetryable()`.
     */
    protected function failed(string $message, bool $retryable = true): RedirectResponse
    {
        return back()->with('aiAnalysisError', ['message' => $message, 'retryable' => $retryable]);
    }

    /**
     * A reason slug from the gateway, as a sentence a teacher can act on.
     * Three outcomes rather than the gateway's seven — see
     * `HelpAssistantController::unavailableMessage()` for why a teacher is not
     * told which setting is missing.
     */
    public static function unavailableMessage(?string $reason): string
    {
        return match ($reason) {
            'plan' => 'A análise pedagógica com IA não está incluída no plano desta organização.',
            'off' => 'A análise pedagógica com IA não está ativada nesta instalação.',
            default => 'A análise pedagógica com IA não está configurada nesta instalação.',
        };
    }

    /**
     * The same narrowing helper `StudentProgressController` already uses for
     * its own AI action: the route is behind `auth`, so the user is never
     * null here, and the analyst is entitled to say so in its signature.
     */
    protected function user(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}
