<?php

namespace App\Domain\Import\Correction;

/**
 * A section of the instrument as the source describes it — «Grupo I», «Parte A».
 *
 * This is NOT an InstrumentGroup and NOT a Domain. It is structure: where a
 * question sits on the page. A domain is what the question assesses, and the two
 * are decided independently (§9). A source that names its sections after domains
 * is a coincidence the import must not read as a mapping.
 *
 * A source with no sections at all (Plickers) produces exactly one group with a
 * null label — the implicit group. It exists so a question always has somewhere
 * to belong, and the interface never shows it.
 */
final readonly class CanonicalGroup
{
    /**
     * @param  string  $sourceKey  Identity WITHIN this import only. Never an InstrumentGroup ulid.
     * @param  array<string, mixed>  $metadata  Source-specific detail (sheet, row range, …).
     */
    public function __construct(
        public string $sourceKey,
        public int $sequence,
        public ?string $label = null,
        public ?string $externalId = null,
        public array $metadata = [],
    ) {}

    /**
     * The unnamed section a structureless source implies. Not shown to anyone.
     */
    public static function implicit(string $sourceKey = 'group:0'): self
    {
        return new self(sourceKey: $sourceKey, sequence: 1, label: null);
    }

    public function isImplicit(): bool
    {
        return $this->label === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'sequence' => $this->sequence,
            'label' => $this->label,
            'external_id' => $this->externalId,
            'metadata' => $this->metadata,
        ];
    }
}
