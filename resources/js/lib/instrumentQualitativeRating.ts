/**
 * Per-instrument percentage and qualitative-label helpers for the instrument
 * grading grid (resources/js/pages/instruments/Grid.vue).
 *
 * Extracted out of the Grid.vue <script setup> block so this logic is
 * reviewable and unit-testable in isolation from the Vue SFC. This project
 * has no frontend test runner yet (no Vitest/Jest configured) — pulling the
 * calculation into a plain TypeScript module means it is trivially testable
 * the day one is adopted, without needing to mount the component.
 */

type GradableItem = {
    id: number;
    points_possible: number;
    is_bonus: boolean;
};

type GradedCell = {
    state: string;
    points: number | null;
};

type ScaleBand = { label: string; band_min: string; band_max: string };

/**
 * The percentage, over only the items already graded — never the
 * instrument's full total_points as a fixed denominator, so an ungraded
 * item never drags the percentage down (CLAUDE.md §13.3, "vazio não é
 * zero"). Bonus items add to the numerator but not the denominator,
 * mirroring CalculationEngine::calculateDomain()'s treatment of bonus
 * items. Returns null when nothing is graded yet, or if every graded item
 * happened to be bonus (denominator would be zero).
 *
 * This is a simple per-instrument indicator. It deliberately does NOT
 * replicate the rest of CalculationEngine: no domain-allocation weighting,
 * no eligibility/late-entry handling, no absence_mode. The class/period's
 * official result is computed by CalculationEngine, not by this function.
 *
 * @param items the instrument's items
 * @param cellFor looks up the cell for a given item id, or undefined if untouched
 */
export function percentFor(
    items: GradableItem[],
    cellFor: (itemId: number) => GradedCell | undefined,
): number | null {
    let earned = 0;
    let possible = 0;
    let assessed = 0;

    for (const item of items) {
        const current = cellFor(item.id);

        if (current?.state === 'assessed' && current.points !== null) {
            earned += current.points;

            if (!item.is_bonus) {
                possible += item.points_possible;
            }

            assessed += 1;
        }
    }

    if (assessed === 0 || possible === 0) {
        return null;
    }

    return Math.round((earned / possible) * 1000) / 10;
}

/**
 * The qualitative label for a given percentage, from the class's
 * assessment-profile scale bands (scaleBands prop). A band match is
 * inclusive on both ends, mirroring only the inclusive-boundary convention
 * of CalculationEngine::combine()'s band-matching loop — it does not
 * mirror domain weighting, eligibility, or absence handling (see
 * percentFor()). Returns null when percent is null, or when scaleBands is
 * empty (no profile assigned to the class, or its scale has no bands
 * configured) — the caller renders "—" in that case, never a guessed
 * label.
 */
export function qualitativeLabelFor(percent: number | null, scaleBands: ScaleBand[]): string | null {
    if (percent === null) {
        return null;
    }

    const band = scaleBands.find(
        (band) => percent >= Number(band.band_min) && percent <= Number(band.band_max),
    );

    return band?.label ?? null;
}
