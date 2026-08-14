<?php

namespace App\Support\Interventions;

use App\Models\InterventionType;
use Carbon\CarbonInterface;

/**
 * No legal framework — a fully valid state, not a degraded one.
 *
 * It applies to an organization in a jurisdiction LÁPIS has no framework for,
 * and to one that never stated a jurisdiction while the compatibility default
 * is switched off. In both cases the teacher registers interventions exactly as
 * usual: type, target, domain, description, dates, status, effectiveness,
 * availability for reports and filters all work untouched. Only the legal
 * framing is absent, because there is none to offer.
 *
 * It never borrows another country's taxonomy. Showing Portuguese measure
 * levels to a school in a jurisdiction that does not have them would be worse
 * than showing nothing: it invites a teacher to record a legal classification
 * that does not exist where they teach.
 */
final class NullLegalFramework implements InterventionLegalFramework
{
    public function code(): string
    {
        return 'none';
    }

    public function jurisdiction(): ?string
    {
        return null;
    }

    public function coversDate(CarbonInterface $date): bool
    {
        return true;
    }

    public function hasLegalTaxonomy(): bool
    {
        return false;
    }

    public function mappingFor(InterventionType $type): LegalMapping
    {
        return LegalMapping::none();
    }

    /**
     * Empty, not absent: the UI's contract stays the same shape everywhere, so
     * a page never breaks over a missing property — it simply has nothing to
     * render (§1 of the brief).
     *
     * @return array<int, array{value: string, label: string, measures: array<int, array{value: string, label: string}>}>
     */
    public function supportMeasureLevels(): array
    {
        return [];
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public function evaluationAdaptations(): array
    {
        return [];
    }
}
