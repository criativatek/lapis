<?php

namespace App\Services\Reporting;

use App\Models\Report;
use App\Models\ReportSection;
use App\Models\ReportStatus;
use App\Models\User;
use App\Services\Audit\AuditLog;
use App\Services\Documents\SchoolLogoService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Turning a draft into a document (§37, §38, §39).
 *
 * AFTER THIS, THE REPORT STOPS LISTENING TO THE WORLD. Everything it says is
 * copied into `document`: the text, the structure, the figures, the school's
 * letterhead, the temporal scope, the provenance of each section, and the
 * teacher's own answers. A grade corrected in May does not rewrite a report
 * finished in February, a renamed domain does not rename it retroactively, and
 * reprinting it in September does not run a single query against live data.
 *
 * THE LOGO IS COPIED, NOT LINKED — AND ONLY FOR A REPORT THAT ASKED FOR ONE.
 * This is the one place where «snapshot» would otherwise quietly fail: the
 * letterhead URL is stable, so replacing the file behind it would silently
 * change every finished document. The bytes are duplicated into `report-logos/`
 * at finalization and the frozen identity points at the copy. A report whose
 * `show_logo` option is off freezes no logo at all, so nothing is duplicated
 * for a document that will never print it (§50).
 *
 * THE FROZEN URL IS RELATIVE. An absolute one bakes today's APP_URL into a
 * document meant to outlive the installation: a report finalized on a local
 * Herd and read afterwards on the real domain pointed its letterhead at
 * `http://lapis.test/…`, and the browser drew a broken image. The path is
 * stored; whoever renders the page builds the address it is being served from.
 *
 * SECTIONS WITH NOTHING TO SAY DO NOT ENTER THE DOCUMENT. A heading over a
 * blank is worse than no heading; and a section excluded by the teacher was
 * excluded (§41, §45).
 *
 * IT IS NOT REVERSIBLE. There is no `unfinalize`: correcting a finished report
 * means deriving a new one from it, which is what DeriveReport is for (§32).
 */
class FinalizeReport
{
    public const LOGO_DIRECTORY = 'report-logos';

    public function __construct(
        protected ReportContextFactory $contexts,
        protected AuditLog $audit,
    ) {}

    public function finalize(Report $report, User $by): Report
    {
        if (! $report->isDraft()) {
            throw new \LogicException('Este relatório já se encontra finalizado.');
        }

        $document = $this->document($report, $by);

        DB::transaction(function () use ($report, $by, $document): void {
            // forceFill, because `status` moving to finalized is the one write
            // the model's own guard must not catch: the guard fires on rows
            // that were ALREADY finalized, and this one is not yet.
            $report->forceFill([
                'status' => ReportStatus::Finalized,
                'document' => $document,
                'document_version' => Report::CURRENT_DOCUMENT_VERSION,
                'document_hash' => Report::hashFor($document),
                'finalized_at' => now(),
                'finalized_by' => $by->getKey(),
            ])->save();
        });

        $this->audit->record(
            'report.finalized',
            $report,
            $by,
            summary: "Relatório «{$report->title}» finalizado.",
            properties: ['type' => $report->type->value, 'scope' => $report->scope_label],
        );

        return $report;
    }

    /**
     * The frozen document.
     *
     * @return array<string, mixed>
     */
    protected function document(Report $report, User $by): array
    {
        $context = $this->contexts->for($report);

        $sections = $report->sections()
            ->where('included', true)
            ->orderBy('position')
            ->get()
            ->filter(fn (ReportSection $section) => $section->hasContent())
            ->values();

        return [
            'version' => Report::CURRENT_DOCUMENT_VERSION,
            'finalized_at' => now()->toIso8601String(),
            'finalized_by' => $by->name,
            'report' => [
                'ulid' => $report->ulid,
                'type' => $report->type->value,
                'type_label' => $report->type->label(),
                'title' => $report->title,
                'tone' => $report->tone->value,
                'scope_kind' => $report->scope_kind->value,
                'scope_label' => $report->scope_label,
                'starts_on' => $report->starts_on?->toDateString(),
                'ends_on' => $report->ends_on?->toDateString(),
                'author' => $report->author?->name,
                'created_at' => $report->created_at->toIso8601String(),
            ],
            // What the document is ABOUT, in the words it had at the time. A
            // class renamed next year does not rename it here.
            'context' => [
                'class' => $context->fact('class'),
                'enrollment' => $context->fact('enrollment'),
                'roster' => $context->fact('roster'),
                'period_label' => $context->fact('period_label'),
            ],
            'identity' => $this->identitySnapshot($report, $context),
            'sections' => $sections->map(fn (ReportSection $section) => [
                'key' => $section->key,
                'heading' => $section->heading,
                'position' => $section->position,
                'body' => $section->body,
                'data' => $section->data,
                // §40: where each section's content came from, kept with it so
                // a finished report can still answer «de onde vem isto».
                'sources' => $section->sources ?? [],
                // Whether the teacher rewrote it. Part of the provenance, not a
                // judgement about the text.
                'edited' => $section->edited,
            ])->all(),
            // The teacher's own answers, frozen with everything else: they are
            // half the content of an analytical report (§8).
            'teacher_input' => $report->teacher_input,
            'options' => $report->options,
            // §30: which template this started from and exactly what it said,
            // so a finished report is still reproducible after the template has
            // been edited, deactivated or removed.
            'template' => $report->template_snapshot,
            'based_on' => $report->basedOn === null ? null : [
                'ulid' => $report->basedOn->ulid,
                'title' => $report->basedOn->title,
                'finalized_at' => $report->basedOn->finalized_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * The letterhead as it stood, with its own copy of the logo (§39).
     *
     * @return array<string, mixed>
     */
    protected function identitySnapshot(Report $report, ReportContext $context): array
    {
        $identity = $context->identity;

        $logoPath = $this->copyLogo($report);

        return [
            ...$identity,
            // The route that served the live logo is replaced by one that
            // serves THIS report's copy: same shape, frozen bytes — and stored
            // as a path, so the host it is read from is decided at read time.
            'logo_url' => $logoPath === null ? null : route('reports.logo', $report, absolute: false),
            'logo_path' => $logoPath,
            'has_logo' => $logoPath !== null,
            'show_logo' => $report->showsLogo(),
        ];
    }

    /**
     * Duplicate the school's current logo into this report's own file.
     *
     * A failure here is not worth failing a finalization over: the document is
     * complete without an image, and refusing to sign a report because a file
     * could not be copied would be the wrong trade. It is logged and the
     * document records that it has no logo.
     */
    protected function copyLogo(Report $report): ?string
    {
        // Never for a report that does not print one: a copy nothing reads is
        // a file about a school kept for no reason (§65).
        if (! $report->showsLogo()) {
            return null;
        }

        $source = $report->organization->identity?->logo_path;

        if ($source === null) {
            return null;
        }

        $disk = Storage::disk(SchoolLogoService::DISK);

        try {
            if (! $disk->exists($source)) {
                return null;
            }

            $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
            $destination = self::LOGO_DIRECTORY.'/'.Str::uuid()->toString().'.'.$extension;

            $disk->copy($source, $destination);

            return $destination;
        } catch (Throwable $exception) {
            Log::warning('Não foi possível guardar o logótipo no relatório finalizado.', [
                'report' => $report->ulid,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }
    }
}
