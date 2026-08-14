<?php

namespace App\Support\Interventions;

use App\Models\InterventionType;
use Carbon\CarbonInterface;

/**
 * How one jurisdiction, during one period of its history, reads the pedagogical
 * catalogue in legal terms.
 *
 * The split this interface exists to enforce: an intervention is a pedagogical
 * act and is the same everywhere — «apoio à organização da escrita» means the
 * same thing in Lisbon and in Lyon. What differs is whether, and how, the law
 * of a place frames that act. So InterventionType stays global and knows no
 * law, and everything jurisdictional lives behind here.
 *
 * A framework is also bounded in TIME, not just in space: legislation changes,
 * and it may change mid-school-year. Resolution therefore takes a date — the
 * intervention's own `started_on`, never today — so an intervention recorded
 * under one regime is never reinterpreted by the next one.
 */
interface InterventionLegalFramework
{
    /** Stable identifier, e.g. 'pt-inclusive-education-current'. */
    public function code(): string;

    /** ISO 3166-1 alpha-2, or null for the no-framework case. */
    public function jurisdiction(): ?string;

    /** Whether this version of the law was in force on the given date. */
    public function coversDate(CarbonInterface $date): bool;

    /**
     * What this framework proposes for a pedagogical type — and how firmly.
     * A framework that has nothing to say returns LegalMapping::none().
     */
    public function mappingFor(InterventionType $type): LegalMapping;

    /**
     * Whether this framework has a legal taxonomy at all. False means the UI
     * shows no measure levels, no measures and no adaptations — not empty
     * dropdowns of another country's categories.
     */
    public function hasLegalTaxonomy(): bool;

    /**
     * The measure levels and their measures, ready for the UI. Deliberately a
     * payload rather than a fixed enum: nothing guarantees another jurisdiction
     * has three levels, or any levels at all.
     *
     * @return array<int, array{value: string, label: string, measures: array<int, array{value: string, label: string}>}>
     */
    public function supportMeasureLevels(): array;

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function evaluationAdaptations(): array;
}
