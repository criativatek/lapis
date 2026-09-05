<?php

namespace App\Http\Controllers\Records;

use App\Http\Controllers\Controller;
use App\Models\SchoolClass;
use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Evidence\Ai\IncidentDescriptionAssistant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Aperfeiçoar redação» for the disciplinary occurrence description a teacher
 * is drafting in the Registos form (SUP-U8FMAE) — the Registos equivalent of
 * `App\Http\Controllers\Reports\ReportRewriteController`.
 *
 * IT WRITES NOTHING. There is no `EvidenceRecord` yet for this call to reach —
 * the draft lives only in the teacher's browser, in a form nobody submitted —
 * so the only thing this endpoint can ever do is flash a suggestion, or an
 * error, back to the same page. Accepting is the teacher's own click on «Usar
 * sugestão», which replaces the text in the still-open form; saving the record
 * is still, and only, `EvidenceController::store()`/`update()`.
 */
class IncidentDescriptionRewriteController extends Controller
{
    public function __construct(protected IncidentDescriptionAssistant $assistant) {}

    public function store(Request $request, SchoolClass $class): RedirectResponse
    {
        Gate::authorize('update', $class);

        $data = $request->validate([
            'kind' => ['required', 'string'],
            'description' => ['required', 'string', 'max:1000'],
        ]);

        // Checked here as well as by what draws the button — hiding a control
        // is presentation, this is the answer (CLAUDE.md §8.2).
        if (! $this->assistant->mayRewrite($data['kind'], $data['description'])) {
            return $this->failed('Esta descrição não pode ser aperfeiçoada.');
        }

        try {
            $suggestion = $this->assistant->suggest($class, $data['description'], $request->user());
        } catch (AiUnavailable $exception) {
            return $this->failed($exception->publicMessage());
        } catch (AiQuotaExceeded $exception) {
            return $this->failed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            return $this->failed($this->preservingText($exception->publicMessage()));
        }

        return back()->with('incidentRewrite', $suggestion->toArray());
    }

    protected function failed(string $message): RedirectResponse
    {
        return back()->with('incidentRewriteError', ['message' => $message]);
    }

    /**
     * The one screen this reassurance is true on: the teacher's own draft is
     * still in the form, untouched, because nothing here ever writes to it.
     */
    protected function preservingText(string $message): string
    {
        return rtrim($message).' '.__('O texto atual foi preservado.');
    }
}
