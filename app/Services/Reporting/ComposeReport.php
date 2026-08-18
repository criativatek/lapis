<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionKey;
use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Reporting\Sections\SectionComposerRegistry;

/**
 * Runs the composers over a draft and writes what they produced.
 *
 * TWO TEXTS, AND THE TEACHER'S ALWAYS WINS. Every generation writes
 * `generated_body`. It writes `body` too — but only where the teacher has not
 * touched the section. Regenerating a report after correcting a grade must
 * update the numbers without silently deleting a paragraph somebody wrote at
 * eleven at night (§44).
 *
 * ONE CONTEXT FOR THE WHOLE PASS. The read models are read once and fourteen
 * sections are written from that single reading, so a class report costs one
 * pass whatever its length (§62) — and, more importantly, every section in it
 * describes the same moment.
 *
 * A DRAFT ONLY. A finalized report has no sections to regenerate: it has a
 * document. The model would throw anyway; this refuses earlier and says why.
 */
class ComposeReport
{
    public function __construct(
        protected ReportContextFactory $contexts,
        protected SectionComposerRegistry $composers,
    ) {}

    /**
     * (Re)generate every section of a draft.
     *
     * @return int How many sections produced content.
     */
    public function generate(Report $report): int
    {
        $this->guardDraft($report);

        $context = $this->contexts->for($report);
        $written = 0;

        foreach ($report->sections()->get() as $section) {
            if ($this->write($section, $context, overwriteEdited: false)) {
                $written++;
            }
        }

        return $written;
    }

    /**
     * Regenerate ONE section, replacing whatever is in it.
     *
     * This is «restaurar texto automático» and «regenerar esta secção»: the
     * teacher asked for it explicitly, so the edited text is overwritten and
     * `edited` goes back to false. Never called implicitly.
     */
    public function regenerate(ReportSection $section): bool
    {
        $report = $section->report;

        $this->guardDraft($report);

        return $this->write($section, $this->contexts->for($report), overwriteEdited: true);
    }

    /**
     * Restore one section's last automatic text without re-reading anything.
     *
     * Cheaper than regenerating and, more importantly, DIFFERENT: it puts back
     * the words that were generated from the data as it stood when the section
     * was last composed, rather than composing new ones from the data as it
     * stands now.
     */
    public function restore(ReportSection $section): bool
    {
        $this->guardDraft($section->report);

        if ($section->generated_body === null) {
            return false;
        }

        $section->update(['body' => $section->generated_body, 'edited' => false]);

        return true;
    }

    protected function write(ReportSection $section, ReportContext $context, bool $overwriteEdited): bool
    {
        $key = SectionKey::tryFrom($section->key);

        // A section whose key the current code no longer knows is left exactly
        // as it is. It belongs to a draft written by an older version, and
        // blanking it would destroy the teacher's text to tidy up a catalogue.
        if ($key === null) {
            return false;
        }

        $composer = $this->composers->find($key);

        if ($composer === null) {
            return false;
        }

        // The plan is checked here as well as at creation: a subscription can
        // lapse between the two, and a report must not keep generating a
        // section the school is no longer entitled to (§4).
        if (! $context->allows($key)) {
            return false;
        }

        $composed = $composer->compose($context);

        $attributes = [
            'generated_body' => $composed->body,
            'sources' => $composed->sourceValues(),
            'data' => $composed->data === [] ? null : $composed->data,
        ];

        if ($overwriteEdited || ! $section->edited) {
            $attributes['body'] = $composed->body;
            $attributes['edited'] = false;
        }

        $section->update($attributes);

        return $composed->hasContent();
    }

    protected function guardDraft(Report $report): void
    {
        if (! $report->isDraft()) {
            throw new \LogicException(
                'Um relatório finalizado já não se gera: o seu conteúdo está fixado. Crie um novo a partir dele.',
            );
        }
    }
}
