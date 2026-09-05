<?php

namespace App\Services\Evidence\Ai;

/**
 * Whether an answer may be shown to a teacher, and if not, why — the Registos
 * equivalent of `App\Services\Reporting\Writing\RewriteVerdict`.
 */
readonly class IncidentRewriteVerdict
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

    /** The only sentence a teacher sees when validation fails. */
    public function publicMessage(): string
    {
        return 'Não foi possível validar a reformulação. O texto original foi preservado.';
    }
}
