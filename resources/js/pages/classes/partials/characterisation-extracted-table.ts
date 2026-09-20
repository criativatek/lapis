/**
 * TypeScript mirror of the PHP extraction shape (app/Services/Characterisation
 * /Import/Extraction/{ExtractedTable,ExtractedRow,ExtractedCell,
 * ExtractedTableSource}.php), used ONLY by the image/OCR source: every other
 * client-side source (pasted text, pasted HTML) is sent to the server as raw
 * text/HTML and turned into an ExtractedTable there. OCR is the one source
 * that already has to build this shape in the browser — tesseract.js never
 * runs server-side (see the PRIVACY note in characterisation-image-extraction.ts)
 * — so it is built here, snake_cased to match, and posted as JSON in
 * `extracted_table` instead of a source string.
 *
 * Keep this in lockstep with the PHP classes by hand: there is no shared
 * schema generator in this codebase (see ReadCharacterisationTable's own
 * comment on why files are read by existing readers rather than a second
 * parser), and duplicating four small readonly shapes is cheaper than adding
 * one.
 */

/** Mirrors ExtractedCell. `confidence` is EXTRACTION confidence (0..1,
 * tesseract's own word confidence rescaled) — never the domain
 * CodeConfidence ("is this a recognised measure code?") used elsewhere in
 * the pipeline. See ExtractedCell.php's docblock for the full distinction. */
export type ExtractedCellPayload = {
    text: string;
    row: number;
    column: number;
    colspan: number;
    rowspan: number;
    confidence: number | null;
};

/** Mirrors ExtractedRow. `kind` always starts 'unknown' from a client-built
 * table — NormaliseExtractedTable classifies Header/Data/Group/Legend
 * server-side, once, with the whole table visible, exactly as it does for
 * every other source. */
export type ExtractedRowPayload = {
    index: number;
    cells: ExtractedCellPayload[];
    kind: 'unknown';
};

/** Mirrors ExtractedTable. `source_type` is one of the two OCR variants the
 * server already reserves (ExtractedTableSource::PastedImage /
 * ::ImageUpload) — never any of the other cases, which the server never
 * accepts from this payload (see the controller's source_kind validation). */
export type ExtractedTablePayload = {
    rows: ExtractedRowPayload[];
    source_type: 'pasted_image' | 'image_upload';
    source_filename: string | null;
    warnings: string[];
    extraction_confidence: number;
};
