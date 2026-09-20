import { describe, expect, it } from 'vitest';
import { resolvePastePayload } from './characterisation-paste-priority';

/**
 * The regression this whole slice exists to close (§8): a Word/Excel/Google
 * Sheets paste puts a real `<table>` on the clipboard as `text/html`
 * alongside a flattened `text/plain` copy. Reading the plain text first
 * loses every merge and produces 0 recognised rows even though the HTML
 * table was sitting right there.
 */
describe('resolvePastePayload', () => {
    it('prefers text/html with a table over text/plain when both are present', () => {
        const payload = resolvePastePayload({
            types: ['text/html', 'text/plain'],
            getData: (format: string) => {
                if (format === 'text/html') {
                    return '<table><tr><td>Aluno</td></tr><tr><td>Ana Silva</td></tr></table>';
                }

                if (format === 'text/plain') {
                    return 'Aluno\nAna Silva';
                }

                return '';
            },
        });

        expect(payload).toEqual({
            kind: 'html',
            html: '<table><tr><td>Aluno</td></tr><tr><td>Ana Silva</td></tr></table>',
        });
    });

    it('falls back to tabular text/plain when text/html has no table', () => {
        const payload = resolvePastePayload({
            types: ['text/html', 'text/plain'],
            getData: (format: string) => {
                if (format === 'text/html') {
                    return '<b>Ana Silva</b>';
                }

                if (format === 'text/plain') {
                    return 'Aluno\tObservações\nAna Silva\tTexto.';
                }

                return '';
            },
        });

        expect(payload).toEqual({ kind: 'text', text: 'Aluno\tObservações\nAna Silva\tTexto.' });
    });

    // The shape a BROWSER actually produces for Ctrl+V of a screenshot, measured
    // in Chromium: `types` is `["Files"]` — never `["image/png"]` — and the media
    // type lives on the file. The earlier version of this test asserted
    // `types: ['image/png']`, a shape no browser emits, so it passed while the
    // only gesture the OCR path exists for could never reach it.
    it('routes a pasted screenshot to the image seam, which announces itself as "Files"', () => {
        const file = new File(['fake'], 'tabela.png', { type: 'image/png' });

        const payload = resolvePastePayload({
            types: ['Files'],
            getData: () => '',
            files: [file],
        });

        expect(payload).toEqual({ kind: 'image', file });
    });

    it('still routes an image when the clipboard does announce a concrete image type', () => {
        const file = new File(['fake'], 'tabela.png', { type: 'image/png' });

        const payload = resolvePastePayload({
            types: ['image/png'],
            getData: () => '',
            files: [file],
        });

        expect(payload).toEqual({ kind: 'image', file });
    });

    // Priority still holds: a spreadsheet paste carries BOTH a table and, on some
    // platforms, an image of it. The table must win — an image of a table read by
    // OCR is strictly worse than the table itself.
    it('prefers an HTML table over an image that arrives alongside it', () => {
        const file = new File(['fake'], 'tabela.png', { type: 'image/png' });

        const payload = resolvePastePayload({
            types: ['text/html', 'Files'],
            getData: (format: string) =>
                format === 'text/html' ? '<table><tr><td>Ana Silva</td></tr></table>' : '',
            files: [file],
        });

        expect(payload).toEqual({ kind: 'html', html: '<table><tr><td>Ana Silva</td></tr></table>' });
    });

    it('falls back to plain text/plain when nothing else is present', () => {
        const payload = resolvePastePayload({
            types: ['text/plain'],
            getData: (format: string) => (format === 'text/plain' ? 'Um parágrafo qualquer.' : ''),
        });

        expect(payload).toEqual({ kind: 'text', text: 'Um parágrafo qualquer.' });
    });

    it('reports none when the clipboard carries nothing usable', () => {
        const payload = resolvePastePayload({ types: [], getData: () => '' });

        expect(payload).toEqual({ kind: 'none' });
    });
});
