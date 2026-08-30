<?php

namespace Tests\Feature\Console;

use App\Models\CorrectionImport;
use App\Models\CorrectionImportStatus;
use App\Models\DataExport;
use App\Models\Organization;
use App\Models\SchoolClass;
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
 * The other four prunes had the same exposure and were only spared by luck.
 *
 * `data-imports:prune` is the one that broke in production, but nothing about
 * the failure was specific to it: every prune listed a private directory
 * through `Storage::files()`/`directories()` — which Laravel does not guard
 * with the disk's `'throw' => false` — and every prune that owned a database
 * pointer dropped it without checking whether the file had actually gone.
 * They survived only because their directories either predate the last
 * group-permission fix on the server or do not exist there at all.
 *
 * `data-exports:prune` was in fact the worst of them: it opened with a bulk
 * `update(['disk_path' => null])` over every expired row and only then went
 * looking for folders, so the pointer was dropped whether or not the ZIP ever
 * went.
 *
 * The two folder-based prunes (roster, INOVAR) have no database pointer at
 * all, so the pointer rule cannot apply to them — only the listing rule does.
 * That difference is deliberate and is not flattened away here.
 */
class PruneSiblingsRobustnessTest extends TestCase
{
    use FailsToDeleteFiles;
    use RefreshDatabase;

    /**
     * A disk that exists but refuses to be listed — the production condition,
     * reproducible on Windows where POSIX modes are not available.
     */
    private function unlistableDisk(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('files')->andThrow($this->refusal());
        $disk->shouldReceive('directories')->andThrow($this->refusal());

        Storage::set('local', $disk);
    }

    private function refusal(): UnableToListContents
    {
        return UnableToListContents::atLocation('', false, new RuntimeException(
            'DirectoryIterator::__construct(): Failed to open directory: Permission denied',
        ));
    }

    // ------------------------------------------------- data-exports

    private function createExport(string $diskPath, ?string $expiresAt): DataExport
    {
        $organization = Organization::factory()->create();
        $user = User::factory()->withoutOrganization()->create();

        $export = new DataExport;
        $export->forceFill([
            'organization_id' => $organization->id,
            'requested_by' => $user->id,
            'status' => 'ready',
            'disk_path' => $diskPath,
            'expires_at' => $expiresAt,
        ]);
        $export->save();

        return $export;
    }

    #[Test]
    public function an_export_whose_folder_survives_keeps_its_disk_path(): void
    {
        Storage::fake('local');

        // A plain file standing where the token folder should be:
        // deleteDirectory() will not touch it, so the removal genuinely fails.
        Storage::disk('local')->put('data-exports/stuck', 'not a folder');

        $export = $this->createExport('data-exports/stuck/export.zip', now()->subHour()->toDateTimeString());

        $this->artisan('data-exports:prune')->assertFailed();

        $this->assertSame(
            'data-exports/stuck/export.zip',
            $export->fresh()->disk_path,
            'The pointer must outlive a removal that did not happen.',
        );
    }

    #[Test]
    public function an_export_whose_folder_really_went_loses_its_disk_path(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-exports/gone/export.zip', 'bytes');

        $export = $this->createExport('data-exports/gone/export.zip', now()->subHour()->toDateTimeString());

        $this->artisan('data-exports:prune')->assertSuccessful();

        $this->assertNull($export->fresh()->disk_path);
        Storage::disk('local')->assertMissing('data-exports/gone/export.zip');
    }

    #[Test]
    public function an_export_still_inside_its_window_is_untouched(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('data-exports/fresh/export.zip', 'bytes');

        $export = $this->createExport('data-exports/fresh/export.zip', now()->addHour()->toDateTimeString());

        $this->artisan('data-exports:prune')->assertSuccessful();

        $this->assertSame('data-exports/fresh/export.zip', $export->fresh()->disk_path);
        Storage::disk('local')->assertExists('data-exports/fresh/export.zip');
    }

    // ------------------------------------------------- correction-imports

    #[Test]
    public function a_correction_import_whose_file_will_not_delete_keeps_its_pointer(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('correction-imports/locked.csv', 'bytes');
        $this->failDeleteOf('correction-imports/locked.csv');

        $organization = Organization::factory()->create();
        $user = User::factory()->withoutOrganization()->create();
        $class = SchoolClass::factory()->recycle($organization)->create();

        $import = new CorrectionImport;
        $import->forceFill([
            'organization_id' => $organization->id,
            'class_id' => $class->id,
            'source' => 'plickers',
            'status' => CorrectionImportStatus::Parsed->value,
            'stored_path' => 'correction-imports/locked.csv',
            'uploaded_by' => $user->id,
            'updated_at' => now()->subDays(3),
        ]);
        $import->save();

        $this->artisan('correction-imports:prune')->assertFailed();

        $fresh = $import->fresh();
        $this->assertSame('correction-imports/locked.csv', $fresh->stored_path);
        $this->assertSame('parsed', $fresh->status->value, 'Nothing may be recorded as cleaned up while its file is still there.');
    }

    // ------------------------------------------------- listing, all four

    #[Test]
    public function every_prune_reports_a_directory_it_cannot_list(): void
    {
        foreach (['correction-imports:prune', 'data-exports:prune', 'roster-imports:prune', 'inovar-exports:prune'] as $command) {
            $this->unlistableDisk();

            // Reaching the assertion at all is the point: before this slice
            // the exception escaped the command entirely.
            $this->artisan($command)->assertFailed();
        }
    }
}
