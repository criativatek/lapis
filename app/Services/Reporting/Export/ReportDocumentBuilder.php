<?php

namespace App\Services\Reporting\Export;

use App\Models\Report;
use App\Models\ReportSection;
use App\Services\Documents\DocumentIdentity;
use App\Services\Documents\SchoolLogoService;
use Illuminate\Support\Carbon;
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
 * THE LOGO TRAVELS AS BYTES, AND ONLY WHEN IT WAS ASKED FOR. A renderer cannot
 * follow a session-protected URL, and it should not: the file is read here,
 * server-side, from the private disk, and handed over already embedded. It is
 * read at all for a report that `Report::showsLogo()` says carries one — a
 * school that uploaded a logo for its own screens did not thereby decide that
 * every relatório de turma leaving the building carries it (§50). The question
 * is asked there and not answered here, so this file and the preview cannot
 * come to different conclusions about the same document.
 *
 * FOR A SIGNED DOCUMENT THE PATH IS THE FROZEN ONE, never the school's current
 * identity: what this prints is the letterhead as it was at signature (§39).
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
     * What the document is called and the line of metadata under it.
     *
     * PUBLIC BECAUSE THE SCREEN NEEDS THE SAME ANSWER. The online preview is
     * the third rendering of this document, and a heading it composed for
     * itself would be a fourth opinion about what the report is called (§47).
     * Cheap on purpose — it reads no sections and no logo bytes.
     *
     * @return array{title: string, subtitle: string}
     */
    public function heading(Report $report): array
    {
        if (! $report->isFinalized()) {
            return DocumentHeading::for(
                title: $report->title,
                typeLabel: $report->type->label(),
                metadata: $this->metadata($report),
            );
        }

        $document = (array) $report->document;

        // FROM THE SNAPSHOT, NOT FROM THE RELATIONS. The heading names a turma,
        // a disciplina and a período as they were — a class renamed in
        // September does not rename a report signed in February (§39).
        return DocumentHeading::for(
            title: (string) data_get($document, 'report.title', $report->title),
            typeLabel: (string) data_get($document, 'report.type_label', $report->type->label()),
            metadata: [
                data_get($document, 'context.class.label'),
                data_get($document, 'context.class.subject'),
                data_get($document, 'context.enrollment.name'),
                data_get($document, 'report.scope_label', $report->scope_label),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromDocument(Report $report): array
    {
        $document = (array) $report->document;
        $identity = (array) ($document['identity'] ?? []);

        $heading = $this->heading($report);

        return [
            'title' => $heading['title'],
            'subtitle' => $heading['subtitle'],
            // Never labelled a draft: this one is signed.
            'draft_note' => null,
            'identity' => $this->identityBlock(
                $identity,
                $report->showsLogo() ? (string) ($identity['logo_path'] ?? '') : '',
            ),
            'sections' => $this->sectionsFromDocument($document),
            'meta' => [
                'author' => data_get($document, 'report.author'),
                'finalized_at' => data_get($document, 'finalized_at'),
                'finalized_by' => data_get($document, 'finalized_by'),
                'scope_label' => (string) data_get($document, 'report.scope_label', $report->scope_label),
                'status' => 'finalized',
                'closing' => $this->closing(
                    data_get($document, 'finalized_by'),
                    data_get($document, 'finalized_at'),
                ),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function fromDraft(Report $report): array
    {
        $identity = $this->identity->forCurrentOrganization();
        $logoPath = $report->showsLogo() ? $report->organization->identity?->logo_path : null;

        $heading = $this->heading($report);

        return [
            'title' => $heading['title'],
            'subtitle' => $heading['subtitle'],
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
                'closing' => null,
            ],
        ];
    }

    /**
     * What the document is about, one fact per entry.
     *
     * ATOMIC ON PURPOSE. DocumentHeading compares these against the title's own
     * segments, and a composite «7.º A · Português» would never match the
     * «7.º A» a generated title carries — the duplication would survive the
     * very step that exists to remove it.
     *
     * @return list<string|null>
     */
    protected function metadata(Report $report): array
    {
        return [
            $report->schoolClass?->label,
            $report->schoolClass?->subject->name,
            $report->enrollment === null ? null : optional($report->enrollment->student->identity)->display_name,
            $report->scope_label,
        ];
    }

    /**
     * The sentence that closes a finished document.
     *
     * BUILT AS ONE STRING, not assembled by a template out of conditional
     * blocks. Doing it in Blade printed «finalizado por Ana Martins em
     * 19/08/2026 .» — the newline before the full stop became a space, which is
     * exactly the kind of detail a teacher notices on a document they are about
     * to send to a família.
     */
    protected function closing(mixed $finalizedBy, mixed $finalizedAt): string
    {
        $parts = ['Relatório finalizado'];

        if (is_string($finalizedBy) && trim($finalizedBy) !== '') {
            $parts[] = 'por '.trim($finalizedBy);
        }

        if (is_string($finalizedAt) && trim($finalizedAt) !== '') {
            $parts[] = 'em '.Carbon::parse($finalizedAt)->format('d/m/Y');
        }

        return implode(' ', $parts).'.';
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
