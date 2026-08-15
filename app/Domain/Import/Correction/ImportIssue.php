<?php

namespace App\Domain\Import\Correction;

/**
 * One thing worth telling the teacher about a file being imported.
 *
 * Carries a code (what it is), a severity (what it costs), a message (what to
 * read) and context (where to look). The context is deliberately free-form and
 * deliberately impersonal: it holds source keys and counts so the interface can
 * point at a row, never names or answers, because these travel into logs,
 * snapshots and, eventually, exceptions.
 */
final readonly class ImportIssue
{
    /**
     * @param  array<string, scalar|null>  $context
     */
    public function __construct(
        public IssueCode $code,
        public IssueSeverity $severity,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * @param  array<string, scalar|null>  $context
     */
    public static function make(IssueCode $code, string $message, array $context = [], ?IssueSeverity $severity = null): self
    {
        return new self($code, $severity ?? $code->defaultSeverity(), $message, $context);
    }

    public function blocksConfirmation(): bool
    {
        return $this->severity->blocksConfirmation();
    }

    /**
     * @return array{code: string, severity: string, message: string, context: array<string, scalar|null>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code->value,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
