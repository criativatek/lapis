<?php

namespace App\Domain\Import\Timetable;

/**
 * A whole PDF as the extraction layer read it — every page, in order.
 */
final readonly class ExtractedPdfDocument
{
    /**
     * @param  list<ExtractedPdfPage>  $pages
     */
    public function __construct(public array $pages) {}

    /**
     * Every page's linear text, in page order.
     */
    public function text(): string
    {
        return implode("\n", array_map(
            fn (ExtractedPdfPage $page): string => $page->text,
            $this->pages,
        ));
    }

    /**
     * Every page's positioned fragments, in page order and, within a page, in
     * the order the PDF itself emitted them.
     *
     * That order matters: it is the reading order of the table, and the row a
     * cell belongs to is decided by which time-range label most recently
     * preceded it (see TimetableParser).
     *
     * @return list<PositionedTextFragment>
     */
    public function fragments(): array
    {
        $fragments = [];

        foreach ($this->pages as $page) {
            foreach ($page->fragments as $fragment) {
                $fragments[] = $fragment;
            }
        }

        return $fragments;
    }

    /**
     * Whether the document yielded any text at all.
     *
     * False for a scanned, image-only export — out of scope for this import (no
     * OCR), and an expected outcome rather than a bug, so the caller turns it
     * into a plain sentence for the teacher.
     */
    public function hasText(): bool
    {
        foreach ($this->pages as $page) {
            if (trim($page->text) !== '' || $page->fragments !== []) {
                return true;
            }
        }

        return false;
    }
}
