<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Síntese final».
 *
 * A RECAP IS NOT A CONCLUSION. On the Base plan this section restates, in one
 * paragraph, the three things the reader most needs to leave with — the scope,
 * the headline figure and the success rate — and adds nothing that was not
 * already established above. It does not decide whether the period «correu
 * bem»; that is a judgement, and the plan that allows judgements is a different
 * one (§4, §6).
 *
 * THE PERSPECTIVE FOR THE PERIOD AHEAD IS THE TEACHER'S SENTENCE, not the
 * system's. Where they wrote one it is carried through verbatim and attributed
 * to `teacher_input`; where they did not, the paragraph simply ends.
 *
 * Shared by every report type: what it recaps differs, the rule does not.
 */
class FinalSynthesisComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::FinalSynthesis;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $note = $this->clean($context->input('final_note'));
        $recap = $this->recap($context);

        if ($recap === null && $note === null) {
            return ComposedSection::empty();
        }

        $sources = [ContentSource::Statistics];

        if ($note !== null) {
            $sources[] = ContentSource::TeacherInput;
        }

        return ComposedSection::of(
            Phrase::body([$recap, $note === null ? null : Phrase::terminate($note)]),
            $sources,
        );
    }

    protected function recap(ReportContext $context): ?string
    {
        $summary = $context->fact('summary');

        if (! is_array($summary)) {
            return null;
        }

        $average = Phrase::percentage($summary['primary_average'] ?? null);
        $success = is_array($summary['success'] ?? null) ? $summary['success'] : [];
        $placed = (int) ($success['placed'] ?? 0);
        $rate = Phrase::percentage($success['rate'] ?? null);

        // Nothing to recap is not a paragraph saying there is nothing to
        // recap — the sections above already said so, once (§41).
        if ($average === null && $placed === 0) {
            return null;
        }

        return Phrase::sentence(
            'Em síntese, e no que respeita a',
            $context->scopeLabel().',',
            $average === null
                ? null
                : 'o resultado médio da turma situou-se em '.$average,
            $average !== null && $placed > 0 ? 'e' : null,
            $placed === 0 || $rate === null
                ? null
                : 'a taxa de sucesso, apurada sobre as classificações atribuídas, foi de '.$rate,
        );
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
