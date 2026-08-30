<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The preflight that would have made 2026-08-30 a five-minute problem.
 *
 * It answers, as whoever runs it, the only three questions the scheduled
 * prunes actually need answered about a private directory — is it there, can
 * it be listed, can it be written — and changes nothing while doing so.
 */
class PrivateStorageStatusTest extends TestCase
{
    #[Test]
    public function it_succeeds_when_every_existing_private_directory_is_usable(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/anything.zip', 'bytes');

        $this->artisan('storage:private-status')
            ->expectsOutputToContain('data-imports')
            ->assertSuccessful();
    }

    #[Test]
    public function a_directory_that_does_not_exist_is_not_a_failure(): void
    {
        Storage::fake('local');

        // Nothing has been uploaded yet on a fresh install. That is the
        // ordinary state, not a fault — and it is also precisely why four of
        // the five prunes never failed in production.
        $this->artisan('storage:private-status')->assertSuccessful();
    }

    #[Test]
    public function it_reports_json_when_asked(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/anything.zip', 'bytes');

        $this->artisan('storage:private-status', ['--json' => true])
            ->expectsOutputToContain('"directory": "data-imports"')
            ->assertSuccessful();
    }

    #[Test]
    public function it_fails_when_a_directory_exists_but_cannot_be_read(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX modes do not apply on Windows.');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses directory permissions, so this proves nothing.');
        }

        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('data-imports/anything.zip', 'bytes');

        $root = $disk->path('data-imports');
        chmod($root, 0000);

        try {
            $this->artisan('storage:private-status')->assertFailed();
        } finally {
            chmod($root, 0755);
        }
    }
}
