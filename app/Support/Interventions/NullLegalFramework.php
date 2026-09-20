<?php

namespace App\Support\Interventions;

use App\Models\CatalogueFamily;
use App\Models\InterventionType;
use App\Models\LegalFrameworkStatus;
use App\Models\SupportMeasureCode;
use App\Models\SupportMeasureLevel;
use Carbon\CarbonInterface;

/**
 * No legal framework — a fully valid state, not a degraded one.
 *
 * It applies to an organization in a jurisdiction Lapispro has no framework for,
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

    public function title(): string
    {
        return __('Sem enquadramento legal');
    }

    public function legalReference(): string
    {
        return '';
    }

    public function validFrom(): ?CarbonInterface
    {
        return null;
    }

    public function validUntil(): ?CarbonInterface
    {
        return null;
    }

    /**
     * Active, not Draft. "No framework applies here" is a correct and current
     * answer, not an unfinished one — marking it Draft would make the registry
     * refuse to apply it and leave callers with nothing at all.
     */
    public function status(): LegalFrameworkStatus
    {
        return LegalFrameworkStatus::Active;
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
     * Everything is a pedagogical strategy, because with no law nothing can be
     * a legal measure. This is the assertion the whole family concept rests on:
     * a family is a reading, and where there is no law there is no reading that
     * makes an act legally significant.
     */
    public function familyFor(InterventionType $type): CatalogueFamily
    {
        return CatalogueFamily::PedagogicalStrategy;
    }

    public function levelFor(SupportMeasureCode $measure): ?SupportMeasureLevel
    {
        return null;
    }

    public function legalReferenceFor(SupportMeasureCode $measure): ?LegalReference
    {
        return null;
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
