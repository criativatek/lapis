<?php

namespace App\Services\Reporting\Writing;

/**
 * Whether an answer may be shown to a teacher, and if not, why.
 *
 * ONE SENTENCE FOR THE PERSON, A SLUG FOR THE TRAIL (§12, §49). The teacher
 * always reads the same thing — «não foi possível validar a reformulação, o
 * texto atual foi preservado» — because the difference between «it changed a
 * percentage» and «it added a strategy» is not something they can act on, and
 * spelling it out would teach whoever wanted to how to get past the guard.
 *
 * The slug goes to the audit trail, where it is worth a great deal: a rejection
 * rate concentrated on one reason is how anyone would ever find out that a
 * particular engine, or a particular prompt version, has a specific problem.
 */
readonly class RewriteVerdict
{
    protected function __construct(
        public bool $acceptable,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {}

    public static function accepted(): self
    {
        return new self(true);
    }

    /**
     * @param  string  $reason  a stable slug for the audit trail
     * @param  string|null  $detail  free text for the log, never shown to a teacher
     */
    public static function rejected(string $reason, ?string $detail = null): self
    {
        return new self(false, $reason, $detail);
    }

    /** The only sentence a teacher sees when validation fails (§12). */
    public function publicMessage(): string
    {
        return 'Não foi possível validar a reformulação. O texto original foi preservado.';
    }
}
