<?php

namespace App\Services;

use App\Models\StudentIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a student photo is written or deleted.
 *
 * Photos are personal data: they live on the private `local` disk under an
 * unguessable uuid name, never under public/, and reach the browser only
 * through StudentPhotoController, which authorizes first (§22.2).
 *
 * The path is stored on StudentIdentity because that is where the schema puts
 * it — so a student with no identity row has nowhere to keep a photo. Batch
 * import writes the file before the identity exists, which is why putBytes()
 * (file only) and attachTo() (file + identity) are separate steps.
 */
class StudentPhotoService
{
    public const DISK = 'local';

    public const DIRECTORY = 'student-photos';

    /**
     * A photo the teacher picked by hand, for one known student. Replaces the
     * previous one if there is one.
     */
    public function storeUploaded(StudentIdentity $identity, UploadedFile $file): string
    {
        // extension(), not getClientOriginalExtension(): the guessed extension
        // comes from the file's own content, not from what the client claimed.
        $path = $this->newPath($file->extension() ?: 'jpg');

        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, basename($path));

        return $this->attachTo($identity, $path);
    }

    /**
     * A photo extracted from a batch import, already in memory, for a student
     * whose identity already exists.
     */
    public function storeBytes(StudentIdentity $identity, string $imageBytes, string $extension): string
    {
        return $this->attachTo($identity, $this->putBytes($imageBytes, $extension));
    }

    /**
     * Writes the file alone and returns its path, for the import that creates
     * the student and their identity in one go afterwards.
     */
    public function putBytes(string $imageBytes, string $extension): string
    {
        $path = $this->newPath($extension);

        Storage::disk(self::DISK)->put($path, $imageBytes);

        return $path;
    }

    /**
     * Drops the photo and nothing else — the student, the identity, the name
     * and every pedagogical record stay exactly as they were.
     */
    public function remove(StudentIdentity $identity): void
    {
        $previousPath = $identity->photo_path;

        if ($previousPath === null) {
            return;
        }

        // Clear the reference first: a row pointing at a file that is gone is
        // worse than a file nobody points at. If the physical delete then
        // fails, the database stays correct and we log the leftover rather
        // than pointing the student back at a photo they asked to remove.
        $identity->update(['photo_path' => null]);

        $this->deleteFile($previousPath);
    }

    /**
     * Points the identity at an already-written file and only then removes the
     * one it pointed at before.
     *
     * The compensation matters: if persisting the new reference throws, the
     * file we just wrote would be orphaned and the student would still be on
     * the old photo. So the new file is deleted and the exception re-thrown —
     * the previous photo and its row are left exactly as they were.
     */
    protected function attachTo(StudentIdentity $identity, string $path): string
    {
        $previousPath = $identity->photo_path;

        try {
            $identity->update(['photo_path' => $path]);
        } catch (Throwable $exception) {
            Storage::disk(self::DISK)->delete($path);

            throw $exception;
        }

        if ($previousPath !== null && $previousPath !== $path) {
            $this->deleteFile($previousPath);
        }

        return $path;
    }

    /**
     * A failed cleanup is a leftover file, not a broken record — worth knowing
     * about, never worth undoing the database change for.
     */
    protected function deleteFile(string $path): void
    {
        if (! Storage::disk(self::DISK)->delete($path)) {
            Log::warning('Student photo file could not be deleted.', ['path' => $path]);
        }
    }

    protected function newPath(string $extension): string
    {
        return self::DIRECTORY.'/'.Str::uuid().'.'.$extension;
    }
}
