<?php

namespace App\Services\Export;

/**
 * WHERE the mentions written into an INOVAR grid come from.
 *
 * The grid, the matching by process number, the exact domain mapping, the
 * filling, the fidelity checks, the download and the cleanup are all identical
 * whether a school is exporting the end of a period or a moment kept along the
 * way. THE ONLY DIFFERENCE IS THE SOURCE OF THE VALUES — so that is the only
 * thing behind an interface, and everything else stays exactly where it was.
 *
 * Two implementations:
 *
 *  - CurrentPeriodResultsSource reads the canonical read model, as it always has;
 *  - InterimSnapshotSource reads a stored photograph and recomputes nothing.
 *
 * The second one is why `inovarCode()` is asked of the source rather than
 * resolved from a live ScaleLevel: a grid produced in January from a November
 * photograph must carry November's code, and the band it refers to may have
 * been reconfigured since.
 */
interface InovarExportSource
{
    /** «Final do 1.º Semestre», «Avaliação intercalar de novembro». */
    public function label(): string;

    /** «15/11/2026» for a kept moment, null for the period as it stands. */
    public function referenceLabel(): ?string;

    /**
     * Per enrolment and domain: the mention, its INOVAR code, and why the
     * result was partial if it was.
     *
     * @return array<int, array<int, array{
     *     band_label: string|null,
     *     inovar_code: string|null,
     *     coverage_warning: bool,
     *     coverage_elements: list<array<string, mixed>>,
     * }>>
     */
    public function cells(): array;

    /**
     * Whether every band this source can produce has an INOVAR correspondence.
     *
     * All of them, not merely the ones this class happens to have reached: a
     * scale is exportable or it is not, and discovering halfway through that a
     * band cannot be written is discovering it too late.
     */
    public function isExportable(): bool;

    /**
     * The bands with no correspondence, named for the refusal message.
     *
     * @return list<string>
     */
    public function missingBands(): array;
}
