<?php

namespace Tests\Concerns;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mockery;

/**
 * Makes one specific delete fail, and nothing else behave differently.
 *
 * The condition being reproduced is the production one: `Storage::delete()`
 * reports that it did not remove the file, while the file is still very much
 * there. Everything else about the disk has to stay real, because the code
 * under test then goes on to ask whether the file still exists.
 *
 * The first version of these tests created a DIRECTORY where the upload
 * should be — `unlink()` refuses a directory on every platform, so the delete
 * genuinely failed with no POSIX modes involved. It worked, and it was not
 * hermetic: on Windows the leftover directory outlived `Storage::fake()`'s
 * cleanup often enough to make later tests in the same run fail, and fail
 * DIFFERENT tests each time. A test that moves its failures around is worse
 * than no test — it teaches you to distrust the suite.
 */
trait FailsToDeleteFiles
{
    /**
     * Call AFTER `Storage::fake('local')` and after the file exists.
     */
    protected function failDeleteOf(string $path): void
    {
        $this->interceptOnDisk('delete', $path);
    }

    /**
     * The folder variant: `deleteDirectory()` reports that it did nothing, so
     * the folder is still standing when the caller checks.
     */
    protected function failDeleteOfDirectory(string $path): void
    {
        $this->interceptOnDisk('deleteDirectory', $path);
    }

    /**
     * Everything reaches the real faked disk except one method called with one
     * path, which reports failure.
     *
     * The delegation is explicit rather than Mockery's `passthru()`: on a
     * proxied partial mock `passthru()` invokes the method on the MOCK's own
     * uninitialised instance, whose Flysystem driver is null. Holding on to
     * the real adapter and calling it by hand is the version that works — and
     * it also keeps the fallthrough obvious to read.
     */
    private function interceptOnDisk(string $method, string $path): void
    {
        /** @var Filesystem $real */
        $real = Storage::disk('local');

        $disk = Mockery::mock($real);

        // Order matters: Mockery takes the first expectation whose arguments
        // match, so the one path we want to fail is declared before the
        // catch-all that hands everything else back to the real disk.
        $disk->shouldReceive($method)->with($path)->andReturnFalse();
        $disk->shouldReceive($method)->withAnyArgs()->andReturnUsing(
            fn (mixed ...$arguments): mixed => $real->{$method}(...$arguments),
        );

        Storage::set('local', $disk);
    }
}
