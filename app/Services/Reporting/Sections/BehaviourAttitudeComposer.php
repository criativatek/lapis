<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\BehaviourRating;
use App\Domain\Reporting\ComplementaryIndicator;
use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\IndicatorStanding;
use App\Domain\Reporting\LearningAttitude;
use App\Domain\Reporting\SectionKey;
use App\Models\ReportType;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Comportamento e atitude face às aprendizagens» (§8, §9, §10, §11).
 *
 * EVERY WORD IN THIS SECTION IS THE TEACHER'S. LÁPIS holds disciplinary
 * occurrences and merit records; it does not hold behaviour, and it certainly
 * does not hold attitude. Eight incidents in a class of twenty-six is a count —
 * turning it into «a turma tem problemas de comportamento» is exactly the
 * inference §12 forbids. So this composer reads answers and writes sentences; it
 * reads no data at all.
 *
 * WHICH MEANS SILENCE PRODUCES NOTHING. A teacher who has not answered gets no
 * section: not a neutral sentence, not «sem registo de problemas», not a
 * paragraph explaining that the question was not answered. The whole section
 * simply does not appear (§41, §67).
 *
 * BEHAVIOUR AND ATTITUDE ARE TWO QUESTIONS (§8) and get two sentences. A class
 * can be perfectly well behaved and disengaged, and a report that merged them
 * would lose the distinction a conselho de turma actually meets to discuss.
 */
class BehaviourAttitudeComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::BehaviourAttitude;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $subject = $context->report->type === ReportType::Student ? 'O aluno' : 'A turma';

        $behaviour = $this->behaviourSentence($context, $subject);
        $attitude = $this->attitudeSentence($context, $subject);
        $indicators = $this->indicatorParagraph($context);
        $observation = $this->clean($context->input('observation'));

        if ($behaviour === null && $attitude === null && $indicators === null && $observation === null) {
            return ComposedSection::empty();
        }

        return ComposedSection::of(
            Phrase::body([
                Phrase::paragraph([$behaviour, $attitude]),
                $indicators,
                $observation === null ? null : Phrase::terminate($observation),
            ]),
            [ContentSource::TeacherInput],
            [
                'behaviour' => $context->input('behaviour'),
                'attitude' => $context->input('attitude'),
                'indicators' => $context->input('indicators', []),
            ],
        );
    }

    protected function behaviourSentence(ReportContext $context, string $subject): ?string
    {
        $answer = $context->input('behaviour');
        $rating = is_string($answer) ? BehaviourRating::tryFrom($answer) : null;

        if ($rating === null || ! $rating->characterises()) {
            return null;
        }

        return Phrase::sentence(
            'O comportamento',
            $subject === 'O aluno' ? 'do aluno' : 'da turma',
            'foi, globalmente,',
            $rating->clause(),
        );
    }

    protected function attitudeSentence(ReportContext $context, string $subject): ?string
    {
        $answer = $context->input('attitude');
        $attitude = is_string($answer) ? LearningAttitude::tryFrom($answer) : null;

        if ($attitude === null || ! $attitude->characterises()) {
            return null;
        }

        return Phrase::sentence(
            'A atitude face às aprendizagens revelou-se',
            $attitude->clause(),
        );
    }

    /**
     * The flagged aspects, grouped by the direction the teacher gave them.
     *
     * A bare list of nouns tells the reader nothing — «assinalam-se: a
     * participação, a autonomia» could mean either strength or gap. Grouping by
     * standing is what makes the sentence say something, and the standing is
     * always a choice somebody made rather than a valence LÁPIS assigned (§11).
     */
    protected function indicatorParagraph(ReportContext $context): ?string
    {
        $chosen = $context->input('indicators');

        if (! is_array($chosen) || $chosen === []) {
            return null;
        }

        /** @var array<string, list<string>> $grouped */
        $grouped = [];

        foreach ($chosen as $row) {
            if (! is_array($row)) {
                continue;
            }

            $indicator = is_string($row['indicator'] ?? null)
                ? ComplementaryIndicator::tryFrom($row['indicator'])
                : null;
            $standing = is_string($row['standing'] ?? null)
                ? IndicatorStanding::tryFrom($row['standing'])
                : null;

            if ($indicator === null || $standing === null) {
                continue;
            }

            $grouped[$standing->value] ??= [];
            $grouped[$standing->value][] = $indicator->noun();
        }

        if ($grouped === []) {
            return null;
        }

        $sentences = [];

        // Strengths first, then what needs work: a paragraph that opens on a
        // deficit reads as an indictment, and the order is the only thing here
        // that is a choice of tone rather than of content.
        foreach ([IndicatorStanding::Strength, IndicatorStanding::ToImprove, IndicatorStanding::Irregular, IndicatorStanding::Flagged] as $standing) {
            $nouns = $grouped[$standing->value] ?? [];

            if ($nouns === []) {
                continue;
            }

            $sentences[] = Phrase::sentence(
                count($nouns) === 1 ? $standing->singularLead() : $standing->lead(),
                Phrase::items($nouns),
            );
        }

        return Phrase::paragraph($sentences);
    }

    protected function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $text = trim($value);

        return $text === '' ? null : $text;
    }
}
