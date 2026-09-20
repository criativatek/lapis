<?php

namespace App\Support\Characterisation;

use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;

/**
 * What the resolver made of one statement inside a cell.
 *
 * `rawToken` is never dropped. Whatever the interpretation turns out to be
 * worth later, what the school's file literally said stays next to it — that is
 * what lets a future framework re-read this without re-guessing.
 */
readonly class CodeResolution
{
    /**
     * @param  list<string>  $unresolvedAnnotations  Sub-paragraph letters ("b)") that
     *                                               were read but deliberately not mapped to a measure.
     */
    public function __construct(
        public string $rawToken,
        public CodeConfidence $confidence,
        public ?SupportMeasureLevel $level = null,
        public ?SupportMeasureCode $code = null,
        public array $unresolvedAnnotations = [],
        public AcronymScope $scope = AcronymScope::Institutional,
        public ?string $note = null,
    ) {}

    /**
     * @param  list<string>  $unresolvedAnnotations
     */
    public static function recognised(
        string $rawToken,
        SupportMeasureCode $code,
        array $unresolvedAnnotations = [],
        ?string $note = null,
    ): self {
        return new self(
            rawToken: $rawToken,
            confidence: CodeConfidence::Recognised,
            level: $code->level(),
            code: $code,
            unresolvedAnnotations: $unresolvedAnnotations,
            scope: AcronymScope::National,
            note: $note,
        );
    }

    /**
     * @param  list<string>  $unresolvedAnnotations
     */
    public static function ambiguous(
        string $rawToken,
        ?SupportMeasureLevel $level = null,
        array $unresolvedAnnotations = [],
        ?string $note = null,
    ): self {
        return new self(
            rawToken: $rawToken,
            confidence: CodeConfidence::Ambiguous,
            level: $level,
            unresolvedAnnotations: $unresolvedAnnotations,
            scope: $level !== null ? AcronymScope::National : AcronymScope::Institutional,
            note: $note,
        );
    }

    public static function unrecognised(
        string $rawToken,
        AcronymScope $scope = AcronymScope::Institutional,
        ?string $note = null,
    ): self {
        return new self(
            rawToken: $rawToken,
            confidence: CodeConfidence::Unrecognised,
            scope: $scope,
            note: $note,
        );
    }

    /** Only a recognised resolution may ever become a stored measure. */
    public function isStorable(): bool
    {
        return $this->confidence === CodeConfidence::Recognised && $this->code !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'raw_token' => $this->rawToken,
            'confidence' => $this->confidence->value,
            'confidence_label' => $this->confidence->label(),
            'level' => $this->level?->value,
            'level_label' => $this->level?->label(),
            'code' => $this->code?->value,
            'code_label' => $this->code?->label(),
            'unresolved_annotations' => $this->unresolvedAnnotations,
            'scope' => $this->scope->value,
            'note' => $this->note,
            'storable' => $this->isStorable(),
        ];
    }
}
