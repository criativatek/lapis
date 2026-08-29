<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionDefinition;
use App\Models\ReportTone;
use App\Models\ReportType;
use App\Services\Ai\Gateway\AiCapability;
use App\Support\Entitlements\Entitlements;

/**
 * WHAT THIS SCHOOL'S PLAN LETS ITS REPORTS DO (§4).
 *
 * One place, consulted by everything: the creation screen when it offers types
 * and sections, the composer when it decides which sections to generate, the
 * controller when it refuses a request, and the finalizer when it writes down
 * what the document was allowed to contain.
 *
 * IT ANSWERS ON THE SERVER (§8.2 of CLAUDE.md). Hiding a section from a
 * checklist is presentation; this is the check. A Base organization that posts
 * the key of a Pro section gets it dropped here, not rendered because the form
 * said so.
 *
 * THE SHAPE OF THE PLAN DIFFERENCE. Base reports DESCRIBE: counts, averages,
 * distributions, the teacher's own statements. Pro reports may additionally
 * INTERPRET: characterise behaviour and attitude, name difficulties, propose
 * measures. Institucional adds the aggregate view across classes. No section is
 * "better" in a higher plan — the descriptive ones are identical everywhere.
 * What changes is which questions the report is allowed to answer at all.
 */
class ReportCapabilities
{
    /**
     * The plan capability behind «Aperfeiçoar redação». Pro and Institucional;
     * never Base.
     *
     * IT MOVED FROM `ai_assistance` TO `ai_reports` IN THE AI-COMPLETE SLICE.
     * The old key was one entitlement covering both this and the strategy
     * suggester, which meant a school could not hold one without the other;
     * they are now separate capabilities, with separate ceilings and separately
     * readable rows in the meter. Every organization that held `ai_assistance`
     * keeps working, because `AiCapability::legacyModuleKeys()` still accepts
     * it.
     */
    public const WRITING_ASSISTANT_MODULE = 'ai_reports';

    /**
     * The capability behind the ANALYTICAL readings a report may carry on top
     * of its descriptive sections: the comparison with the class average and
     * the comparison with the report this one was derived from.
     *
     * Distinct from `PEDAGOGICAL_MODULE`, and deliberately not folded into it.
     * That one is about a section EXISTING at all — «Dificuldades
     * identificadas» is a Pro section or it is nothing. This one is about a
     * sentence or a panel inside a section every plan has: the Síntese global
     * is Base (Matriz Mestre §6, «Síntese factual»), and what §3 and §4 mark
     * Pro is the «Comparação contextual com turma» inside it. Same key the
     * Acompanhamento panel uses for the same reading, so a school cannot end
     * up seeing the comparison in one place and not the other.
     */
    public const ANALYTICS_MODULE = 'advanced_analytics';

    public function __construct(protected Entitlements $entitlements) {}

    /** Whether the pedagogical (interpretive) layer is available at all. */
    public function allowsPedagogicalAnalysis(): bool
    {
        return $this->entitlements->allows(SectionCatalogue::PEDAGOGICAL_MODULE);
    }

    /**
     * Whether this school's plan includes the analytical readings inside
     * otherwise-Base sections — the class comparison (§3, §4) and
     * «Comparação entre períodos» (§6).
     */
    public function allowsAnalytics(): bool
    {
        return $this->entitlements->allows(self::ANALYTICS_MODULE);
    }

    /**
     * Whether this school's plan includes the writing assistant.
     *
     * THE LEGACY KEY STILL OPENS THE DOOR. An organization that holds
     * `ai_assistance` — through a plan, or through a per-organization override
     * granted before the capability was split — is allowed here exactly as it
     * was before, which is what makes the rename invisible to a school.
     *
     * This answers the PLAN question only. Whether an engine is configured at
     * all is a separate question with a separate answer, because the two fail
     * for different reasons and a teacher deserves to be told which (§41).
     */
    public function allowsWritingAssistance(): bool
    {
        foreach (AiCapability::Reports->moduleKeys() as $moduleKey) {
            if ($this->entitlements->allows($moduleKey)) {
                return true;
            }
        }

        return false;
    }

    public function allowsType(ReportType $type): bool
    {
        return $this->entitlements->allows($type->module());
    }

    /**
     * @return list<ReportType>
     */
    public function availableTypes(): array
    {
        return array_values(array_filter(
            ReportType::cases(),
            fn (ReportType $type) => $this->allowsType($type),
        ));
    }

    public function allowsTone(ReportTone $tone): bool
    {
        $module = $tone->module();

        return $module === null || $this->entitlements->allows($module);
    }

    /**
     * @return list<ReportTone>
     */
    public function availableTones(): array
    {
        return array_values(array_filter(
            ReportTone::cases(),
            fn (ReportTone $tone) => $this->allowsTone($tone),
        ));
    }

    public function allowsSection(SectionDefinition $definition): bool
    {
        return $definition->module === null || $this->entitlements->allows($definition->module);
    }

    /**
     * The sections this organization may actually have in a report of this type,
     * in catalogue order.
     *
     * @return list<SectionDefinition>
     */
    public function sectionsFor(ReportType $type): array
    {
        return array_values(array_filter(
            SectionCatalogue::for($type),
            fn (SectionDefinition $definition) => $this->allowsSection($definition),
        ));
    }

    /**
     * The sections a report of this type would start with, before the teacher
     * changes anything (§45).
     *
     * @return list<string>
     */
    public function defaultSectionKeysFor(ReportType $type): array
    {
        return array_values(array_map(
            fn (SectionDefinition $definition) => $definition->key->value,
            array_filter(
                $this->sectionsFor($type),
                fn (SectionDefinition $definition) => $definition->defaultIncluded,
            ),
        ));
    }

    /**
     * The whole catalogue for a type, INCLUDING what this plan does not allow,
     * each row saying whether it is available.
     *
     * Shown rather than hidden on purpose: a teacher who cannot produce
     * «Propostas de superação» should learn that the section exists and what it
     * needs, not silently receive a shorter form. Access control is elsewhere;
     * this is how the product explains itself.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogueFor(ReportType $type): array
    {
        return array_map(function (SectionDefinition $definition): array {
            $allowed = $this->allowsSection($definition);

            return [
                ...$definition->toArray(),
                'available' => $allowed,
                'default_included' => $allowed && $definition->defaultIncluded,
            ];
        }, SectionCatalogue::for($type));
    }
}
