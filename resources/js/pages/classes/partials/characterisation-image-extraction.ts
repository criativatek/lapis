/**
 * The seam a future OCR/image-recognition slice fills in.
 *
 * CharacterisationImportDialog.vue already detects an `image/*` clipboard
 * paste or file (§ scope item 6) and needs somewhere to hand that blob to —
 * this function is that somewhere. It is deliberately narrow (one function,
 * one file) so the image slice can replace it wholesale without touching the
 * dialog's paste-priority logic, and so a test can mock/replace it without
 * reaching into the component.
 *
 * ============================================================================
 * PRIVACY — NON-NEGOTIABLE. Read this before changing anything below.
 *
 * The image handed to this function may contain the names of minors — a
 * photographed characterisation sheet, a screenshot of a class list. It MUST
 * NEVER leave this browser tab: never sent to Gemini, never to any OCR web
 * service, never to any other third-party API, and never to the Lapispro
 * server either. All recognition happens IN-BROWSER, inside a tesseract.js
 * Web Worker running entirely against locally-hosted assets (see
 * TESSERACT_ASSET_PATHS below — every path is same-origin, none is a CDN).
 * Only the RECOGNISED TEXT (already structured into an ExtractedTable) is
 * ever sent onward, by the dialog, to the existing preview endpoint — the
 * same JSON a pasted-HTML or .docx table would produce. See
 * characterisation-image-extraction.test.ts, which asserts no
 * network call carries image bytes during this flow.
 * ============================================================================
 */
import type { ExtractedCellPayload, ExtractedRowPayload, ExtractedTablePayload } from './characterisation-extracted-table';
import { buildGridFromWords  } from './characterisation-ocr-grid';
import type {OcrWord} from './characterisation-ocr-grid';

/**
 * Every tesseract.js asset this feature uses, hosted at the same origin as
 * the app (public/vendor/tesseract/, served by Vite/Laravel exactly like any
 * other public/ file — see the paths chosen here against `ls public`).
 * tesseract.js's OWN default is a jsDelivr CDN for all three of these, which
 * this feature refuses on the same privacy grounds as sending the image
 * itself: a hidden third-party fetch on every OCR run is still a third
 * party learning "someone at this school just OCR'd a table", even without
 * the image bytes.
 *
 * None of these three files is committed. `worker.min.js` and the wasm core
 * ship inside the tesseract.js / tesseract.js-core npm packages we already
 * install, and `por.traineddata.gz` ships inside `@tesseract.js-data/por`;
 * a Vite plugin (resources/build/vite-plugin-tesseract-assets.ts) copies the
 * one core variant this file requests, plus the worker and the traineddata,
 * into public/vendor/tesseract at build/dev time. See
 * docs/characterisation-ocr.md.
 */
const TESSERACT_ASSET_PATHS = {
    workerPath: '/vendor/tesseract/worker.min.js',
    corePath: '/vendor/tesseract/tesseract-core-simd-lstm.wasm.js',
    langPath: '/vendor/tesseract/',
} as const;

/** Refused outright — a decoded bitmap at this size is hundreds of MB of
 * pixel data, the classic decompression-bomb shape (tiny file, huge
 * dimensions), and no photographed table is legitimately this large. */
const MAX_IMAGE_DIMENSION_PX = 6000;

/** Refused outright — this is a paste/upload dialog, not a document store;
 * an 8k scan is not what "photograph a table with a phone" produces. */
const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

export class ImageDecodeError extends Error {}

export type OcrProgress = {
    /** tesseract.js's own worker status string ('loading language traineddata',
     * 'recognizing text', …) — surfaced instead of translated 1:1, so the UI
     * decides how much of it to show rather than this module inventing a
     * second vocabulary for the same handful of states. */
    status: string;
    /** 0..1 */
    progress: number;
};

export type ExtractImageOptions = {
    /** Reused, not reinvented: the docx table-choice/parsing flow already
     * reports progress to the dialog through a callback — OCR extends the
     * same shape (status + 0..1 progress) instead of adding a second
     * progress vocabulary next to it. */
    onProgress?: (progress: OcrProgress) => void;
    /** AbortSignal is the platform's own cancellation primitive — reused
     * here instead of a bespoke `cancel()` method so the dialog's existing
     * fetch-cancellation pattern (if any) composes with this call for free. */
    signal?: AbortSignal;
    /** ExtractedTableSource::PastedImage vs ::ImageUpload (see
     * characterisation-extracted-table.ts) — the two things this function
     * can be called for, decided by the caller (paste vs file input), never
     * guessed here. */
    sourceKind: 'pasted_image' | 'image_upload';
    sourceFilename?: string | null;
};

/**
 * Decodes `blob` off-screen to (a) prove it is a real, intact image before
 * any OCR work starts, and (b) read its true pixel dimensions — a MIME type
 * or file extension proves nothing (see DocxTableExtractor's own comment on
 * why .docx validates itself by opening the file, not by trusting its
 * extension; the same reasoning applies to images and decompression bombs).
 */
async function decodeAndValidateImage(blob: Blob): Promise<ImageBitmap> {
    if (blob.size > MAX_IMAGE_BYTES) {
        throw new ImageDecodeError('A imagem é demasiado grande.');
    }

    let bitmap: ImageBitmap;

    try {
        bitmap = await createImageBitmap(blob);
    } catch {
        // A file that merely LOOKS like an image (wrong bytes, truncated,
        // a renamed non-image) fails here rather than being handed to
        // tesseract, which would otherwise fail much later with a far less
        // actionable error.
        throw new ImageDecodeError('Não foi possível ler esta imagem.');
    }

    if (bitmap.width > MAX_IMAGE_DIMENSION_PX || bitmap.height > MAX_IMAGE_DIMENSION_PX) {
        bitmap.close();

        throw new ImageDecodeError('A imagem tem uma resolução superior ao que esta importação aceita.');
    }

    return bitmap;
}

/**
 * The actual OCR call, split out from extractTableFromImage() so tests can
 * mock tesseract.js without touching the validation/decoding path above —
 * exactly the boundary the privacy test needs (see its own comment for why).
 */
async function recogniseWords(
    bitmap: ImageBitmap,
    options: Pick<ExtractImageOptions, 'onProgress' | 'signal'>,
): Promise<OcrWord[]> {
    // Dynamic import: tesseract.js (worker glue + wasm loader) must never be
    // part of the initial bundle — it is only ever needed once a teacher
    // actually pastes or picks an image. `npm run build`'s own chunk report
    // is how this stays true; see docs/characterisation-ocr.md.
    const { createWorker } = await import('tesseract.js');

    const canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
    const context = canvas.getContext('2d');

    if (context === null) {
        throw new ImageDecodeError('Não foi possível processar esta imagem.');
    }

    context.drawImage(bitmap, 0, 0);

    const worker = await createWorker('por', 1, {
        ...TESSERACT_ASSET_PATHS,
        logger: (message) => {
            if (typeof message.progress === 'number') {
                options.onProgress?.({ status: message.status, progress: message.progress });
            }
        },
    });

    const terminate = () => {
        // Fire-and-forget: cancellation must not itself become an awaited,
        // rejectable promise the caller has to handle a second time.
        void worker.terminate();
    };

    if (options.signal?.aborted) {
        terminate();

        throw new DOMException('Cancelled', 'AbortError');
    }

    options.signal?.addEventListener('abort', terminate, { once: true });

    try {
        const { data } = await worker.recognize(canvas as unknown as Parameters<typeof worker.recognize>[0], {}, {
            blocks: true,
        });

        // Word-level boxes, not tesseract's own line/paragraph grouping —
        // see characterisation-ocr-grid.ts's own comment for why the line
        // grouping is not trusted for a table.
        const words = (data.blocks ?? [])
            .flatMap((block) => block.paragraphs)
            .flatMap((paragraph) => paragraph.lines)
            .flatMap((line) => line.words)
            .map(
                (word): OcrWord => ({
                    text: word.text,
                    confidence: word.confidence,
                    bbox: word.bbox,
                }),
            );

        return words;
    } finally {
        options.signal?.removeEventListener('abort', terminate);
        // Always terminate — on success as much as on failure/cancel. A
        // worker left running is a wasm runtime and a loaded language model
        // sitting in memory for a dialog the teacher has already moved past.
        terminate();
    }
}

/**
 * Chooses the column-boundary gap threshold from the image's own width
 * rather than a fixed pixel count (see buildGridFromWords's own doc) — a
 * fraction of image width scales with both a tight crop and a full-page
 * photo of the same table.
 */
function gapThresholdFor(bitmap: ImageBitmap): number {
    return Math.max(20, bitmap.width * 0.02);
}

function toPayloadRows(words: OcrWord[], gapPx: number): ExtractedRowPayload[] {
    const grid = buildGridFromWords(words, gapPx);

    return grid.rows.map(
        (row, rowIndex): ExtractedRowPayload => ({
            index: rowIndex,
            kind: 'unknown',
            cells: row.map(
                (cell, columnIndex): ExtractedCellPayload => ({
                    text: cell.text,
                    row: rowIndex,
                    column: columnIndex,
                    colspan: 1,
                    rowspan: 1,
                    confidence: cell.confidence,
                }),
            ),
        }),
    );
}

/**
 * Reads a table out of a pasted/uploaded image entirely in-browser and
 * returns it in the exact shape the server-side extractors produce (see
 * characterisation-extracted-table.ts). Never sends the image anywhere —
 * see the PRIVACY block at the top of this file.
 */
export async function extractTableFromImage(
    file: File | Blob,
    options: ExtractImageOptions,
): Promise<ExtractedTablePayload> {
    const bitmap = await decodeAndValidateImage(file);

    try {
        const words = await recogniseWords(bitmap, options);
        const rows = toPayloadRows(words, gapThresholdFor(bitmap));

        const confidences = rows
            .flatMap((row) => row.cells)
            .map((cell) => cell.confidence)
            .filter((value): value is number => value !== null);

        const extractionConfidence =
            confidences.length === 0 ? 1 : confidences.reduce((sum, value) => sum + value, 0) / confidences.length;

        return {
            rows,
            source_type: options.sourceKind,
            source_filename: options.sourceFilename ?? null,
            warnings: [],
            extraction_confidence: extractionConfidence,
        };
    } finally {
        bitmap.close();
    }
}
