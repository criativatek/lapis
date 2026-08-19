<?php

namespace App\Services\Reporting\Export;

use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\SchoolLogoService;
use Illuminate\Support\Facades\Storage;

/**
 * THE ONE PLACE A REPORT BECOMES A DOCUMENT (§47).
 *
 * PDF and Word are two renderings of ONE structure, and this is the structure.
 * Neither renderer reads a report, a section row or a frozen document; they read
 * what comes out of here. That is the whole guarantee behind «os dois devem
 * partir do MESMO conteúdo final» — not a convention two files agree to follow,
 * but the only input either of them has.
 *
 * A FINALIZED REPORT IS READ FROM ITS DOCUMENT; A DRAFT FROM ITS ROWS. Exporting
 * a finished report never touches live data — the whole point of finalizing —
 * and exporting a draft is allowed and marked as such, because a teacher taking
 * a working copy to a conselho de turma is a real thing to want and pretending
 * otherwise just means they print the screen instead.
 *
 * THE LOGO TRAVELS AS BYTES. A renderer cannot follow a session-protected URL,
 * and it should not: the file is read here, server-side, from the private disk,
 * and handed over already embedded.
 */
class ReportDocumentBuilder
{
    public function __construct(protected DocumentIdentity $identity) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Report $report): array
    {
        return $report->isFinalized()
            ? $this->fromDocument($report)
            : $this->fromDraft($report);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromDocument(Report $report): array
    {
        $document = (array) $report->document;
        $identity = (array) ($document['identity'] ?? []);

        return [
            'title' => (string) data_get($document, 'report.title', $report->title),
            'subtitle' => $this->subtitle($report),
            // Never labelled a draft: this one is signed.
            'draft_note' => null,
            'identity' => $this->identityBlock($identity, (string) ($identity['logo_path'] ?? '')),
            'sections' => $this->sectionsFromDocument($document),
            'meta' => [
                'author' => data_get($document, 'report.author'),
                'finalized_at' => data_get($document, 'finalized_at'),
                'finalized_by' => data_get($document, 'finalized_by'),
                'scope_label' => (string) data_get($document, 'report.scope_label', $report->scope_label),
                'status' => 'finalized',
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromDraft(Report $report): array
    {
        $identity = $this->identity->forCurrentOrganization();
        $logoPath = $report->organization->identity?->logo_path;

        return [
            'title' => $report->title,
            'subtitle' => $this->subtitle($report),
            // STAMPED, NOT HIDDEN. A working copy that looks finished is how a
            // draft ends up in a parent's hands as though it were the record.
            'draft_note' => 'RASCUNHO — documento de trabalho, ainda não finalizado.',
            'identity' => $this->identityBlock($identity, (string) $logoPath),
            'sections' => $this->sectionsFromRows($report),
            'meta' => [
                'author' => $report->author?->name,
                'finalized_at' => null,
                'finalized_by' => null,
                'scope_label' => $report->scope_label,
                'status' => 'draft',
            ],
        ];
    }

    protected function subtitle(Report $report): string
    {
        $parts = array_filter([
            $report->type->label(),
            $report->schoolClass === null ? null : $report->schoolClass->label.' · '.$report->schoolClass->subject->name,
            $report->enrollment === null ? null : optional($report->enrollment->student->identity)->display_name,
            $report->scope_label,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $identity
     * @return array<string, mixed>
     */
    protected function identityBlock(array $identity, string $logoPath): array
    {
        return [
            'name' => (string) ($identity['name'] ?? ''),
            'header_lines' => array_values((array) ($identity['header_lines'] ?? [])),
            'footer_note' => $identity['footer_note'] ?? null,
            'logo' => $this->logo($logoPath),
        ];
    }

    /**
     * The logo's own bytes, or nothing.
     *
     * @return array{data: string, mime: string, extension: string}|null
     */
    protected function logo(string $path): ?array
    {
        if ($path === '') {
            return null;
        }

        $disk = Storage::disk(SchoolLogoService::DISK);

        if (! $disk->exists($path)) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION) ?: 'png');

        return [
            'data' => (string) $disk->get($path),
            'mime' => match ($extension) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            },
            'extension' => $extension,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<array<string, mixed>>
     */
    protected function sectionsFromDocument(array $document): array
    {
        $sections = [];

        foreach ((array) ($document['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $sections[] = [
                'heading' => (string) ($section['heading'] ?? ''),
                'paragraphs' => $this->paragraphs($section['body'] ?? null),
                'tables' => SectionTables::for((string) ($section['key'] ?? ''), $section['data'] ?? null),
            ];
        }

        return $sections;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sectionsFromRows(Report $report): array
    {
        return array_values($report->sections()
            ->where('included', true)
            ->orderBy('position')
            ->get()
            ->filter(fn (ReportSection $section) => $section->hasContent())
            ->map(fn (ReportSection $section) => [
                'heading' => $section->heading,
                'paragraphs' => $this->paragraphs($section->body),
                'tables' => SectionTables::for($section->key, $section->data),
            ])
            ->values()
            ->all());
    }

    /**
     * A body into paragraphs.
     *
     * Blank lines separate paragraphs; single newlines inside one are kept as
     * lines, because the list sections («— Maria Silva: …») rely on them and
     * flattening them would run the names together.
     *
     * @return list<string>
     */
    protected function paragraphs(mixed $body): array
    {
        if (! is_string($body) || trim($body) === '') {
            return [];
        }

        $blocks = preg_split('/\n\s*\n/u', trim($body)) ?: [];

        return array_values(array_filter(array_map('trim', $blocks), fn (string $block) => $block !== ''));
    }
}
