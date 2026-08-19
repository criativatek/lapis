<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Reporting\Writing\ReportWritingAssistant;
use App\Services\Reporting\Writing\WritingMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * «Aperfeiçoar redação» — one section, one mode, one proposal (§4, §18, §19).
 *
 * IT WRITES NOTHING. The answer is flashed to the session, the editor shows it
 * beside the text that is there now, and the teacher either takes it or does
 * not. Accepting goes through the section editor that already existed, so there
 * is exactly one place in this application that writes a section's body and
 * exactly one place that decides whether it counts as edited (§19, §21).
 *
 * ITS OWN CONTROLLER, not four more lines in ReportSectionController. The
 * section editor's verbs are local and always succeed; this one leaves the
 * building, can fail in five ways that are not bugs, and is rate limited. Those
 * are different concerns and they read better apart.
 *
 * EVERY FAILURE PRESERVES THE TEXT (§26). Provider missing, timeout, 429, 500,
 * an unusable answer, a rejected one: all of them come back as a sentence and an
 * unchanged section. There is no path through this controller that modifies the
 * report.
 */
class ReportRewriteController extends Controller
{
    public function __construct(protected ReportWritingAssistant $assistant) {}

    public function store(Request $request, Report $report, ReportSection $section): RedirectResponse
    {
        Gate::authorize('update', $report);

        // The section has to belong to the report in the URL — the same guard
        // the section editor uses, for the same reason.
        abort_if((int) $section->report_id !== (int) $report->id, 404);

        $data = $request->validate([
            'mode' => ['required', Rule::enum(WritingMode::class)],
        ]);

        // Checked here as well as in the payload that decides whether to draw
        // the button. Hiding a control is presentation; this is the answer
        // (§8.2 of CLAUDE.md).
        if (! $this->assistant->mayRewrite($report, $section)) {
            return $this->failed($section, 'Esta secção não pode ser aperfeiçoada.');
        }

        try {
            $suggestion = $this->assistant->suggest(
                $report,
                $section,
                WritingMode::from($data['mode']),
                $request->user(),
            );
        } catch (AiUnavailable $exception) {
            return $this->failed($section, $exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            // The technical reason goes to the log, where it is useful; the
            // teacher gets the sentence. Neither carries the endpoint or the key
            // (§47, §48, §49).
            report($exception);

            return $this->failed($section, $exception->publicMessage());
        }

        return back()->with('rewrite', $suggestion->toArray());
    }

    /**
     * The message travels with the section it belongs to.
     *
     * Without the ulid the editor would have to guess, and would show «não foi
     * possível» under every section on the page — including the ones nobody
     * touched.
     */
    protected function failed(ReportSection $section, string $message): RedirectResponse
    {
        return back()->with('rewriteError', [
            'section' => $section->ulid,
            'message' => $message,
        ]);
    }
}
