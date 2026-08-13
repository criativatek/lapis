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

export type DomainAllocation = { domain_id: number; percent: number };

type GradableItemWithDomains = GradableItem & { domains: DomainAllocation[] };

export type DomainResult = {
    domain_id: number;
    earned: number;
    possible: number;
    /** Same null-means-nothing-graded convention as percentFor(). */
    percent: number | null;
    /**
     * Some, but not all, of the items allocated to this domain have any
     * state at all — the figures above are real, but not the domain's last
     * word yet. Mirrors instruments/Grid.vue's own per-instrument
     * isPartial(): a still-pending item is what makes a domain partial, not
     * merely "not assessed" — an absent or under_review item is a settled
     * cell (not outstanding) even though, like a pending one, it never
     * contributes to earned/possible (only 'assessed' does, matching
     * percentFor()).
     */
    isPartial: boolean;
};

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
 * The per-domain counterpart of percentFor(): one result per domain this
 * instrument's items are allocated to, aggregated across every item that
 * touches it — never a single item's own score read as "the domain's
 * result". A question split across two domains (item_domain_allocations)
 * contributes only its own allocated share to each: with Q1 100% Leitura and
 * Q2 60%/40% Leitura/Escrita, Leitura's result draws from Q1's full points
 * plus 60% of Q2's, mirroring exactly the allocation-splitting shape
 * CalculationEngine::calculateDomain() uses server-side for the official
 * class/period grade (fraction = allocation_percent/100, applied to both the
 * earned and possible side, bonus items excluded from the denominator only)
 * — without replicating that method's domain weighting, eligibility, or
 * absence_mode, exactly as percentFor() already doesn't. The feed for
 * qualitativeLabelFor() is the same either way: only the numerator/
 * denominator arithmetic differs from the instrument-wide total.
 *
 * @param items the instrument's items, each with its own domain allocations
 * @param cellFor looks up the cell for a given item id, or undefined if untouched
 */
export function domainResultsFor(
    items: GradableItemWithDomains[],
    cellFor: (itemId: number) => GradedCell | undefined,
): DomainResult[] {
    const domainIds: number[] = [];

    for (const item of items) {
        for (const allocation of item.domains) {
            if (!domainIds.includes(allocation.domain_id)) {
                domainIds.push(allocation.domain_id);
            }
        }
    }

    return domainIds.map((domainId) => {
        let earned = 0;
        let possible = 0;
        let touching = 0;
        // Any non-pending state counts as "resolved" for partial-detection —
        // matching instruments/Grid.vue's own per-instrument isPartial()
        // (an absent or under_review item is still a settled cell, not an
        // outstanding one), even though only 'assessed' ever contributes to
        // earned/possible below.
        let resolved = 0;
        let assessed = 0;

        for (const item of items) {
            const allocation = item.domains.find((candidate) => candidate.domain_id === domainId);

            if (!allocation) {
                continue;
            }

            touching += 1;
            const current = cellFor(item.id);

            if (current === undefined || current.state === 'pending') {
                continue;
            }

            resolved += 1;

            if (current.state === 'assessed' && current.points !== null) {
                const fraction = allocation.percent / 100;
                earned += current.points * fraction;

                if (!item.is_bonus) {
                    possible += item.points_possible * fraction;
                }

                assessed += 1;
            }
        }

        const percent = assessed === 0 || possible === 0 ? null : Math.round((earned / possible) * 1000) / 10;

        return {
            domain_id: domainId,
            earned: Math.round(earned * 100) / 100,
            possible: Math.round(possible * 100) / 100,
            percent,
            isPartial: resolved > 0 && resolved < touching,
        };
    });
}

/**
 * The scale band a percentage falls into, from the class's assessment-
 * profile scale bands (scaleBands prop). A match is inclusive on both ends,
 * mirroring only the inclusive-boundary convention of
 * CalculationEngine::combine()'s band-matching loop — it does not mirror
 * domain weighting, eligibility, or absence handling (see percentFor()).
 * Returns null when percent is null, or when no band matches (no profile
 * assigned to the class, its scale has no bands configured, or the percent
 * genuinely falls outside every band) — the caller renders "—" in that
 * case, never a guessed label.
 *
 * Generic over the band shape so a caller that also carries sequence/
 * is_negative (for qualitativeToneFor()) gets those fields back on the
 * matched band, not just label/band_min/band_max.
 */
export function scaleBandFor<T extends ScaleBand>(percent: number | null, scaleBands: T[]): T | null {
    if (percent === null) {
        return null;
    }

    return scaleBands.find((band) => percent >= Number(band.band_min) && percent <= Number(band.band_max)) ?? null;
}

/**
 * The qualitative label for a given percentage — see scaleBandFor() for the
 * matching rule. Kept as its own function since every existing caller only
 * ever wanted the label text.
 */
export function qualitativeLabelFor(percent: number | null, scaleBands: ScaleBand[]): string | null {
    return scaleBandFor(percent, scaleBands)?.label ?? null;
}
