<?php

namespace App\Services\Reporting\Sections;

use App\Domain\Reporting\ContentSource;
use App\Domain\Reporting\PlanningCompliance;
use App\Domain\Reporting\SectionKey;
use App\Services\Reporting\ComposedSection;
use App\Services\Reporting\Narrative\Phrase;
use App\Services\Reporting\ReportContext;

/**
 * «Cumprimento da planificação» (§18).
 *
 * ENTIRELY THE TEACHER'S STATEMENT, TRANSCRIBED. Lapispro holds instruments,
 * scores and grades; it does not hold a planning, so there is nothing here to
 * derive and nothing to infer. What this does is turn four answers and some
 * optional free text into a paragraph that reads like a person wrote it — which
 * is the difference between a report and a form.
 *
 * SILENCE PRODUCES NOTHING. A teacher who has not answered gets no section at
 * all, never a default of «cumprida». «Não aplicável» is a different thing: it
 * is an answer, and it gets its own sentence.
 */
class PlanningComplianceComposer implements SectionComposer
{
    public function key(): SectionKey
    {
        return SectionKey::PlanningCompliance;
    }

    public function compose(ReportContext $context): ComposedSection
    {
        $answer = $context->input('planning.compliance');
        $compliance = is_string($answer) ? PlanningCompliance::tryFrom($answer) : null;

        if ($compliance === null) {
            return ComposedSection::empty();
        }

        $pending = $this->cleanList($context->input('planning.pending_content'));
        $postponed = $this->cleanList($context->input('planning.postponed_content'));
        $reason = $this->clean($context->input('planning.reason'));
        $recovery = $this->clean($context->input('planning.recovery_plan'));
        $note = $this->clean($context->input('planning.note'));

        $body = Phrase::body([
            Phrase::paragraph([
                $compliance->sentence(),
                $compliance->admitsPendingContent() ? $this->pendingSentence($pending) : null,
                $compliance->admitsPendingContent() ? $this->postponedSentence($postponed) : null,
            ]),
            Phrase::paragraph([
                $reason === null ? null : Phrase::sentence('O desvio face ao previsto deveu-se a', $reason),
                $recovery === null ? null : Phrase::sentence($recovery),
            ]),
            $note === null ? null : Phrase::sentence($note),
        ]);

        return ComposedSection::of(
            $body,
            [ContentSource::TeacherInput],
            [
                'compliance' => $compliance->value,
                'compliance_label' => $compliance->label(),
                'pending_content' => $pending,
                'postponed_content' => $postponed,
            ],
        );
    }

    /**
     * @param  list<string>  $pending
     */
    protected function pendingSentence(array $pending): ?string
    {
        if ($pending === []) {
            return null;
        }

        return Phrase::sentence(
            'Não foram abordados os conteúdos relativos a',
            Phrase::items($pending),
        );
    }

    /**
     * @param  list<string>  $postponed
     */
    protected function postponedSentence(array $postponed): ?string
    {
        if ($postponed === []) {
            return null;
        }

        return Phrase::sentence(
            'Os conteúdos relativos a',
            Phrase::items($postponed),
            'serão retomados no início do período seguinte',
        );
    }

    /**
     * @return list<string>
     */
    protected function cleanList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $clean = [];

        foreach ($value as $item) {
            $text = $this->clean($item);

            if ($text !== null) {
                $clean[] = $text;
            }
        }

        return $clean;
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
