<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * The engine was asked and did not answer usefully.
 *
 * TWO MESSAGES, ON PURPOSE. `getMessage()` is for the log and carries the
 * technical reason; `publicMessage()` is what a teacher may read and never
 * carries a status code, an endpoint, a vendor name or a stack trace (§49).
 *
 * NEITHER EVER CARRIES THE KEY (§47). The constructors below are the only way to
 * build one, and none of them accepts credentials — a provider that wants to
 * report «401» reports the number, not the header it sent.
 *
 * A THIRD THING, ADDED FOR THE AI CORE: `category()`. The two messages are for
 * people; the category is for the machine — it is what
 * `ai_usage_events.error_category` stores (§7), and what the backoffice's
 * «testar ligação» reads to tell an OPERATOR that a credential was rejected.
 * That distinction is deliberate: a teacher must never be told which of a dozen
 * things went wrong at the vendor, and an operator debugging a key is useless
 * without it. The category is a closed vocabulary written here, never a vendor
 * string passed through.
 *
 * A FOURTH THING: `isRetryable()`. Some of these failures are weather — a
 * timeout, a 503, a rate limit — and pressing the button again is a reasonable
 * thing to do. Others are ARITHMETIC: an answer that did not fit in its token
 * budget will not fit on the second press either. Offering «Tentar novamente»
 * for one of those is not a neutral courtesy — it is the interface telling a
 * teacher something untrue, and charging the school for each demonstration. The
 * distinction is drawn here, in the one place that knows which failure it was.
 *
 * A NOTE ON THE COPY. These sentences are read by SIX different features — the
 * rewrite, the help assistant, two analyses, the synthesis and the strategy
 * suggester — so they name none of them, and in particular they no longer
 * promise that «o texto atual foi preservado». That was true where it was
 * written, beside a paragraph the teacher had already typed, and false
 * everywhere else: there is no previous text under a synthesis of a student's
 * Evolução, and reassuring somebody that nothing was lost is a strange thing to
 * say when nothing was ever there. `ReportRewriteController` adds that sentence
 * back on the one screen where it means something.
 */
class AiRequestFailed extends RuntimeException
{
    /** Every value `category()` can return. Closed — a new one is added here or not at all. */
    public const CATEGORIES = [
        'timeout',
        'unauthorized',
        'rate_limited',
        'refused',
        'provider_error',
        'unusable_answer',
        'truncated_answer',
        'unparsable_answer',
        'unreachable',
        'misconfigured_budget',
    ];

    /**
     * The categories a second attempt cannot fix.
     *
     * `truncated_answer` is the reason this list exists. It means the engine ran
     * out of output budget, which is a function of the configured ceiling, the
     * model's thinking cost and the length of the instruction — three things
     * that are identical on the next press of the button. An operator can change
     * it; a teacher cannot.
     *
     * `unparsable_answer` is here for a subtly different reason: the engine
     * answered fully and in good health, and what it said did not match the
     * shape this application requires. That is a prompt-and-model mismatch, and
     * retrying at temperature 0.2 mostly reproduces it.
     *
     * `unauthorized` is a credential, and `unusable_answer` covers the refusals
     * — a blocked prompt, an empty candidate — that come back the same way each
     * time for the same input.
     */
    public const DETERMINISTIC_CATEGORIES = [
        'unauthorized',
        'truncated_answer',
        'unparsable_answer',
        'unusable_answer',
        'misconfigured_budget',
    ];

    protected function __construct(
        string $message,
        protected string $publicMessage,
        protected string $category,
    ) {
        parent::__construct($message);
    }

    public static function timedOut(int $seconds): self
    {
        return new self(
            "The writing assistant did not answer within {$seconds}s.",
            'O pedido demorou demasiado a responder. Tente novamente dentro de instantes.',
            'timeout',
        );
    }

    public static function refused(int $status): self
    {
        // 429 is the one a teacher can act on — everything else is the same
        // sentence, because «tente mais tarde» is all any of them mean here.
        //
        // 401 AND 403 ARE DELIBERATELY NOT SPECIAL IN THE PUBLIC MESSAGE. «A
        // chave está errada» is true, useful to exactly one person in the
        // building, and an invitation to probe for everybody else; a teacher
        // gets the same sentence as for any other failure, and the fact travels
        // in the category instead — where the backoffice, and only the
        // backoffice, reads it.
        return new self(
            "The writing assistant answered {$status}.",
            $status === 429
                ? 'Estão a chegar demasiados pedidos neste momento. Tente novamente dentro de instantes.'
                : 'Não foi possível obter uma sugestão neste momento.',
            match (true) {
                $status === 429 => 'rate_limited',
                in_array($status, [401, 403], true) => 'unauthorized',
                $status >= 500 => 'provider_error',
                default => 'refused',
            },
        );
    }

    public static function unusableAnswer(string $why): self
    {
        return new self(
            "The writing assistant answered something unusable: {$why}.",
            'Não foi possível obter uma sugestão neste momento.',
            'unusable_answer',
        );
    }

    /**
     * THE ANSWER HIT THE OUTPUT CEILING. A distinct category rather than one
     * more `unusableAnswer`, because it is the one failure on this list an
     * OPERATOR can actually fix — and because it is deterministic, so the
     * interface must not offer to try again.
     *
     * `$why` is a reduced vendor enum (`MAX_TOKENS`) plus, where the provider
     * can tell, whether anything came back at all. It reaches the log and never
     * the teacher: neither the prompt nor the fragment of an answer is written
     * down anywhere by this class.
     */
    public static function truncatedAnswer(string $why): self
    {
        return new self(
            "The writing assistant ran out of output budget: {$why}.",
            'Não foi possível obter uma sugestão neste momento.',
            'truncated_answer',
        );
    }

    /**
     * THE ENGINE ANSWERED; THIS APPLICATION COULD NOT READ IT.
     *
     * Told apart from every category above on purpose. Those are the provider's
     * failures — it refused, it errored, it ran out of room. This one is a
     * disagreement about SHAPE between a prompt and a parser, both of which are
     * written here, and conflating the two would leave a meter that cannot
     * answer «is the model wrong, or is our prompt wrong?» — the first question
     * anybody debugging this feature needs to ask.
     */
    public static function unparsableAnswer(string $why): self
    {
        return new self(
            "The writing assistant answered in a shape this application could not read: {$why}.",
            'Não foi possível obter uma sugestão neste momento.',
            'unparsable_answer',
        );
    }

    /**
     * The installation's hard ceiling is below what this use case declared it
     * needs — so there is no honest number to send, and none is sent.
     *
     * WHY THIS IS A FAILURE AND NOT A CLAMP. Until 0.101.5 the gateway resolved
     * the budget as `min(ceiling, max(default, floor))`, which meant a ceiling
     * of 2048 quietly turned the síntese de acompanhamento's declared 3072 into
     * 2048 and sent it. The call then failed anyway — `truncated_answer`, every
     * time, deterministically — and the operator was left reading a truncation
     * error caused by a setting on their own screen, with nothing connecting
     * the two. A ceiling that silently rewrites a requirement is worse than one
     * that refuses it: the refusal names the setting.
     *
     * IT IS DETERMINISTIC, so no retry is offered. Pressing the button again
     * cannot raise a ceiling.
     */
    public static function misconfiguredBudget(string $useCase, int $needs, int $ceiling): self
    {
        return new self(
            "The use case {$useCase} needs {$needs} output tokens and the installation ceiling is {$ceiling}.",
            'Esta funcionalidade precisa de mais tokens de resposta do que o limite máximo configurado permite. '
                .'Um administrador tem de aumentar o limite máximo antes de a poder usar.',
            'misconfigured_budget',
        );
    }

    public static function unreachable(): self
    {
        return new self(
            'The writing assistant could not be reached.',
            'Não foi possível contactar o serviço. Tente novamente dentro de instantes.',
            'unreachable',
        );
    }

    /** Safe to show a teacher. */
    public function publicMessage(): string
    {
        return $this->publicMessage;
    }

    /**
     * One of self::CATEGORIES. Safe to store and to show an operator: it names
     * a KIND of failure, never a value, a URL, a project or a key.
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * Whether pressing the button again could plausibly produce a different
     * outcome. False means the cause is deterministic and the interface must
     * not offer a retry — see `DETERMINISTIC_CATEGORIES`.
     */
    public function isRetryable(): bool
    {
        return ! in_array($this->category, self::DETERMINISTIC_CATEGORIES, true);
    }
}
