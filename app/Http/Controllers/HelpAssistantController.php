<?php

namespace App\Http\Controllers;

use App\Services\Ai\AiRequestFailed;
use App\Services\Ai\AiUnavailable;
use App\Services\Ai\Gateway\AiQuotaExceeded;
use App\Services\Help\Ai\HelpAssistant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The «Assistente Lapispro» endpoint — one POST, one answer, nothing kept.
 *
 * A SIBLING OF `HelpController`, NOT A METHOD ON IT. That controller's own
 * docblock makes a promise this one cannot: that every method reads only a
 * query string against static article content. This one reaches an engine, so
 * it belongs beside it rather than inside it, and the privacy test that pins
 * `HelpController` down keeps meaning exactly what it says.
 *
 * `question` IS THE ONLY REQUEST INPUT THIS METHOD EVER READS. Not the rest of
 * the body, not the query string, not a class, not a student. There is no
 * parameter here through which pedagogical data could arrive even if a client
 * sent it — see `HelpAssistantTest`, which asserts exactly that against a
 * deliberately over-stuffed request.
 *
 * NO THROTTLE MIDDLEWARE ON THIS ROUTE, deliberately. `AiGateway` applies the
 * per-user and per-organization rate limit for `help_assistant` itself,
 * because it has to work for a job or a command too — and stacking a route
 * throttle on the same capability would count every request twice and halve
 * the ceiling (contract §6).
 *
 * THE ANSWER TRAVELS IN THE SESSION AND IS GONE ON THE NEXT VISIT, the same
 * shape «Aperfeiçoar redação» and «Sugestões de estratégia» already use. It is
 * never stored, never attached to the user, and refreshing the page does not
 * bring it back — an assistant with a memory is a different feature, with
 * different consent, and this is not it.
 */
class HelpAssistantController extends Controller
{
    public function __construct(protected HelpAssistant $assistant) {}

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:'.HelpAssistant::MAX_QUESTION_CHARACTERS],
        ]);

        $question = trim((string) $validated['question']);

        if ($question === '') {
            return $this->failed('Escreva uma pergunta sobre a utilização do Lapispro.');
        }

        $user = $request->user();

        if ($user === null) {
            return $this->failed('Precisa de ter sessão iniciada para usar o Assistente Lapispro.');
        }

        // Asked before the call so nobody is shown a button that can only fail
        // (§41). The gateway asks every one of these questions again on the
        // server — hiding a control is presentation, not access control.
        if (! $this->assistant->isAvailable()) {
            return $this->failed(self::unavailableMessage($this->assistant->unavailableReason()));
        }

        try {
            $answer = $this->assistant->answer($question, $user);
        } catch (AiUnavailable $exception) {
            // `isAvailable()` was checked above, so this is only reachable in a
            // race no ordinary request hits. Worded here rather than through
            // `publicMessage()`, which names «o apoio à redação» — a different
            // feature, and a confusing thing to read in the Centro de Ajuda.
            return $this->failed(self::unavailableMessage($exception->reason()));
        } catch (AiQuotaExceeded $exception) {
            // A ceiling, not a failure: trying again in a second will not help,
            // and the message says when it will. `publicMessage()` already
            // distinguishes the daily from the monthly window.
            return $this->failed($exception->publicMessage());
        } catch (AiRequestFailed $exception) {
            report($exception);

            // Never `getMessage()`, never `category()`, never a status code
            // (contract §4).
            return $this->failed($exception->publicMessage());
        }

        return back()->with('helpAnswer', [
            // Echoed back so the answer can be read next to what was asked —
            // this round trip only. Flash session data, not a record.
            'question' => $question,
            ...$answer->toArray(),
        ]);
    }

    /**
     * A reason slug from the gateway, as a sentence a teacher can act on.
     *
     * THREE OUTCOMES, NOT SEVEN. The gateway distinguishes `off` from
     * `credential_missing`, `model_missing`, `endpoint_missing`,
     * `unknown_driver` and `fake_in_production`, and the platform
     * administration needs all six. A teacher needs to know which of three
     * people can help: their school (`plan`), whoever administers the
     * installation (everything else), or nobody because it is simply switched
     * off. Naming the missing setting on a teacher's screen would be exposing
     * a technical detail to somebody who cannot act on it (§7).
     */
    public static function unavailableMessage(?string $reason): string
    {
        return match ($reason) {
            'plan' => 'O Assistente Lapispro não está incluído no plano desta organização. Os artigos do Centro de Ajuda continuam disponíveis.',
            'off' => 'O Assistente Lapispro não está ativado nesta instalação. Os artigos do Centro de Ajuda continuam disponíveis.',
            default => 'O Assistente Lapispro não está configurado nesta instalação. Os artigos do Centro de Ajuda continuam disponíveis.',
        };
    }

    /**
     * A controlled failure: a sentence a teacher may read, and a page that
     * still works. Never a 500, never a raw exception, and always retryable —
     * the form is still standing when this lands.
     */
    protected function failed(string $message): RedirectResponse
    {
        return back()->with('helpAnswerError', ['message' => $message]);
    }
}
