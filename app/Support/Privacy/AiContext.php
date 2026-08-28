<?php

namespace App\Support\Privacy;

use InvalidArgumentException;

/**
 * HOW PEDAGOGICAL CONTEXT IS BUILT. Not «assemble a paragraph and hope the
 * sanitiser catches whatever ended up in it».
 *
 * THE ORDER IS THE POINT, AND IT IS THE OPPOSITE OF THE OBVIOUS ONE:
 *
 *      allowlist  →  pseudonymise  →  serialise  →  sanitise
 *
 * A caller states, field by field, what it is sending. Each value is
 * pseudonymised THE MOMENT IT IS ADDED, while it is still an isolated scalar
 * with a known meaning. Only then is anything joined into text. The sanitiser
 * runs last, over the finished string, as a SECOND barrier.
 *
 * WHY NOT JUST SANITISE THE TEXT. Because by the time a paragraph exists, the
 * structure that made it checkable is gone. `AiPayloadSanitizer` is a set of
 * regular expressions looking for shapes it knows — an email, a postal code, a
 * long run of digits. It is very good at those and blind to everything else: a
 * street name, a sibling, «o pai da Rita». A pipeline whose only barrier is
 * pattern matching over prose is a pipeline that leaks exactly the personal data
 * nobody thought to write a pattern for. Minimisation has to happen where
 * somebody can still see what a value IS, and that is here.
 *
 * THE ALLOWLIST HAS TEETH, AND THE TEETH ARE A RUNTIME GUARD RATHER THAN THE
 * PARAMETER TYPE — which is not the obvious choice and is the important one.
 *
 * The obvious version of `add()` types its value `string|int|float|null` and
 * calls it done. It is not done, for two reasons that compound:
 * `Illuminate\Database\Eloquent\Model::__toString()` EXISTS and returns
 * `toJson()`, so an Eloquent record satisfies a `string` parameter by coercion —
 * `->add('Aluno', $student)` would have quietly serialised the whole row, every
 * column of it, into the prompt. And `declare(strict_types=1)` cannot rescue it,
 * because strict mode is decided by the CALLING file: this class cannot impose
 * it on a caller written next year.
 *
 * So the value is `mixed` and is checked here. `->add('Aluno', $student)`,
 * `->add('Dados', $row->toArray())` and `->add('Turma', $class)` all throw,
 * whatever the caller's declare, and the message names the TYPE and never the
 * value. There is no shape of this API in which a record gets dumped into a
 * prompt: a caller has to name every scalar it wants to send, one at a time, in
 * the language the model will read. Naming twelve fields by hand is tedious on
 * purpose — it is the moment where somebody notices that the twelfth is a
 * guardian's telephone.
 *
 * THE ROSTER IS NOT OPTIONAL, IT IS EXPLICIT. There is no default constructor:
 * a caller says `about($pseudonyms)` or says `withoutPeople()`. «I forgot to
 * pass the names» and «there are no names» are different statements and must not
 * look the same at the call site.
 *
 * WHAT IT IS NOT: a prompt builder. It produces the CONTENT half of an `AiAsk`.
 * The instruction is separate, application-authored and versioned, and the two
 * never meet before the wire (§6).
 */
final class AiContext
{
    /** @var array<string, string> label => value, already pseudonymised */
    private array $fields = [];

    /** How many name substitutions happened at `add()` time, before anything was joined. */
    private int $namesReplaced = 0;

    private function __construct(private readonly Pseudonyms $pseudonyms) {}

    /** Context about people whose names must not leave. */
    public static function about(Pseudonyms $pseudonyms): self
    {
        return new self($pseudonyms);
    }

    /**
     * Context with nobody in it — a question about the product, a domain name, a
     * period. Said out loud rather than expressed as an empty roster, so that
     * «no people» is a claim somebody made and can be held to.
     */
    public static function withoutPeople(): self
    {
        return new self(Pseudonyms::none());
    }

    /**
     * Add one field.
     *
     * A blank or null value is DROPPED rather than sent as an empty label:
     * «Objetivo do professor: » tells a model that a field exists and is empty,
     * which is a different and worse statement than not mentioning it.
     *
     * @param  mixed  $value  string, int, float or null — anything else throws.
     *                        Typed `mixed` deliberately; see the class docblock
     *                        for why a narrow union is not the guard it looks like.
     *
     * @throws InvalidArgumentException on a label that could forge structure, or
     *                                  on a value that is not a named scalar
     */
    public function add(string $label, mixed $value): self
    {
        $this->assertUsableLabel($label);
        $this->assertUsableValue($label, $value);

        if ($value === null) {
            return $this;
        }

        $flattened = $this->flatten((string) $value);

        if ($flattened === '') {
            return $this;
        }

        // PSEUDONYMISED HERE, not at serialisation time. This is the ordering
        // the whole class exists to enforce, and `fields()` below is what lets a
        // test prove it happened.
        $before = $flattened;
        $pseudonymised = $this->pseudonyms->apply($flattened);

        if ($pseudonymised !== $before) {
            $this->namesReplaced++;
        }

        // A repeated label appends rather than overwrites: two observations
        // about the same thing are two observations, and silently keeping the
        // last one would lose data without saying so.
        $this->fields[$label] = isset($this->fields[$label])
            ? $this->fields[$label].' | '.$pseudonymised
            : $pseudonymised;

        return $this;
    }

    /**
     * Add a list under one label — prior strategies, domain names, period
     * labels. Each item goes through `add()`'s rules, including the value check.
     *
     * @param  list<mixed>  $values
     */
    public function addList(string $label, array $values): self
    {
        foreach ($values as $value) {
            $this->add($label, $value);
        }

        return $this;
    }

    public function isEmpty(): bool
    {
        return $this->fields === [];
    }

    /**
     * The fields as they stand — ALREADY PSEUDONYMISED, not yet serialised.
     *
     * Exists so `AiContextTest` can assert the ordering rule directly instead of
     * inferring it from the finished string. A test that can only see the output
     * cannot tell «pseudonymised before joining» from «joined and then
     * pseudonymised», and those are the two things this class is about.
     *
     * @return array<string, string>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Serialise, then sanitise.
     *
     * The sanitiser gets the SAME `Pseudonyms` this context used, so its own
     * pass is a no-op over names already replaced — and still catches a name
     * that reached the text some other way. The count from `add()` time is
     * carried in as a seed so the payload's summary reports what actually
     * happened across both stages rather than only the second.
     */
    public function toPayload(AiPayloadSanitizer $sanitizer): SanitisedPayload
    {
        $lines = [];

        foreach ($this->fields as $label => $value) {
            $lines[] = $label.': '.$value;
        }

        return $sanitizer->sanitiseWith(
            implode("\n", $lines),
            $this->pseudonyms,
            $this->namesReplaced > 0 ? ['names' => $this->namesReplaced] : [],
        );
    }

    /**
     * One line, always.
     *
     * A value containing a newline could otherwise write a label of its own and
     * appear to the model as a field this application never sent — the
     * structural half of the prompt-injection defence (§6). Collapsing
     * whitespace is enough because the serialisation is line-based.
     */
    private function flatten(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /**
     * Labels are written by this application, never by a user — but «never» is
     * cheaper to enforce than to rely on. A label that carried a newline would
     * be able to forge the same structure a value cannot.
     */
    private function assertUsableLabel(string $label): void
    {
        if (trim($label) === '' || preg_match('/[\r\n]/', $label) === 1) {
            throw new InvalidArgumentException(
                'An AI context label must be a single non-empty line written by the application.',
            );
        }
    }

    /**
     * The allowlist, enforced.
     *
     * REJECTS EVERYTHING THAT IS NOT A NAMED SCALAR — objects (Eloquent models
     * above all, which stringify to their whole JSON row), arrays, booleans,
     * resources, closures. A caller who wants a record's data in a prompt has to
     * pull out the fields it means, one by one, which is the entire point.
     *
     * Booleans are refused rather than coerced: `true` renders as «1», which
     * tells a model nothing. A caller that means «sim» writes «sim».
     *
     * THE MESSAGE NAMES THE TYPE, NEVER THE VALUE. It reaches a log, and the
     * value is the thing this class exists to keep out of places like that.
     */
    private function assertUsableValue(string $label, mixed $value): void
    {
        if ($value === null || is_string($value) || is_int($value) || is_float($value)) {
            return;
        }

        throw new InvalidArgumentException(sprintf(
            'AI context field [%s] must be a string, int, float or null; got %s. '
            .'Name the individual fields you mean to send instead of handing over a record.',
            $label,
            get_debug_type($value),
        ));
    }
}
