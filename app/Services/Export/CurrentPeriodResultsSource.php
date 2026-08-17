<?php

namespace App\Services\Export;

use App\Models\AcademicPeriod;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;

/**
 * The period as it stands — the export LÁPIS has always done.
 *
 * Lifted out of InovarExportPreviewBuilder unchanged in behaviour: the same
 * accumulated mention from the same read model the Quadro Síntese shows, and
 * the same coverage explanation read in the same scope the warning comes from.
 * What moved is only WHERE it is asked for, so that a second source can answer
 * the same question differently without the builder knowing which one it has.
 */
class CurrentPeriodResultsSource implements InovarExportSource
{
    public function __construct(
        protected SchoolClass $class,
        protected AcademicPeriod $period,
        protected BuildResultsProgression $progression,
        protected ClassResultsCalculator $calculator,
        protected CoverageExplanation $coverage,
        protected InovarCodeResolver $codes,
    ) {}

    public function label(): string
    {
        return "Final do {$this->period->label}";
    }

    public function referenceLabel(): ?string
    {
        // The period as it stands has no reference date — it is now.
        return null;
    }

    public function cells(): array
    {
        $scale = $this->class->profileVersion?->scale()->with('levels')->first();

        // WHY a result is partial, from the service that already answers that
        // question for the ⚠ on Resultados. Read in the same scope the warning
        // itself comes from, so the reason and the flag can never disagree.
        $notes = $this->coverage->forResults($this->calculator->forPeriod($this->class, $this->period));

        $cells = [];

        foreach ($this->progression->for($this->class)['students'] as $student) {
            $enrollmentId = (int) $student['enrollment_id'];

            foreach ($student['periods'] as $row) {
                if ($row['period_id'] !== $this->period->id) {
                    continue;
                }

                foreach ($row['domains'] as $domain) {
                    $mention = $domain['mention'];
                    $level = $mention === null
                        ? null
                        : $scale?->levels->firstWhere('id', $mention['scale_level_id']);

                    $cells[$enrollmentId][(int) $domain['domain_id']] = [
                        'band_label' => $mention['label'] ?? null,
                        'inovar_code' => $this->codes->forLevel($level),
                        'coverage_warning' => (bool) $domain['coverage_warning'],
                        'coverage_elements' => $notes[$enrollmentId]['domains'][$domain['domain_id']]['absences'] ?? [],
                    ];
                }
            }
        }

        return $cells;
    }

    public function isExportable(): bool
    {
        return $this->codes->covers($this->class->profileVersion?->scale()->with('levels')->first());
    }

    public function missingBands(): array
    {
        return $this->codes->missingFrom($this->class->profileVersion?->scale()->with('levels')->first());
    }
}
