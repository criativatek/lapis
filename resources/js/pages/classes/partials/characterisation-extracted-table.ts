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

/** Mirrors ExtractedRow. `kind` starts 'unknown' from a FRESH client-built
 * table (OCR's own output, before any review) — NormaliseExtractedTable
 * classifies Header/Data/Group/Legend server-side, once, with the whole
 * table visible, exactly as it does for every other source.
 *
 * The other four values are §39's: once the teacher has reviewed the
 * structural grid (CharacterisationImportDialog's "Rever tabela
 * reconhecida") and corrected a row's kind herself, the corrected table is
 * sent back carrying that EXPLICIT kind — mirroring ExtractedRowKind's own
 * cases — so NormaliseExtractedTable honours her decision instead of
 * re-running its own header/group/legend guesses on it (see that class's
 * own docblock on why re-guessing a reviewed row would be wrong). */
export type ExtractedRowPayload = {
    index: number;
    cells: ExtractedCellPayload[];
    kind: 'unknown' | 'header' | 'data' | 'group' | 'legend';
};

/** Mirrors ExtractedTable. `source_type` is one of the FOUR variants the
 * server accepts from this client-built payload (see
 * CharacterisationImportController::parseExtractedTablePayload): the two OCR
 * ones (ExtractedTableSource::PastedImage/::ImageUpload) for a fresh OCR
 * run, and the two §39 "corrected" ones (::CorrectedDocx/
 * ::CorrectedPastedHtml) for a .docx/pasted-HTML table resubmitted after the
 * structural review step. Never ::Docx or ::PastedHtml themselves — those
 * stay reserved for a genuine server-side read of the original file. */
export type ExtractedTablePayload = {
    rows: ExtractedRowPayload[];
    source_type: 'pasted_image' | 'image_upload' | 'corrected_docx' | 'corrected_pasted_html';
    source_filename: string | null;
    warnings: string[];
    extraction_confidence: number;
};
