<?php

namespace App\Support\Interventions;

use App\Models\CatalogueFamily;
use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
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
    /**
     * Stable identifier of this VERSION, e.g. 'pt-inclusive-education-2018'.
     *
     * It is what an intervention stores as its snapshot, so it must never be
     * reused for a different text. A new version of the same regime gets a new
     * code; it does not inherit this one.
     */
    public function code(): string;

    /** Human title, e.g. «Regime jurídico da educação inclusiva». */
    public function title(): string;

    /**
     * The diploma, cited as it should appear in a document — including the
     * amending diplomas, because the consolidated text is what is in force.
     */
    public function legalReference(): string;

    /** When this version entered into force; null when it predates what we know. */
    public function validFrom(): ?CarbonInterface;

    /** When it stopped being in force; null while it still is. */
    public function validUntil(): ?CarbonInterface;

    /**
     * Where this version stands in its own life cycle. A framework whose status
     * is not applicable must never be resolved for a real intervention — the
     * registry enforces that, so a draft cannot leak into the UI by someone
     * forgetting an `if`.
     */
    public function status(): LegalFrameworkStatus;

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
     * Which family this framework reads a pedagogical type as: a legal measure,
     * a teaching strategy, an assessment adaptation, or a resource.
     *
     * Deliberately the framework's answer and not the type's. «Apoio tutorial»
     * is a named measure in Portugal and plain teaching in a jurisdiction with
     * no such regime, and the page must say so honestly in both.
     */
    public function familyFor(InterventionType $type): CatalogueFamily;

    /**
     * The level this version of the law puts a measure at, or null when this
     * version does not name it at all (it was introduced later, or revoked).
     *
     * This is the authority. SupportMeasureCode::currentPortugueseLevel() is a
     * convenience for today; a record from 2026 read in 2030 must come through
     * here, with the framework resolved from its own date.
     */
    public function levelFor(SupportMeasureCode $measure): ?SupportMeasureLevel;

    /**
     * Where this version names the measure — article, número, alínea, and the
     * diploma's own designation — or null when it does not name it.
     */
    public function legalReferenceFor(SupportMeasureCode $measure): ?LegalReference;

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
