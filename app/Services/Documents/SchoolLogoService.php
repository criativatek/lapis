<?php

namespace App\Services\Documents;

use App\Models\OrganizationIdentity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The one place a school logo is written or deleted.
 *
 * Same shape as StudentPhotoService, on purpose: the private `local` disk, an
 * unguessable name the SERVER chooses, and a controller that authorizes before
 * streaming it. A logo is not personal data, but it is tenant data, and a file
 * whose URL is its filename is a file anybody can enumerate.
 *
 * THE CLIENT'S FILENAME IS NEVER USED. Not for the path, not for the
 * extension: `extension()` reads the file's own content, so «logo.png» that is
 * really a PHP script lands as whatever it actually is and is refused by the
 * validation before it ever reaches here.
 *
 * REPLACING DELETES THE OLD FILE, and only ever the path this identity row
 * already held — never a path that arrived in a request.
 */
class SchoolLogoService
{
    public const DISK = 'local';

    public const DIRECTORY = 'school-logos';

    /** 2 MB. A letterhead logo that needs more than this is the wrong file. */
    public const MAX_KILOBYTES = 2048;

    /** The formats a document renderer can be relied on to place. */
    public const ALLOWED_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp'];

    public const ALLOWED_MIMES = ['image/png', 'image/jpeg', 'image/webp'];

    /**
     * Stores a new logo and drops whatever was there before.
     */
    public function replace(OrganizationIdentity $identity, UploadedFile $file): string
    {
        $previous = $identity->logo_path;

        $path = $this->newPath($file->extension() ?: 'png');

        Storage::disk(self::DISK)->putFileAs(self::DIRECTORY, $file, basename($path));

        $identity->forceFill(['logo_path' => $path])->save();

        // Only after the new one is safely stored and pointed at: a failure
        // above must never leave the school with no logo and no file.
        if ($previous !== null && $previous !== $path) {
            $this->deleteFile($previous);
        }

        return $path;
    }

    /**
     * Drops the logo and nothing else — every other field of the identity
     * stays exactly as it was.
     */
    public function remove(OrganizationIdentity $identity): void
    {
        $path = $identity->logo_path;

        if ($path === null) {
            return;
        }

        $identity->forceFill(['logo_path' => null])->save();

        $this->deleteFile($path);
    }

    /**
     * A name the server chooses, under the one directory this service owns.
     */
    protected function newPath(string $extension): string
    {
        $safe = in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true)
            ? strtolower($extension)
            : 'png';

        return self::DIRECTORY.'/'.Str::uuid()->toString().'.'.$safe;
    }

    /**
     * A file that is already gone is not an error worth failing a request over
     * — the row no longer points at it either way, which is what matters.
     */
    protected function deleteFile(string $path): void
    {
        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (Throwable $exception) {
            Log::warning('Não foi possível apagar o logótipo anterior.', [
                'path' => $path,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
