<?php

namespace App\Services\Export;

use App\Models\AcademicPeriod;
use App\Models\ClassificationScope;
use App\Models\SchoolClass;
use App\Services\Assessment\BuildResultsProgression;
use App\Services\Assessment\ClassResultsCalculator;
use App\Services\Assessment\CoverageExplanation;
use App\Services\Assessment\DomainAppreciationDecisions;

/**
 * The period as it stands — the export Lapispro has always done.
 *
 * Lifted out of InovarExportPreviewBuilder unchanged in behaviour: the same
 * accumulated mention from the same read model the Quadro Síntese shows, and
 * the same coverage explanation read in the same scope the warning comes from.
 * What moved is only WHERE it is asked for, so that a second source can answer
 * the same question differently without the builder knowing which one it has.
 *
 * ONDE O PROFESSOR DECIDIU, É A DECISÃO DELE QUE VAI PARA A ESCOLA. A apreciação
 * de um domínio pode ter sido reescrita na Pauta, e essa é a leitura que o
 * professor assume — a proposta do Lapispro fica onde estava, mas não é ela que
 * a escola recebe (§3.3). Onde não há decisão nenhuma, vai o valor canónico de
 * sempre: nada é inventado por causa disto.
 *
 * O CÓDIGO ESCRITO CONTINUA A SER O `inovar_code` DA BANDA, decidida ou
 * proposta. Nunca o `code` da escala («4»), nunca o rótulo («Bom») — ver
 * InovarCodeResolver.
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
        protected ?DomainAppreciationDecisions $decisions = null,
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

        $students = $this->progression->for($this->class)['students'];

        // UMA consulta para a turma inteira, e nunca uma por célula (§44).
        $decided = ($this->decisions ?? new DomainAppreciationDecisions)->for(
            array_map(static fn (array $student): int => (int) $student['enrollment_id'], $students),
            (int) $this->period->getKey(),
            ClassificationScope::Period,
        );

        $cells = [];

        foreach ($students as $student) {
            $enrollmentId = (int) $student['enrollment_id'];

            foreach ($student['periods'] as $row) {
                if ($row['period_id'] !== $this->period->id) {
                    continue;
                }

                foreach ($row['domains'] as $domain) {
                    $domainId = (int) $domain['domain_id'];
                    $mention = $domain['mention'];
                    $level = $mention === null
                        ? null
                        : $scale?->levels->firstWhere('id', $mention['scale_level_id']);

                    // A decisão do professor sobre este domínio, quando existe,
                    // substitui a banda calculada — e só ela. A percentagem, a
                    // cobertura e a explicação continuam a ser as do motor.
                    $teachers = $decided[$enrollmentId][$domainId] ?? null;

                    if ($teachers !== null) {
                        // A banda que o professor escreveu, lida da relação que
                        // o leitor das decisões já trouxe — não uma segunda
                        // consulta, e não a escala a ser reinterpretada.
                        $level = $teachers->scaleLevel;
                    }

                    $cells[$enrollmentId][$domainId] = [
                        'band_label' => $teachers !== null
                            ? (string) $teachers->scaleLevel->label
                            : ($mention['label'] ?? null),
                        'inovar_code' => $this->codes->forLevel($level),
                        'coverage_warning' => (bool) $domain['coverage_warning'],
                        'coverage_elements' => $notes[$enrollmentId]['domains'][$domain['domain_id']]['absences'] ?? [],
                        // Para o ecrã de preparação poder dizer que aquela
                        // célula é uma decisão e não a leitura do sistema.
                        'decided_by_teacher' => $teachers !== null,
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
