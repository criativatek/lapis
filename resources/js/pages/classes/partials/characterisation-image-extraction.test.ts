import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * tesseract.js's real worker spins up a Web Worker and loads WASM — far too
 * slow and non-deterministic for a unit test, and beside the point: what
 * this suite verifies is the SURROUNDING code (decode/validate, cancellation,
 * and — the one that matters most — that nothing here ever posts image bytes
 * over the network), not tesseract's own recognition. See the PRIVACY block
 * at the top of characterisation-image-extraction.ts.
 */
const terminate = vi.fn().mockResolvedValue(undefined);
const recognize = vi.fn().mockResolvedValue({
    data: {
        blocks: [
            {
                paragraphs: [
                    {
                        lines: [
                            {
                                words: [
                                    { text: 'Maria', confidence: 92, bbox: { x0: 0, y0: 0, x1: 60, y1: 20 } },
                                ],
                            },
                        ],
                    },
                ],
            },
        ],
    },
});
const createWorker = vi.fn().mockResolvedValue({ recognize, terminate });

vi.mock('tesseract.js', () => ({ createWorker: (...args: unknown[]) => createWorker(...args) }));

describe('extractTableFromImage', () => {
    let fetchSpy: ReturnType<typeof vi.fn>;
    let xhrOpenSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        vi.clearAllMocks();

        // A 1x1 fake bitmap — small enough to pass the size caps, and a
        // stand-in for createImageBitmap()/OffscreenCanvas, neither of which
        // jsdom implements.
        (globalThis as Record<string, unknown>).createImageBitmap = vi.fn().mockResolvedValue({
            width: 100,
            height: 20,
            close: vi.fn(),
        });
        (globalThis as Record<string, unknown>).OffscreenCanvas = class {
            getContext() {
                return { drawImage: vi.fn() };
            }
        };

        fetchSpy = vi.fn().mockRejectedValue(new Error('network calls are not expected in this test'));
        vi.stubGlobal('fetch', fetchSpy);
        xhrOpenSpy = vi.spyOn(XMLHttpRequest.prototype, 'open');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        xhrOpenSpy.mockRestore();
        delete (globalThis as Record<string, unknown>).createImageBitmap;
        delete (globalThis as Record<string, unknown>).OffscreenCanvas;
    });

    it('never makes a network request carrying the image — recognition stays entirely in-process', async () => {
        const { extractTableFromImage } = await import('./characterisation-image-extraction');
        const blob = new Blob(['fake-image-bytes'], { type: 'image/png' });

        const table = await extractTableFromImage(blob, { sourceKind: 'pasted_image' });

        expect(fetchSpy).not.toHaveBeenCalled();
        expect(xhrOpenSpy).not.toHaveBeenCalled();
        expect(table.rows[0].cells[0].text).toBe('Maria');
    });

    it('passes tesseract low-confidence output through unchanged, flagged with its confidence', async () => {
        recognize.mockResolvedValueOnce({
            data: {
                blocks: [
                    {
                        paragraphs: [
                            {
                                lines: [
                                    {
                                        words: [
                                            { text: 'ACN5', confidence: 38, bbox: { x0: 0, y0: 0, x1: 60, y1: 20 } },
                                        ],
                                    },
                                ],
                            },
                        ],
                    },
                ],
            },
        });

        const { extractTableFromImage } = await import('./characterisation-image-extraction');
        const blob = new Blob(['fake-image-bytes'], { type: 'image/png' });

        const table = await extractTableFromImage(blob, { sourceKind: 'pasted_image' });

        // Byte-for-byte: never "corrected" toward a known code.
        expect(table.rows[0].cells[0].text).toBe('ACN5');
        expect(table.rows[0].cells[0].confidence).toBeCloseTo(0.38);
    });

    it('terminates the tesseract worker when cancelled', async () => {
        const { extractTableFromImage } = await import('./characterisation-image-extraction');
        const blob = new Blob(['fake-image-bytes'], { type: 'image/png' });
        const controller = new AbortController();

        // recognize() never resolves — a cancel mid-flight is what this test
        // exercises, not a cancel-before-start race.
        recognize.mockReturnValueOnce(new Promise(() => {}));

        const promise = extractTableFromImage(blob, { sourceKind: 'pasted_image', signal: controller.signal });
        controller.abort();

        // AbortError, either because the signal was already aborted before
        // recognize() was reached, or because it fired while recognize()
        // (which never settles in this test) was in flight — either way the
        // worker must be terminated, which is what this test pins.
        await expect(promise).rejects.toBeInstanceOf(DOMException);
        expect(terminate).toHaveBeenCalledTimes(1);
    });

    it('refuses an image whose reported dimensions are beyond the sane cap (decompression-bomb shape)', async () => {
        (globalThis as Record<string, unknown>).createImageBitmap = vi.fn().mockResolvedValue({
            width: 20000,
            height: 20000,
            close: vi.fn(),
        });

        const { extractTableFromImage, ImageDecodeError } = await import('./characterisation-image-extraction');
        const blob = new Blob(['tiny'], { type: 'image/png' });

        await expect(extractTableFromImage(blob, { sourceKind: 'pasted_image' })).rejects.toBeInstanceOf(
            ImageDecodeError,
        );
        expect(createWorker).not.toHaveBeenCalled();
    });

    it('refuses a file that does not actually decode as an image', async () => {
        (globalThis as Record<string, unknown>).createImageBitmap = vi.fn().mockRejectedValue(new Error('bad image'));

        const { extractTableFromImage, ImageDecodeError } = await import('./characterisation-image-extraction');
        const blob = new Blob(['not-an-image'], { type: 'image/png' });

        await expect(extractTableFromImage(blob, { sourceKind: 'pasted_image' })).rejects.toBeInstanceOf(
            ImageDecodeError,
        );
        expect(createWorker).not.toHaveBeenCalled();
    });
});
