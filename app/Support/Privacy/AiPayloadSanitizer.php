<?php

namespace App\Support\Privacy;

use RuntimeException;

/**
 * THE ONE PLACE WHERE TEXT BECOMES SENDABLE (§5 of the AI Core brief).
 *
 * Nothing in this application may hand an AI engine material it prepared
 * itself. `AiGateway` accepts `AiAsk`, `AiAsk` accepts `SanitisedPayload`, and
 * `SanitisedPayload` is what this class returns. The policy is therefore in one
 * file instead of in every feature that ever wants to ask a model something,
 * which is the only arrangement in which «we do not send student data» can be
 * verified rather than believed.
 *
 * DEFAULT-DENY, IN THE ONLY SENSE THAT IS ACHIEVABLE ON PROSE. A structured
 * payload can be built from an allow-list of fields; free text cannot, because
 * the whole point of free text is that nobody knows what is in it. So this class
 * does three things in a fixed order and then CHECKS ITS OWN WORK:
 *
 *   1. names       the roster it was given becomes «Aluno A», «Aluno B»
 *   2. patterns    the identifier shapes below are replaced by markers
 *   3. verify      `assertClean()` re-runs the detectors and throws if anything
 *                  it knows how to recognise is still there
 *
 * Step 3 is not ceremony. Steps 1 and 2 are regular expressions over text this
 * code did not write, and a substitution that silently did nothing looks exactly
 * like a substitution that worked.
 *
 * WHAT IT REMOVES BY DEFAULT, matching the brief's list: names, emails,
 * telephone numbers, postal addresses, student numbers, external identifiers,
 * internal record identifiers (ULID/UUID), and any long run of digits — which is
 * the catch-all for a citizen-card number, a taxpayer number, a process number
 * and every other identifier nobody thought to name.
 *
 * WHAT IT DELIBERATELY LEAVES ALONE: short numbers. A percentage, a grade, an
 * age, a count of lessons, «3.º período» — these are the pedagogical substance
 * of anything worth asking about, and stripping them would leave a question no
 * model could answer. The line is drawn at SIX digits, below which a number
 * cannot be a Portuguese identity, taxpayer, phone or student number, and above
 * which it is not a grade. Reporting's own `ProtectedFacts` takes the opposite,
 * stricter position for its own use case — every figure becomes a marker — and
 * that remains right there and unchanged; this is the general policy for
 * material that has to keep its numbers to be worth sending.
 *
 * IT IS NOT ANONYMISATION AND MUST NOT BE DESCRIBED AS SUCH. See `Pseudonyms`.
 *
 * WHAT IT CANNOT DO, AND WHY IT IS THE SECOND BARRIER AND NOT THE FIRST. A name
 * it was not given, an address written in words, «o irmão da Rita da turma B» —
 * no pattern catches those. This class narrows the surface; it does not close
 * it. Anything with structure must therefore be built through `AiContext`, which
 * allowlists the fields and pseudonymises each value BEFORE any of it becomes
 * text; this class then runs over the finished string as defence in depth. A
 * pipeline whose only barrier is pattern matching over prose leaks exactly the
 * personal data nobody thought to write a pattern for.
 *
 * `sanitise()` — free prose in, payload out — remains for material that genuinely
 * has no structure to allowlist: a question a teacher typed, a section a
 * composer wrote. It is the weaker entry point, and it is not the one to reach
 * for when the caller knows what the fields are.
 *
 * Features that can carry free text written by a teacher say so on the screen,
 * and `ai_pedagogical_analysis` exists as a separate entitlement precisely so
 * that a school can decline the whole category rather than trust a regular
 * expression.
 *
 * FINAL, because `assertClean()` is a guard and a subclass that overrode it
 * would be a guard that does nothing. Nothing about these rules is meant to
 * vary by installation, by tenant or by feature.
 */
final class AiPayloadSanitizer
{
    /**
     * The detectors, in application order. Order matters: emails contain `@` and
     * dots and would otherwise be half-eaten by the digit rule, and URLs contain
     * everything.
     *
     * Each marker is Portuguese and readable, because it reaches a model that
     * has to understand that something was withheld rather than that a sentence
     * is broken.
     *
     * @var array<string, array{0: string, 1: string}> rule => [pattern, replacement]
     */
    protected const RULES = [
        // Before everything: a URL can contain an email, an id and a digit run.
        'urls' => ['~\b(?:https?://|www\.)\S+~iu', '[ligação removida]'],

        'emails' => ['/[\p{L}\p{N}._%+-]+@[\p{L}\p{N}.-]+\.[a-z]{2,}/iu', '[email removido]'],

        // Portuguese postal code. Distinctive enough to catch before digit runs,
        // and the strongest single signal that an address is being written.
        'postal_codes' => ['/\b\d{4}-\d{3}\b/u', '[código postal removido]'],

        // +351912345678, 912 345 678, 912.345.678, 912345678.
        //
        // NARROW ON PURPOSE: exactly the Portuguese nine-digit shape in 3-3-3
        // grouping, plus any international `+` run. A greedier «eight to
        // fourteen digits with optional separators» also matches «12 34 56 78
        // 90», which in a pedagogical payload is five results and not a
        // telephone. Unseparated long runs are caught by `long_numbers` below
        // anyway; what this rule adds is the separated forms, which that one
        // cannot see.
        'phone_numbers' => ['/\B\+\d{6,15}\b|\b\d{3}[ .\-]?\d{3}[ .\-]?\d{3}\b/u', '[contacto removido]'],

        // «n.º 12», «nº 12», «n.o 12», «número 12» — the shape a student number
        // is written in. Caught by shape rather than by length, because a class
        // number is one or two digits and would survive every other rule here.
        //
        // THE DOT IS MANDATORY BEFORE A BARE `o`, and that is not pedantry: an
        // earlier version accepted `n` + optional dot + `o`, which matched the
        // word «no» and turned «no 2.º período» into «[número removido].º
        // período». A rule that eats ordinary Portuguese is a rule that makes
        // every payload worse, and this one was caught by the test below rather
        // than in production.
        'record_numbers' => ['/\b(?:n\.\s*[ºo°]|n[º°]|n[uú]mero)\s*:?\s*\d+/iu', '[número removido]'],

        // ULID (26 Crockford base32) and UUID. Every identifier this application
        // exposes in a URL is one of these, and none of them is ever needed by a
        // model — «Aluno A» is what a model needs to tell two students apart.
        'identifiers' => [
            '/\b(?:[0-7][0-9ABCDEFGHJKMNPQRSTVWXYZ]{25}|[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12})\b/iu',
            '[identificador removido]',
        ],

        // The catch-all. Six digits or more, after every shaped rule above has
        // had its turn. See the class docblock for why six.
        'long_numbers' => ['/\b\d{6,}\b/u', '[número removido]'],
    ];

    /**
     * Sanitise free text.
     *
     * @param  list<string>  $names  real names to pseudonymise — the roster, when the caller has one
     *
     * @throws RuntimeException when the result still contains something the
     *                          detectors recognise. Callers do not catch this:
     *                          it means the policy failed, and a payload that
     *                          failed the policy does not get sent anyway.
     */
    public function sanitise(string $text, array $names = [], string $pseudonymPrefix = 'Aluno'): SanitisedPayload
    {
        return $this->sanitiseWith($text, Pseudonyms::of($names, $pseudonymPrefix));
    }

    /**
     * The same work, against a roster the caller already built.
     *
     * `AiContext` needs this: it pseudonymises value by value on the way in, and
     * has to hand the SAME map down so the payload can put names back afterwards
     * — and so this pass is a no-op over what is already done rather than a
     * second, differently-lettered substitution.
     *
     * `$seedRemovals` carries counts from work done before this call, so a
     * payload's summary reports the whole pipeline rather than only its last
     * stage.
     *
     * @param  array<string, int>  $seedRemovals
     *
     * @throws RuntimeException when the result still contains something the
     *                          detectors recognise.
     */
    public function sanitiseWith(string $text, Pseudonyms $pseudonyms, array $seedRemovals = []): SanitisedPayload
    {
        $removals = $seedRemovals;

        $before = $text;
        $text = $pseudonyms->apply($text);

        $replaced = $this->substitutionCount($before, $text, $pseudonyms);

        if ($replaced > 0) {
            $removals['names'] = ($removals['names'] ?? 0) + $replaced;
        }

        foreach (self::RULES as $rule => [$pattern, $replacement]) {
            $count = 0;
            $text = (string) preg_replace($pattern, $replacement, $text, -1, $count);

            if ($count > 0) {
                $removals[$rule] = ($removals[$rule] ?? 0) + $count;
            }
        }

        $payload = SanitisedPayload::producedBy($this, $text, $removals, $pseudonyms);

        $this->assertClean($payload);

        return $payload;
    }

    /**
     * A shorthand over `AiContext` for a caller that already has its fields in
     * an array.
     *
     * IT DELEGATES RATHER THAN DUPLICATING, so this path gets the ordering rule
     * for free: allowlist, pseudonymise each value, THEN serialise, THEN this
     * class as the second barrier. An earlier version of this method joined the
     * fields first and sanitised the paragraph — which made the regular
     * expressions the only barrier, the exact arrangement `AiContext` exists to
     * replace.
     *
     * Keys are labels for the model and are NEVER sanitised: they are written by
     * this application, not by a user. Values always are.
     *
     * @param  array<string, string|int|float|null>  $fields
     * @param  list<string>  $names
     */
    public function sanitiseFields(array $fields, array $names = [], string $pseudonymPrefix = 'Aluno'): SanitisedPayload
    {
        $context = $names === []
            ? AiContext::withoutPeople()
            : AiContext::about(Pseudonyms::of($names, $pseudonymPrefix));

        foreach ($fields as $label => $value) {
            $context->add($label, $value);
        }

        return $context->toPayload($this);
    }

    /**
     * Re-run every detector over a finished payload and refuse it if any fires.
     *
     * PUBLIC, so a test can point it at a payload built any other way and so the
     * gateway can check one it was handed rather than one it made. This is the
     * proof obligation in §5: not «we removed the identifiers», but «there are
     * no identifiers left that we know how to see».
     *
     * @throws RuntimeException naming the rule that fired — never the value.
     */
    public function assertClean(SanitisedPayload $payload): void
    {
        foreach (self::RULES as $rule => [$pattern]) {
            if (preg_match($pattern, $payload->text) === 1) {
                // The RULE, never the match. An exception message reaches a log.
                throw new RuntimeException(
                    "AI payload failed the privacy check: [{$rule}] still matches after sanitisation.",
                );
            }
        }

        if (! $payload->pseudonyms->coversEverythingIn($payload->text)) {
            throw new RuntimeException(
                'AI payload failed the privacy check: a known name survived pseudonymisation.',
            );
        }
    }

    /**
     * How many name substitutions happened.
     *
     * Counted by asking the map how many of its pseudonyms are now present,
     * rather than by diffing: a diff would need both strings, and the whole
     * design here is that the «before» is discarded as soon as possible.
     */
    protected function substitutionCount(string $before, string $after, Pseudonyms $pseudonyms): int
    {
        $count = 0;

        foreach (array_unique(array_values($pseudonyms->byName)) as $pseudonym) {
            $count += mb_substr_count($after, $pseudonym) - mb_substr_count($before, $pseudonym);
        }

        return max($count, 0);
    }
}
