<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Absence;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Evolução», for one student.
 *
 * THE SAME TWO MOVEMENTS AS THE CLASS SECTION, and they matter more here
 * because they can point in opposite directions for one person (§7). A student
 * who scored 70 in the first period and 50 in the second fell twenty points as
 * a period and five as a year. Printing only one of those — whichever one — is
 * how a report ends up telling a parent something that is not true.
 *
 * «SEM COMPARAÇÃO» IS SAID, NOT HIDDEN. A student with no previous result did
 * not stand still; there is nothing to stand against, and a section that
 * silently omitted them would read as stability.
 */
class StudentEvolutionComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::StudentEvolution;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $student = $context->fact('student');

        if (! is_array($student)) {
            return ComposedSection::empty();
        }

        $previous = $context->fact('previous_period_label');
        $evolution = $student['evolution'] ?? null;
        $continuous = $student['continuous_evolution'] ?? null;

        if (! is_array($evolution) && ! is_array($continuous)) {
            return ComposedSection::of(Absence::noComparison(), [ContentSource::Results]);
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([
                    $this->periodSentence($evolution, is_string($previous) ? $previous : null),
                    $this->continuousSentence($continuous),
                ]),
                $this->seriesLine($student),
            ]),
            [ContentSource::Results, ContentSource::Statistics],
            [
                'evolution' => $evolution,
                'continuous_evolution' => $continuous,
                'series' => $student['series'] ?? [],
            ],
        );
    }

    protected function periodSentence(mixed $evolution, ?string $previous): ?string
    {
        if (! is_array($evolution)) {
            return null;
        }

        $points = Phrase::number($evolution['points'] ?? null);

        if ($points === null) {
            return null;
        }

        return Phrase::sentence(
            $previous === null ? 'Face ao momento anterior' : 'Face ao '.$previous,
            ', considerando o trabalho realizado em cada período isoladamente, o resultado',
            $this->direction((string) ($evolution['direction'] ?? 'stable')),
            $this->magnitude((string) ($evolution['direction'] ?? 'stable'), $points),
        );
    }

    protected function continuousSentence(mixed $continuous): ?string
    {
        if (! is_array($continuous)) {
            return null;
        }

        $points = Phrase::number($continuous['points'] ?? null);

        if ($points === null) {
            return null;
        }

        // No parenthetical dashes explaining the method mid-sentence (§1).
        return Phrase::sentence(
            'Na avaliação contínua, que considera o percurso acumulado até ao momento, o desempenho',
            $this->direction((string) ($continuous['direction'] ?? 'stable')),
            $this->magnitude((string) ($continuous['direction'] ?? 'stable'), $points),
        );
    }

    protected function direction(string $direction): string
    {
        return match ($direction) {
            'up' => 'subiu',
            'down' => 'desceu',
            default => 'manteve-se',
        };
    }

    protected function magnitude(string $direction, string $points): ?string
    {
        if ($direction !== 'up' && $direction !== 'down') {
            return null;
        }

        $absolute = ltrim($points, '-');

        return $absolute.' pontos percentuais';
    }

    /**
     * The student's own line through the year, for the table beside the prose.
     *
     * @param  array<string, mixed>  $student
     */
    protected function seriesLine(array $student): ?string
    {
        $series = $student['series'] ?? null;

        if (! is_array($series) || count($series) < 2) {
            return null;
        }

        $parts = [];

        foreach ($series as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $value = Phrase::percentage($entry['primary_average'] ?? null);

            // A period with no result is shown as such, never as a gap in a
            // line that the reader would join up themselves.
            $parts[] = (string) ($entry['period_label'] ?? '—').': '.($value ?? 'sem resultado');
        }

        return $parts === [] ? null : Phrase::sentence('Ao longo do ano letivo:', Phrase::items($parts, 'e'));
    }
}
