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
 * NOT IMPLEMENTED YET: OCR/image table recognition is a separate slice
 * (see the task that added pasted HTML/.docx support). Calling this today
 * always rejects — the dialog shows that as "not implemented", never as a
 * silent failure.
 */
// eslint-disable-next-line @typescript-eslint/no-unused-vars -- the parameter documents the seam's future shape even though this stub never reads it
export async function extractTableFromImage(file: File | Blob): Promise<never> {
    throw new Error('not implemented');
}
