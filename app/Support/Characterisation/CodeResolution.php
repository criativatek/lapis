<?php

namespace App\Support\Characterisation;

use App\Models\CatalogueFamily;
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
        /**
         * What kind of thing this is, when that much is known. A resolution can
         * be confident about the family and still have nowhere to store it.
         */
        public ?CatalogueFamily $family = null,
    ) {}

    /**
     * A token recognised as a support or resource — a Centro de Recursos para a
     * Inclusão, a specialised technician — for which the catalogue has no item
     * and therefore no structured destination.
     *
     * It is NOT storable, and that is the whole point: «uma necessidade do
     * aluno» and «um apoio mobilizado para ele» are different facts, and
     * writing the second into the first because the first has a column would be
     * the application asserting something nobody said. It reaches the preview,
     * named and classified, and goes no further without a person.
     */
    public static function resource(
        string $rawToken,
        ?string $expansion = null,
        AcronymScope $scope = AcronymScope::National,
    ): self {
        return new self(
            rawToken: $rawToken,
            confidence: CodeConfidence::Recognised,
            scope: $scope,
            note: $expansion === null
                ? (string) __('Apoio ou recurso. Não há destino estruturado para o guardar.')
                : (string) __(':token — :expansion. Apoio ou recurso: não há destino estruturado para o guardar.', [
                    'token' => $rawToken,
                    'expansion' => $expansion,
                ]),
            family: CatalogueFamily::SupportResource,
        );
    }

    /**
     * The level is PASSED IN, never derived from the code here.
     *
     * The enum can answer "the level under the regime in force today", but this
     * object may be describing paperwork read under an older regime — and
     * re-deriving would silently reclassify it. The applicable framework is the
     * authority, and the caller is the one holding it.
     *
     * @param  list<string>  $unresolvedAnnotations
     */
    public static function recognised(
        string $rawToken,
        SupportMeasureCode $code,
        SupportMeasureLevel $level,
        array $unresolvedAnnotations = [],
        ?string $note = null,
    ): self {
        return new self(
            rawToken: $rawToken,
            confidence: CodeConfidence::Recognised,
            level: $level,
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

    /**
     * Only a recognised resolution WITH A CODE may ever become a stored
     * measure. A resource is recognised and has no code, so it is never
     * storable — there is nothing in the schema that could honestly hold it.
     */
    public function isStorable(): bool
    {
        return $this->confidence === CodeConfidence::Recognised && $this->code !== null;
    }

    /** Recognised as a support or resource, with nowhere structured to go. */
    public function isResource(): bool
    {
        return $this->family === CatalogueFamily::SupportResource;
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
            'family' => $this->family?->value,
            'family_label' => $this->family?->label(),
            // Said plainly, because the preview has to. A resource is
            // understood and still has nowhere to be kept.
            'has_structured_destination' => $this->isStorable(),
        ];
    }
}
