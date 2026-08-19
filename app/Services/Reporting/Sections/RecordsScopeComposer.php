<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Models\EvidenceKind;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Âmbito do relatório» — what was counted, and over what.
 *
 * WITHOUT THIS PARAGRAPH EVERY NUMBER BELOW IS MEANINGLESS. «18 registos» is a
 * fact about a class, a set of kinds and a stretch of dates; a reader who
 * cannot see which will assume the widest reading, and the widest reading is
 * usually wrong. So the filters are stated in prose before anything is counted
 * (§29).
 */
class RecordsScopeComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::RecordsScope;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $filters = $context->fact('filters');

        if (! is_array($filters)) {
            return ComposedSection::empty();
        }

        $class = $context->fact('class');

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    Phrase::sentence(
                        'O presente relatório reúne os registos',
                        is_array($class) ? 'da turma '.$class['label'] : 'de todas as turmas',
                        'no período de',
                        $context->scopeLabel(),
                    ),
                    $this->kindsSentence($filters),
                ]),
                Phrase::paragraph([
                    $this->identificationSentence($filters),
                    $this->modeSentence($filters),
                ]),
            ]),
            [ContentSource::Records],
            ['filters' => $filters],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function kindsSentence(array $filters): ?string
    {
        $kinds = is_array($filters['kinds'] ?? null) ? $filters['kinds'] : [];

        if ($kinds === []) {
            return 'Foram considerados todos os tipos de registo.';
        }

        $labels = [];

        foreach ($kinds as $value) {
            $kind = is_string($value) ? EvidenceKind::tryFrom($value) : null;

            if ($kind !== null) {
                $labels[] = $kind->label();
            }
        }

        return $labels === []
            ? null
            : Phrase::sentence('Foram considerados os registos do tipo', Phrase::items($labels));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function identificationSentence(array $filters): string
    {
        return ($filters['names_students'] ?? false) === true
            ? 'Os registos são apresentados com identificação dos alunos.'
            : 'Os registos são apresentados de forma agregada, sem identificação dos alunos.';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function modeSentence(array $filters): string
    {
        return ($filters['detailed'] ?? false) === true
            ? 'O relatório inclui a cronologia detalhada dos registos.'
            : 'O relatório apresenta apenas a síntese, sem a listagem individual dos registos.';
    }
}
