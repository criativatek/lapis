<?php

namespace App\Domain\Import\Correction;

/**
 * What the source says about the assessment itself — usually very little.
 *
 * Every field is optional and every absent field stays null. Nothing here is
 * ever filled in by inference: not the title, not the date, not the period, not
 * the purpose, not the type, not the total (§13). A file whose name contains
 * «04_05_2026» is a file with a suggestive name, not a file that states a date,
 * and the difference matters because the date decides which students the
 * instrument even applies to (§11.4).
 *
 * `title` may hold something the source genuinely printed at the top of the
 * export. It is a suggestion for the teacher to accept or replace, never a value
 * that reaches an Instrument without being confirmed (§53).
 */
final readonly class CanonicalInstrument
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public ?string $title = null,
        public ?string $appliedOn = null,
        public ?string $externalId = null,
        public ?string $sourceTotal = null,
        public ?string $sourcePercentage = null,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'applied_on' => $this->appliedOn,
            'external_id' => $this->externalId,
            'source_total' => $this->sourceTotal,
            'source_percentage' => $this->sourcePercentage,
            'metadata' => $this->metadata,
        ];
    }
}
