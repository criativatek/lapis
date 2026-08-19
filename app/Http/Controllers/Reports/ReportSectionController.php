<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Reporting\ComposeReport;
use App\Services\Reporting\Writing\ReportWritingAssistant;
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
    public function __construct(
        protected ComposeReport $composer,
        protected ReportWritingAssistant $assistant,
    ) {}

    /** The teacher's own text. From here on the section is theirs. */
    public function update(Request $request, Report $report, ReportSection $section): RedirectResponse
    {
        $this->guard($report, $section);

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:20000'],
            'heading' => ['sometimes', 'string', 'max:200'],
            'included' => ['sometimes', 'boolean'],
            // Whether this save is a teacher accepting a rewrite. It changes
            // nothing about what is written — only what the audit trail records
            // (§20 of the IA brief). Deliberately a flag on the ONE endpoint
            // that writes a body, rather than a second endpoint that would have
            // to duplicate how «edited» is decided.
            'assisted' => ['sometimes', 'boolean'],
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

        // The provenance, not the text. What was accepted may have been edited
        // by hand before saving, which is exactly why the hash of what was
        // actually stored is the thing worth keeping.
        if (($data['assisted'] ?? false) && array_key_exists('body', $attributes)) {
            $this->assistant->recordAcceptance(
                $report,
                $section,
                $request->user(),
                (string) $attributes['body'],
            );
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
            $sections = $report->sections()->orderBy('position')->get();

            // EVERY SECTION IS RENUMBERED, not only the ones named.
            //
            // Renumbering just the listed ones from 10 upwards makes them
            // collide with the untouched ones, which still hold 10, 20, 30 —
            // and a tie resolves by insertion order, so a partial request
            // produces an order nobody asked for. The named ones lead, in the
            // order given; the rest follow, keeping theirs.
            $named = array_values(array_filter(
                array_map(
                    fn (string $ulid) => $sections->firstWhere('ulid', $ulid),
                    array_values($data['order']),
                ),
            ));

            $rest = $sections->reject(
                fn (ReportSection $section) => collect($named)->contains(fn (ReportSection $row) => $row->is($section)),
            );

            $position = 0;

            foreach ([...$named, ...$rest->all()] as $section) {
                $position += 10;

                // Only `position`. Nothing here touches the text, the automatic
                // text underneath it, the edited flag or the provenance (§11).
                $section->update(['position' => $position]);
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
