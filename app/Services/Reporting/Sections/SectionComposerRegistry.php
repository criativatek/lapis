<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\SectionKey;

/**
 * Which composer answers for which section.
 *
 * A KEY WITH NO COMPOSER PRODUCES NOTHING, deliberately. Sections are declared
 * in SectionCatalogue — the structure of the document — and generated here.
 * The two lists grow at different rates while the module is being built, and a
 * missing composer must mean «this section is not written yet», never a crash
 * and never a placeholder paragraph that reads like content.
 *
 * Composers are resolved through the container so they can take the narrative
 * helpers and nothing else; none of them may take a repository or a query
 * builder, which is the point (§1).
 */
class SectionComposerRegistry
{
    /**
     * @var list<class-string<SectionComposer>>
     */
    public const COMPOSERS = [
        // Turma — descriptive, and therefore Base.
        ClassIdentificationComposer::class,
        OverallAssessmentComposer::class,
        ClassDistributionComposer::class,
        DomainResultsComposer::class,
        ClassEvolutionComposer::class,
        ClassSelfAssessmentComposer::class,
        ClassRecordsComposer::class,

        // Shared across types.
        PlanningComplianceComposer::class,
        InterventionsSummaryComposer::class,
        FinalSynthesisComposer::class,
    ];

    /** @var array<string, SectionComposer>|null */
    protected ?array $resolved = null;

    public function find(SectionKey $key): ?SectionComposer
    {
        return $this->all()[$key->value] ?? null;
    }

    public function has(SectionKey $key): bool
    {
        return $this->find($key) !== null;
    }

    /**
     * @return array<string, SectionComposer>
     */
    public function all(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $resolved = [];

        foreach (static::COMPOSERS as $class) {
            /** @var SectionComposer $composer */
            $composer = app($class);
            $resolved[$composer->key()->value] = $composer;
        }

        return $this->resolved = $resolved;
    }
}
