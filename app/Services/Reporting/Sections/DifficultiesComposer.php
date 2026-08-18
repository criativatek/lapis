<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Principais dificuldades identificadas» (§13).
 *
 * IDENTIFIED BY THE TEACHER, NOT DIAGNOSED BY THE SYSTEM. This is the section
 * where a generated report is most tempted to overstep: a low average in Escrita
 * is right there in the data, and turning it into «dificuldades na escrita»
 * feels like description. It is not — it is a claim about why, and the same
 * average is equally consistent with a hard test, a missed month and a
 * mis-weighted instrument. So a difficulty appears here only because somebody
 * validated it.
 *
 * WHAT THE DATA MAY CONTRIBUTE IS A FIGURE, NEXT TO IT. Where the teacher
 * associated a difficulty with a domain, the class's average in that domain is
 * quoted — a fact, placed beside a judgement the teacher already made. The
 * report never asserts that one caused the other, and never introduces a domain
 * the teacher did not name.
 *
 * Shared with the individual report, where the subject changes and the rule
 * does not.
 */
class DifficultiesComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::Difficulties;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $difficulties = $this->difficulties($context);

        if ($difficulties === []) {
            return ComposedSection::empty();
        }

        $labels = array_map(fn (array $difficulty) => (string) $difficulty['label'], $difficulties);

        return ComposedSection::of(
            Phrase::body([
                // THE LABELS ARE NOT BENT INTO THE SENTENCE. «Foram
                // identificadas … a planificação da escrita» would need the
                // right article for every noun a teacher might type, and
                // guessing gender to make prose flow is the kind of small
                // wrongness that makes a document look machine-made. A colon
                // and the labels as written is correct for every one of them.
                Phrase::sentence(
                    count($labels) === 1
                        ? 'Foi identificada como principal dificuldade:'
                        : 'Foram identificadas como principais dificuldades:',
                    Phrase::items($labels),
                ),
                $this->detailParagraph($context, $difficulties),
            ]),
            [ContentSource::TeacherInput, ContentSource::Library, ContentSource::Statistics],
            ['difficulties' => $difficulties],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function difficulties(ReportContext $context): array
    {
        $chosen = $context->input('difficulties');

        if (! is_array($chosen)) {
            return [];
        }

        $clean = [];

        foreach ($chosen as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = is_string($row['label'] ?? null) ? trim($row['label']) : '';

            if ($label === '') {
                continue;
            }

            $clean[] = $row;
        }

        return $clean;
    }

    /**
     * One line per difficulty that carries something beyond its name: the
     * teacher's note, and the figure of the domain they associated it with.
     *
     * @param  list<array<string, mixed>>  $difficulties
     */
    protected function detailParagraph(ReportContext $context, array $difficulties): ?string
    {
        $lines = [];

        foreach ($difficulties as $difficulty) {
            $parts = [];

            $note = is_string($difficulty['note'] ?? null) ? trim($difficulty['note']) : '';

            if ($note !== '') {
                $parts[] = Phrase::terminate($note);
            }

            $figure = $this->domainFigure($context, $difficulty['domain'] ?? null);

            if ($figure !== null) {
                $parts[] = $figure;
            }

            if ($parts === []) {
                continue;
            }

            $lines[] = '— '.$difficulty['label'].': '.implode(' ', $parts);
        }

        return $lines === [] ? null : implode("\n", $lines);
    }

    /**
     * The class's figure in the domain the TEACHER associated with this
     * difficulty.
     *
     * Stated as co-occurrence, never as cause: «no domínio de X, a média
     * situou-se em Y» is a fact, and the association between the two is the
     * teacher's own (§13).
     */
    protected function domainFigure(ReportContext $context, mixed $domain): ?string
    {
        if (! is_string($domain) || trim($domain) === '') {
            return null;
        }

        $domains = $context->fact('domains');

        if (! is_array($domains)) {
            return null;
        }

        foreach ($domains as $row) {
            if (! is_array($row) || ($row['label'] ?? null) !== $domain) {
                continue;
            }

            $value = Phrase::percentage($row['accumulated_average'] ?? $row['period_average'] ?? null);

            if ($value === null) {
                return null;
            }

            return Phrase::sentence(
                'No domínio de',
                $domain.',',
                'a que foi associada, o resultado médio situou-se em',
                $value,
            );
        }

        return null;
    }
}
