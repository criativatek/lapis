/**
 * Client-side prep for a student photo upload — the same 5 MB ceiling the
 * backend enforces (StudentPhotoController, `max:5120`), checked before the
 * request leaves the browser instead of after a round trip.
 *
 * Most photos taken on a phone are far larger than a small avatar needs, so
 * this also tries to shrink the image before upload: fewer 413s, less
 * storage, faster uploads. Compression is best-effort — any failure (an
 * unsupported browser, a decode error) falls back to validating the original
 * file as-is rather than blocking the upload.
 */

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;
const MAX_DIMENSION = 1200;
const JPEG_QUALITY = 0.85;
const ALLOWED_TYPES = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];

export type PreparedPhoto = { ok: true; file: File } | { ok: false; message: string };

export async function preparePhotoForUpload(file: File): Promise<PreparedPhoto> {
    if (!ALLOWED_TYPES.includes(file.type)) {
        return { ok: false, message: 'Formatos aceites: JPG, PNG ou WEBP.' };
    }

    const candidate = (await tryCompress(file)) ?? file;

    if (candidate.size > MAX_UPLOAD_BYTES) {
        const limitMb = Math.round(MAX_UPLOAD_BYTES / (1024 * 1024));

        return {
            ok: false,
            message: `A imagem é demasiado grande. Escolha uma fotografia até ${limitMb} MB.`,
        };
    }

    return { ok: true, file: candidate };
}

/**
 * `imageOrientation: 'from-image'` makes createImageBitmap apply the file's
 * own EXIF rotation before it ever reaches the canvas — without it, a photo
 * taken in portrait on a phone would be redrawn sideways. Browsers that don't
 * recognise the option ignore it rather than throw, so this stays safe on
 * older engines; it just means orientation isn't corrected there.
 */
async function tryCompress(file: File): Promise<File | null> {
    if (typeof createImageBitmap !== 'function') {
        return null;
    }

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_DIMENSION / Math.max(bitmap.width, bitmap.height));
        const width = Math.max(1, Math.round(bitmap.width * scale));
        const height = Math.max(1, Math.round(bitmap.height * scale));

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (context === null) {
            bitmap.close();

            return null;
        }

        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close();

        const blob = await new Promise<Blob | null>((resolve) =>
            canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY),
        );

        // A small PNG icon re-encoded as JPEG can end up larger — only keep
        // the compressed version when it actually helped.
        if (blob === null || blob.size >= file.size) {
            return null;
        }

        const baseName = file.name.replace(/\.[^./\\]+$/, '');

        return new File([blob], `${baseName}.jpg`, { type: 'image/jpeg' });
    } catch {
        return null;
    }
}
