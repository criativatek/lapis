<?php

namespace App\Support\Characterisation;

use App\Models\CatalogueFamily;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;

/**
 * One entry of the controlled acronym dictionary.
 *
 * `expansion` being null is a real, deliberate state and not a gap waiting to be
 * filled by whoever passes by: it means "this is a known acronym whose meaning
 * nobody confirmed for this project". Writing a plausible expansion there would
 * be inventing one, which is the single thing the dictionary exists to prevent.
 */
readonly class AcronymEntry
{
    public function __construct(
        public string $token,
        public ?string $expansion,
        public AcronymScope $scope,
        public ?SupportMeasureLevel $level = null,
        public ?SupportMeasureCode $code = null,
        /**
         * Which family this token belongs to, when that much is confirmed —
         * a legal measure, a resource, an instrument. Null means the kind of
         * thing it is has not been established either, which is a different
         * and weaker statement than knowing it is a resource with nowhere
         * structured to go.
         */
        public ?CatalogueFamily $family = null,
    ) {}

    /**
     * A confirmed entry is one this repository can justify — either because the
     * expansion is the label of an enum case that already exists, or because a
     * real file inspected during design carried the column.
     */
    public function isConfirmed(): bool
    {
        return $this->expansion !== null;
    }

    /** Does the entry carry enough to name a measure on its own? */
    public function resolvesToMeasure(): bool
    {
        return $this->code !== null;
    }
}
