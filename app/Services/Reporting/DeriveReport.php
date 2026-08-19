<?php

namespace App\Services\Reporting;

use App\Domain\Reporting\SectionCatalogue;
use App\Domain\Reporting\SectionDefinition;
use App\Models\AcademicPeriod;
use App\Models\InterimAssessment;
use App\Models\Report;
use App\Models\ReportScopeKind;
use App\Models\ReportSection;
use App\Models\ReportStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * «Usar relatório anterior como base» (§31–§35).
 *
 * THE OLD REPORT IS NEVER TOUCHED. What comes out is a NEW draft that records
 * where it started from; the original keeps its text, its numbers and its
 * status exactly as they were, whatever happens to the derived one afterwards
 * (§32).
 *
 * WHAT IS INHERITED AND WHAT IS NOT — the distinction is the whole feature:
 *
 *  - INHERITED, because it is the teacher's judgement and judgements carry
 *    forward: the characterisation, the validated difficulties and the
 *    strategies chosen for them, the planning statement, the closing note, the
 *    tone, which sections were included, and any paragraph they rewrote
 *    themselves (§33).
 *
 *  - NOT INHERITED, ever: the numbers. Averages, distributions, evolution,
 *    domain figures, counts of records and interventions all come from the NEW
 *    context. A section whose text the teacher never touched is regenerated
 *    from scratch; a section they DID rewrite keeps their words and gets fresh
 *    figures underneath it, flagged as edited so they can see it needs a second
 *    look (§34).
 *
 * That last rule is the one that could quietly go wrong: carrying a February
 * paragraph containing «66,4%» into a June report and leaving it there would be
 * reporting last term's number as this term's. It is kept, because the teacher
 * wrote it — and it is marked edited, which is what the editor shows.
 */
class DeriveReport
{
    public function __construct(
        protected ReportCapabilities $capabilities,
        protected ComposeReport $composer,
        protected AuditLog $audit,
    ) {}

    /**
     * @param  AcademicPeriod|null  $period  The new context; null keeps the original's.
     */
    public function derive(
        Report $source,
        User $author,
        ?AcademicPeriod $period = null,
        ?InterimAssessment $interim = null,
        ?string $title = null,
    ): Report {
        $draft = DB::transaction(function () use ($source, $author, $period, $interim, $title): Report {
            $draft = Report::create([
                'type' => $source->type,
                'status' => ReportStatus::Draft,
                'title' => $title ?? $this->derivedTitle($source, $period, $interim),
                'tone' => $source->tone,
                'class_id' => $source->class_id,
                'enrollment_id' => $source->enrollment_id,
                'academic_year_id' => $source->academic_year_id,
                // The new moment, or the same one when none was chosen. `->`
                // rather than `?->`: ?? already tolerates a null object.
                'academic_period_id' => $period->id ?? $source->academic_period_id,
                'interim_assessment_id' => $interim?->id,
                ...$this->scopeFor($source, $period, $interim),
                'options' => $source->options,
                // §33: the teacher's own statements travel. They are judgements
                // about a class, not measurements of it.
                'teacher_input' => $source->teacher_input,
                'teacher_input_version' => $source->teacher_input_version,
                'based_on_report_id' => $source->getKey(),
                'template_key' => $source->template_key,
                'created_by' => $author->id,
            ]);

            $this->copySections($source, $draft);

            return $draft;
        });

        // Regenerate: every untouched section is rewritten from the NEW data,
        // and every section the teacher wrote keeps their words (§34).
        $this->composer->generate($draft);

        $this->audit->record(
            'report.derived',
            $draft,
            $author,
            summary: "Relatório «{$draft->title}» criado a partir de «{$source->title}».",
            properties: ['based_on' => $source->ulid],
        );

        return $draft->fresh(['sections']) ?? $draft;
    }

    /**
     * Copy the structure, and the text only where the teacher wrote it.
     *
     * `generated_body` is deliberately NOT copied: it is the old data's
     * sentences, and carrying it over would let «restaurar texto automático»
     * restore February's numbers into a June report. Regeneration fills it
     * immediately afterwards with the new ones.
     */
    protected function copySections(Report $source, Report $draft): void
    {
        // Only sections the current catalogue and the current plan still have.
        // A report derived after a downgrade must not carry a section the
        // school is no longer entitled to (§4).
        $allowed = array_map(
            fn (SectionDefinition $definition) => $definition->key->value,
            $this->capabilities->sectionsFor($source->type),
        );

        $existing = $source->sections()->orderBy('position')->get()->keyBy('key');
        $position = 0;

        foreach (SectionCatalogue::for($source->type) as $definition) {
            $key = $definition->key->value;

            if (! in_array($key, $allowed, strict: true)) {
                continue;
            }

            $position += 10;

            /** @var ReportSection|null $previous */
            $previous = $existing->get($key);

            $draft->sections()->create([
                'key' => $key,
                'heading' => $previous->heading ?? $definition->heading,
                'position' => $position,
                'included' => $previous === null ? $definition->defaultIncluded : $previous->included,
                // The teacher's own paragraph travels; the generated one does
                // not. `edited` travels with it, so the editor shows that this
                // text is theirs and predates the current figures.
                'body' => $previous !== null && $previous->edited ? $previous->body : null,
                'generated_body' => null,
                'edited' => $previous !== null && $previous->edited,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function scopeFor(Report $source, ?AcademicPeriod $period, ?InterimAssessment $interim): array
    {
        if ($interim !== null) {
            return [
                'scope_kind' => ReportScopeKind::Interim,
                'scope_label' => $interim->name.' ('.$interim->reference_date->format('d/m/Y').')',
                'starts_on' => null,
                'ends_on' => $interim->reference_date,
            ];
        }

        if ($period !== null) {
            return [
                'scope_kind' => ReportScopeKind::Period,
                'scope_label' => (string) $period->label,
                'starts_on' => $period->starts_on,
                'ends_on' => $period->ends_on,
            ];
        }

        // Same moment as the original: a derived report that changes nothing
        // about WHEN is a second attempt at the same document, which is a
        // legitimate reason to derive one.
        return [
            'scope_kind' => $source->scope_kind,
            'scope_label' => $source->scope_label,
            'starts_on' => $source->starts_on,
            'ends_on' => $source->ends_on,
        ];
    }

    protected function derivedTitle(Report $source, ?AcademicPeriod $period, ?InterimAssessment $interim): string
    {
        if ($period !== null) {
            // Replace the old moment in the title rather than appending to it,
            // so a report derived three times is not «… (cópia) (cópia)».
            return $source->type->label().' · '.$this->subjectOf($source).' · '.$period->label;
        }

        if ($interim !== null) {
            return $source->type->label().' · '.$this->subjectOf($source).' · '.$interim->name;
        }

        return $source->title;
    }

    protected function subjectOf(Report $source): string
    {
        return match (true) {
            $source->enrollment !== null => optional($source->enrollment->student->identity)->display_name ?? '—',
            $source->schoolClass !== null => (string) $source->schoolClass->label,
            default => '—',
        };
    }
}
