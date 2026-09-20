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

    it('routes an image clipboard paste to the image seam', () => {
        const file = new File(['fake'], 'tabela.png', { type: 'image/png' });

        const payload = resolvePastePayload({
            types: ['image/png'],
            getData: () => '',
            files: [file],
        });

        expect(payload).toEqual({ kind: 'image', file });
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
