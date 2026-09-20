/**
 * What to do with a clipboard paste, decided ONCE and in the right order.
 *
 * This is the fix for the bug this whole slice exists to close (§8): Word,
 * Excel and Google Sheets all put a real `<table>` on the clipboard as
 * `text/html`, ALONGSIDE a flattened `text/plain` copy that has already lost
 * every merged cell and multi-row header. Reading `text/plain` first — which
 * is what the dialog did before this file existed — silently produces a
 * table with the right cell text but none of the structure, and a paste that
 * "worked" but produced 0 recognisable rows.
 *
 * Kept as a pure function, separate from the Vue component, so the priority
 * order itself — the one thing worth pinning down with a test — can be
 * tested against a plain object without mounting a component or faking a
 * real ClipboardEvent.
 */

export type PastePayload =
    | { kind: 'html'; html: string }
    | { kind: 'text'; text: string }
    | { kind: 'image'; file: File | Blob }
    | { kind: 'none' };

export interface PasteClipboardData {
    types: readonly string[];
    getData(format: string): string;
    files?: FileList | readonly File[];
}

/**
 * Order, and only this order:
 *   1. `text/html` — but ONLY when it actually contains a `<table`. Word and
 *      Excel put styling-only HTML on the clipboard for a lot of pastes that
 *      are not tables at all, and treating any HTML as a table would send
 *      prose through a parser built to read one.
 *   2. Tabular plain text — `text/plain` containing a literal tab character,
 *      the shape a spreadsheet's flattened copy takes.
 *   3. An image on the clipboard (`image/*`), routed to the seam this task
 *      leaves for the OCR slice.
 *   4. Whatever `text/plain` is left, even if it is not obviously tabular —
 *      the existing plain-paste path already handles that gracefully.
 */
export function resolvePastePayload(clipboardData: PasteClipboardData): PastePayload {
    const types = clipboardData.types ?? [];

    if (types.includes('text/html')) {
        const html = clipboardData.getData('text/html');

        if (html.toLowerCase().includes('<table')) {
            return { kind: 'html', html };
        }
    }

    if (types.includes('text/plain')) {
        const text = clipboardData.getData('text/plain');

        if (text.includes('\t')) {
            return { kind: 'text', text };
        }
    }

    // A pasted screenshot is found by looking at the FILES, not at `types`.
    //
    // This is not a detail. When a browser puts an image on the clipboard —
    // Ctrl+V of a screenshot, the gesture §14 exists for — `types` reads
    // `["Files"]` and NOT `["image/png"]`: the concrete media type lives on
    // the file, not in the clipboard's list of flavours. Asking `types` for
    // something starting with `image/` therefore found nothing, every time,
    // and the entire OCR path was unreachable by the one gesture it was
    // built for.
    //
    // A unit test can miss this indefinitely, because a test writes the
    // clipboard object by hand and will happily write the shape the code
    // expects instead of the shape a browser produces. Measured in a real
    // browser: `types: ["Files"]`, `files[0].type: "image/png"`.
    const files = Array.from(clipboardData.files ?? []);
    const imageFile = files.find((file) => file.type.startsWith('image/'));

    if (imageFile) {
        return { kind: 'image', file: imageFile };
    }

    if (types.includes('text/plain')) {
        return { kind: 'text', text: clipboardData.getData('text/plain') };
    }

    return { kind: 'none' };
}
