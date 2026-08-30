<?php

namespace Tests\Feature\Console;

use App\Models\DataImport;
use App\Models\DataImportStatus;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToListContents;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\FailsToDeleteFiles;
use Tests\TestCase;

/**
 * What the prune does when the disk does not cooperate.
 *
 * PruneDataImportsTest covers the happy path — an abandoned upload is closed
 * and its file removed. This covers the three ways production found to break
 * it on 2026-08-30, when `storage/app/private/data-imports` was created 0700
 * by php-fpm and the scheduler runs as a different user:
 *
 *   A. a delete that silently does nothing, because Flysystem's own
 *      `file_exists()` cannot tell "no such file" from "this directory will
 *      not let me look" and treats the second as success;
 *   B. a `failed` import keeping its upload for ever, because no pass ever
 *      selected it;
 *   C. a directory that exists but cannot be listed, which threw straight
 *      out of `Storage::files()` — a method Laravel does not guard with the
 *      disk's own `'throw' => false`.
 *
 * The rule these all serve: NEVER clear the pointer to a file before the file
 * is confirmed gone. A row that says "removido" about a file still on disk is
 * worse than the retained file, because it destroys the only record of whose
 * data it was.
 */
class PruneDataImportsRobustnessTest extends TestCase
{
    use FailsToDeleteFiles;
    use RefreshDatabase;

    /**
     * `organization_id` and `status` are deliberately not fillable — stamped
     * from the resolved tenant, and a scheduler test has no tenant at all.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function createImport(array $attributes): DataImport
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withoutOrganization()->create();

        $import = new DataImport;
        $import->forceFill(array_merge([
            'organization_id' => $organization->id,
            'requested_by' => $user->id,
        ], $attributes));
        $import->save();

        return $import;
    }

    // ---------------------------------------------------------------- A.

    #[Test]
    public function a_delete_that_did_not_happen_never_clears_the_pointer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/locked.zip', 'bytes');
        $this->failDeleteOf('data-imports/locked.zip');

        $import = $this->createImport([
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/locked.zip',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('data-imports:prune')->assertFailed();

        $fresh = $import->fresh();
        $this->assertSame('data-imports/locked.zip', $fresh->stored_path, 'The pointer to a file still on disk must survive.');
        $this->assertSame('validated', $fresh->status->value, 'A row whose file is still there was not abandoned-and-cleaned.');
        $this->assertNull($fresh->failure_reason, 'Nothing may claim the upload was removed while it is still on disk.');
        $this->assertTrue(Storage::disk('local')->exists('data-imports/locked.zip'));
    }

    #[Test]
    public function a_failure_on_one_import_still_lets_the_others_be_cleaned(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/locked.zip', 'bytes');
        Storage::disk('local')->put('data-imports/deletable.zip', 'bytes');
        $this->failDeleteOf('data-imports/locked.zip');
        $disk = Storage::disk('local');

        $locked = $this->createImport([
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/locked.zip',
            'expires_at' => now()->subHour(),
        ]);
        $clean = $this->createImport([
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/deletable.zip',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('data-imports:prune')->assertFailed();

        $this->assertSame('data-imports/locked.zip', $locked->fresh()->stored_path);
        $this->assertNull($clean->fresh()->stored_path);
        $this->assertSame('cancelled', $clean->fresh()->status->value);
        $disk->assertMissing('data-imports/deletable.zip');
    }

    #[Test]
    public function a_confirmed_delete_does_clear_the_pointer_and_reports_success(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/gone.zip', 'bytes');

        $import = $this->createImport([
            'status' => DataImportStatus::Validated->value,
            'stored_path' => 'data-imports/gone.zip',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('data-imports:prune')->assertSuccessful();

        $this->assertNull($import->fresh()->stored_path);
        $this->assertSame('cancelled', $import->fresh()->status->value);
        Storage::disk('local')->assertMissing('data-imports/gone.zip');
    }

    // ---------------------------------------------------------------- B.

    #[Test]
    public function an_expired_failed_import_does_not_keep_its_upload_for_ever(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/failed.zip', 'bytes');

        $import = $this->createImport([
            'status' => DataImportStatus::Failed->value,
            'stored_path' => 'data-imports/failed.zip',
            'failure_reason' => 'A importação falhou. Tente novamente ou contacte o suporte.',
            'expires_at' => now()->subHour(),
        ]);

        $this->artisan('data-imports:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('data-imports/failed.zip');
        $fresh = $import->fresh();
        $this->assertNull($fresh->stored_path);
        $this->assertSame('failed', $fresh->status->value, 'The sweep removes the file; it does not rewrite terminal history.');
        $this->assertSame('A importação falhou. Tente novamente ou contacte o suporte.', $fresh->failure_reason);
    }

    #[Test]
    public function a_failed_import_still_inside_its_window_is_left_alone(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-imports/recent-failure.zip', 'bytes');

        $import = $this->createImport([
            'status' => DataImportStatus::Failed->value,
            'stored_path' => 'data-imports/recent-failure.zip',
            'expires_at' => now()->addHour(),
        ]);

        $this->artisan('data-imports:prune')->assertSuccessful();

        Storage::disk('local')->assertExists('data-imports/recent-failure.zip');
        $this->assertSame('data-imports/recent-failure.zip', $import->fresh()->stored_path);
    }

    #[Test]
    public function no_terminal_state_keeps_a_file_past_its_window(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $leftovers = [];

        foreach ([DataImportStatus::Imported, DataImportStatus::Cancelled, DataImportStatus::Failed] as $status) {
            $path = "data-imports/{$status->value}-leftover.zip";
            $disk->put($path, 'bytes');

            $leftovers[$status->value] = $this->createImport([
                'status' => $status->value,
                'stored_path' => $path,
                'expires_at' => now()->subHour(),
            ]);
        }

        $this->artisan('data-imports:prune')->assertSuccessful();

        foreach ($leftovers as $statusValue => $import) {
            $disk->assertMissing("data-imports/{$statusValue}-leftover.zip");
            $this->assertNull($import->fresh()->stored_path, "A {$statusValue} import kept a file it has no reason to hold.");
            $this->assertSame($statusValue, $import->fresh()->status->value);
        }
    }

    // ---------------------------------------------------------------- C.

    #[Test]
    public function a_directory_that_cannot_be_listed_is_reported_not_thrown(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('files')->andThrow(UnableToListContents::atLocation(
            'data-imports',
            false,
            new RuntimeException('DirectoryIterator::__construct(): Failed to open directory: Permission denied'),
        ));

        Storage::set('local', $disk);

        // Reaching this assertion at all is half the point: today the
        // exception escapes the command and the scheduler is left with a
        // stack trace instead of a reported failure.
        $this->artisan('data-imports:prune')->assertFailed();
    }

    #[Test]
    public function a_directory_the_process_may_not_read_is_reported_on_posix(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX modes do not apply on Windows.');
        }

        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root bypasses directory permissions, so this proves nothing.');
        }

        Storage::fake('local');
        $disk = Storage::disk('local');
        $disk->put('data-imports/unreadable.zip', 'bytes');

        $root = $disk->path('data-imports');
        chmod($root, 0000);

        try {
            $this->artisan('data-imports:prune')->assertFailed();
        } finally {
            chmod($root, 0755);
        }
    }
}
