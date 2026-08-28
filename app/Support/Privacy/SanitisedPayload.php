<?php

namespace App\Support\Privacy;

/**
 * Text that has been through `AiPayloadSanitizer`, and the record of what came
 * out of it.
 *
 * IT IS A TYPE, AND THE TYPE IS THE ENFORCEMENT (§5 of the AI Core brief).
 * `AiAsk` — the only thing `AiGateway` accepts — will not take a string. It
 * takes one of these.
 *
 * HOW FAR PHP LETS THAT GUARANTEE GO, EXACTLY. There are no friend classes and
 * no package-private visibility, so «only `AiPayloadSanitizer` may build one»
 * cannot be stated in the type system. What CAN be stated, and is:
 *
 *   1. THE CONSTRUCTOR IS PRIVATE. `new SanitisedPayload(...)` is a fatal error
 *      everywhere, including inside this namespace. The accidental bypass —
 *      writing what looks like an ordinary DTO construction and getting an
 *      unsanitised payload — is gone, because it does not compile.
 *   2. THE CLASS IS FINAL. A subclass with a widened constructor would reopen
 *      exactly the hole (1) closes.
 *   3. THE ONE FACTORY DEMANDS A SANITISER. `producedBy()` will not run without
 *      an `AiPayloadSanitizer` in hand, so the remaining path cannot be taken by
 *      somebody who does not know the sanitiser exists.
 *   4. THE ONE FACTORY IS TESTED TO HAVE ONE CALLER. `PayloadProvenanceTest`
 *      fails if `producedBy(` appears anywhere in `app/` except inside
 *      `AiPayloadSanitizer`, using the same plain-text scan over the source that
 *      `CatalogCoherenceTest` already uses for capability keys.
 *
 * What remains after all four is a deliberate act: importing the sanitiser,
 * calling a method marked `@internal`, and passing it raw text — which reads as
 * wrong at the call site and fails a test in CI. It is not possible to do it by
 * accident, and it is not possible to do it quietly. That is the whole of what
 * this language offers, and pretending otherwise would be worse than saying so.
 *
 * AND THE GATEWAY STILL DOES NOT TRUST THE TYPE. `AiGateway::send()` re-runs
 * `assertClean()` on whatever it was handed, immediately before the wire. A
 * payload that got here by any of the routes above is still checked.
 *
 * `removals` IS A COUNT PER RULE, NEVER THE REMOVED VALUES. «two emails and a
 * postal code were taken out» is what an audit line and a test need; keeping the
 * email in order to prove it was removed would be the entire problem again, in a
 * different object.
 *
 * `text` IS SAFE TO SEND, NOT SAFE TO TRUST. It has been minimised; it has not
 * been validated. Whatever a model says about it is still untrusted output.
 */
final readonly class SanitisedPayload
{
    /**
     * @param  string  $text  what may leave the building
     * @param  array<string, int>  $removals  rule name => how many times it fired
     * @param  Pseudonyms  $pseudonyms  the ephemeral map, for putting names back on the way home
     */
    private function __construct(
        public string $text,
        public array $removals,
        public Pseudonyms $pseudonyms,
    ) {}

    /**
     * The only way to build one.
     *
     * @internal Called by `AiPayloadSanitizer` and by tests that deliberately
     *           smuggle an unsanitised payload in order to prove the gateway
     *           refuses it. Any other caller is a bug, and
     *           `PayloadProvenanceTest` is what says so out loud.
     *
     * The `$sanitizer` argument is a WITNESS, not a proof: it is not consulted,
     * and it is here so the signature cannot be satisfied by somebody who has
     * not met the sanitiser. Verification deliberately does NOT happen inside
     * this factory — `assertClean()` is the gateway's job, one layer out, so
     * that a test can still construct a dirty payload and watch it be refused
     * there. Two barriers only count as two if they can be exercised apart.
     *
     * @param  array<string, int>  $removals
     */
    public static function producedBy(
        AiPayloadSanitizer $sanitizer,
        string $text,
        array $removals = [],
        ?Pseudonyms $pseudonyms = null,
    ): self {
        return new self($text, $removals, $pseudonyms ?? Pseudonyms::none());
    }

    /** Whether anything at all was taken out. */
    public function wasRedacted(): bool
    {
        return $this->removals !== [];
    }

    /** Whether names were substituted — distinct from «anything was removed». */
    public function wasPseudonymised(): bool
    {
        return ! $this->pseudonyms->isEmpty() && ($this->removals['names'] ?? 0) > 0;
    }

    /**
     * Put the real names back into an answer that came home.
     *
     * ONLY NAMES. The pattern-based removals — emails, numbers, addresses — are
     * NOT reversible and are not meant to be: a placeholder that could be turned
     * back into a phone number is a phone number that took a trip.
     */
    public function rehydrate(string $answer): string
    {
        return $this->pseudonyms->rehydrate($answer);
    }

    /**
     * What an audit or usage row may record about this payload.
     *
     * Counts and a length. Never a fragment, never a sample, never the text.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return [
            'characters' => mb_strlen($this->text),
            'removals' => $this->removals,
            'pseudonymised' => $this->wasPseudonymised(),
        ];
    }
}
