<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Reporting\ComposeReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Editing one section at a time (§44).
 *
 * FOUR VERBS, AND THEY ARE NOT THE SAME. Editing writes the teacher's text and
 * marks the section as theirs. Regenerating asks the composers again, from the
 * data as it stands NOW. Restoring puts back the words that were generated the
 * last time the section was composed — the data as it stood THEN. Toggling
 * decides whether the section prints at all, without destroying anything.
 *
 * REGENERATING ONE SECTION NEVER REGENERATES THE REPORT. A teacher who is
 * unhappy with one paragraph must not lose the thirteen they already reviewed.
 */
class ReportSectionController extends Controller
{
    public function __construct(protected ComposeReport $composer) {}

    /** The teacher's own text. From here on the section is theirs. */
    public function update(Request $request, Report $report, ReportSection $section): RedirectResponse
    {
        $this->guard($report, $section);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'heading' => ['sometimes', 'string', 'max:200'],
            'included' => ['sometimes', 'boolean'],
        ]);

        $attributes = [];

        if (array_key_exists('body', $data)) {
            $body = trim((string) $data['body']);
            $attributes['body'] = $body === '' ? null : $body;
            // «Edited» means the printed text is no longer what the system
            // wrote. Typing the generated sentence back in by hand still counts
            // as theirs, and that is the right answer: they reviewed it.
            $attributes['edited'] = $attributes['body'] !== $section->generated_body;
        }

        if (array_key_exists('heading', $data)) {
            $attributes['heading'] = $data['heading'];
        }

        if (array_key_exists('included', $data)) {
            $attributes['included'] = (bool) $data['included'];
        }

        if ($attributes !== []) {
            $section->update($attributes);
        }

        return back();
    }

    /** Ask the composer again, from the data as it stands now. */
    public function regenerate(Report $report, ReportSection $section): RedirectResponse
    {
        $this->guard($report, $section);

        $this->composer->regenerate($section);

        return back();
    }

    /** Put back the last automatic text, without re-reading anything. */
    public function restore(Report $report, ReportSection $section): RedirectResponse
    {
        $this->guard($report, $section);

        $this->composer->restore($section);

        return back();
    }

    /**
     * Reordering (§44).
     *
     * Positions are rewritten in one transaction from the order given, so a
     * half-applied move cannot leave two sections claiming the same place.
     */
    public function reorder(Request $request, Report $report): RedirectResponse
    {
        Gate::authorize('update', $report);

        $data = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['string'],
        ]);

        DB::transaction(function () use ($report, $data): void {
            $position = 0;

            foreach ($data['order'] as $ulid) {
                $section = $report->sections()->where('ulid', $ulid)->first();

                if ($section !== null) {
                    $section->update(['position' => $position += 10]);
                }
            }
        });

        return back();
    }

    protected function guard(Report $report, ReportSection $section): void
    {
        Gate::authorize('update', $report);

        // The section has to belong to the report in the URL. Without this a
        // valid ulid from another report would be editable by anyone who can
        // edit any report.
        abort_if((int) $section->report_id !== (int) $report->id, 404);
    }
}
